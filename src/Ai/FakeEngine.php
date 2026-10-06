<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Enums\Finish;

/**
 * A deterministic engine for development and browser tests that never calls a provider. Markers in the source text
 * exercise the failure paths: `[[invalid]]` strips the placeholders from the translation, `[[refuse]]` makes the call
 * end as refused, and `[[review]]` makes the review correct the segment.
 */
final class FakeEngine implements Engine
{
    public const string INVALID = '[[invalid]]';

    public const string REFUSE = '[[refuse]]';

    public const string REVIEW = '[[review]]';

    public const string REVIEW_REASON = 'The source contains the [[review]] marker.';

    public static function enabled(): bool
    {
        return filter_var(config('tolk.fake_engine'), FILTER_VALIDATE_BOOL) && app()->environment('local', 'testing');
    }

    public function translate(EngineCall $call): EngineResult
    {
        self::delay();

        $texts = $this->texts($call, 'text');

        foreach ($texts as $text) {
            if (str_contains($text, self::REFUSE)) {
                return $this->result($call, [], Finish::Refused);
            }
        }

        return $this->result($call, array_map(fn (string $text) => $this->translation($call, $text), $texts));
    }

    public function review(EngineCall $call): EngineResult
    {
        self::delay();

        $marked = array_filter($this->texts($call, 'source'), fn (string $source) => str_contains($source, self::REVIEW));

        return $this->result($call, array_map(fn (string $source) => [
            'text' => '['.$call->target->lang.', reviewed] '.$source,
            'reason' => self::REVIEW_REASON,
        ], $marked));
    }

    private function translation(EngineCall $call, string $text): string
    {
        if (str_contains($text, self::INVALID)) {
            $text = Placeholders::strip($text);
        }

        return '['.$call->target->lang.'] '.$text;
    }

    /**
     * The segments' texts by id. As in TranslationEngine, the first of repeated ids wins and segments without a string
     * id or text get no item.
     *
     * @return array<string, string>
     */
    private function texts(EngineCall $call, string $key): array
    {
        $texts = [];

        foreach ($call->segments as $segment) {
            $id = $segment['id'] ?? null;
            $text = $segment[$key] ?? null;

            if (is_string($id) && is_string($text) && ! isset($texts[$id])) {
                $texts[$id] = $text;
            }
        }

        return $texts;
    }

    public static function delay(): void
    {
        $milliseconds = (int) config('tolk.fake_delay_ms', 300);

        if ($milliseconds > 0) {
            Sleep::for($milliseconds)->milliseconds();
        }
    }

    private function result(EngineCall $call, array $items, Finish $finish = Finish::Stop): EngineResult
    {
        return new EngineResult(items: $items, finish: $finish, usage: new Usage(calls: 1), model: $call->model);
    }
}
