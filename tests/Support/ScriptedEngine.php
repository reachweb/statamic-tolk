<?php

namespace Reach\StatamicTolk\Tests\Support;

use Closure;
use LogicException;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\EngineResult;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Enums\Finish;

/**
 * An engine whose answer to each call comes from a closure, for the finish reasons and failures that faked agents
 * cannot produce. Bind it with `app()->instance(Engine::class, $engine)` before resolving the pipeline.
 */
final class ScriptedEngine implements Engine
{
    /** @var list<EngineCall> */
    public array $calls = [];

    /**
     * @param  Closure(EngineCall, int): EngineResult  $script  receives the call and its number, starting at 1
     */
    public function __construct(private readonly Closure $script) {}

    public static function result(EngineCall $call, Finish $finish, array $items = []): EngineResult
    {
        return new EngineResult($items, $finish, new Usage(input: 10, output: 5, calls: 1), $call->model);
    }

    /**
     * Translates every segment of the call with `[T] ` + text.
     */
    public static function translated(EngineCall $call): EngineResult
    {
        return self::result($call, Finish::Stop, array_column(array_map(
            fn (array $segment) => ['id' => $segment['id'], 'text' => '[T] '.$segment['text']],
            $call->segments,
        ), 'text', 'id'));
    }

    public function translate(EngineCall $call): EngineResult
    {
        $this->calls[] = $call;

        return ($this->script)($call, count($this->calls));
    }

    public function review(EngineCall $call): EngineResult
    {
        throw new LogicException('The scripted engine does not review.');
    }

    /**
     * @return list<list<string>> the segment ids of each call
     */
    public function segmentIds(): array
    {
        return array_map(fn (EngineCall $call) => array_column($call->segments, 'id'), $this->calls);
    }
}
