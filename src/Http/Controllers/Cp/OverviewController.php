<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\FailsWithJson;
use Reach\StatamicTolk\Http\Requests\OverviewScanRequest;
use Reach\StatamicTolk\Http\RunState;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\State\OverviewScanner;
use Reach\StatamicTolk\State\StatusScanner;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * The Overview (§A13): what is translated into which site, per container, with the buttons that create runs, and the
 * month's usage, the providers and the latest runs. The page loads the cells itself through `scan`.
 */
class OverviewController extends Controller
{
    use FailsWithJson;

    public const int RECENT_RUNS = 5;

    public function index(StatusScanner $scanner, Providers $providers, UsageLog $usage, ModelCatalog $catalog, RunRepository $runs, RunState $state): Response
    {
        $sites = Site::authorized()->map(fn ($site) => ['handle' => $site->handle(), 'name' => $site->name()])->values()->all();
        $models = $usage->month();

        return Inertia::render('tolk::Overview', [
            'multisite' => Site::multiEnabled() && Site::hasMultiple(),
            'sites' => $sites,
            'rows' => $this->rows($scanner, array_column($sites, 'handle')),
            'providers' => array_map($providers->status(...), ModelRef::PROVIDERS),
            'fakeEngine' => FakeEngine::enabled(),
            'usage' => ['month' => now()->format('Y-m'), 'models' => $models, 'labels' => $catalog->labels(array_keys($models))],
            'runs' => array_map($state->summary(...), $runs->recent(self::RECENT_RUNS)),
            'urls' => [
                'scan' => cp_route('tolk.overview.scan'),
                'store' => cp_route('tolk.runs.store'),
                'runs' => cp_route('tolk.runs.index'),
                'audit' => null,
            ],
        ]);
    }

    /**
     * The cell's totals so far, with its cursor, null once finished. A finished cell is answered from the cache unless
     * the request asks for `fresh` counts.
     */
    public function scan(OverviewScanRequest $request, OverviewScanner $overview): JsonResponse
    {
        $type = $request->type();
        [$container, $site] = [$request->validated('container'), $request->validated('site')];

        try {
            $cached = $request->cursor() === null && ! $request->fresh() ? $overview->cached($type, $container, $site) : null;
            $totals = $cached ?? $overview->scan($type, $container, $site, $request->cursor());
        } catch (UnknownSite|InvalidArgumentException $exception) {
            $this->fail(422, 'invalid_scan', $exception->getMessage());
        }

        return response()->json([...$totals->toArray(), 'cached' => $cached !== null]);
    }

    /**
     * The containers StatusScanner covers, by content type, each with the sites it has targets in that the user may
     * access. A container without targets is listed with no sites.
     *
     * @param  list<string>  $sites
     * @return list<array{type: string, handle: string, title: string, sites: list<string>}>
     */
    private function rows(StatusScanner $scanner, array $sites): array
    {
        $rows = [];

        foreach (OverviewScanRequest::TYPES as $type) {
            foreach ($scanner->containers($type) as $handle) {
                $rows[] = [
                    'type' => $type->value,
                    'handle' => $handle,
                    'title' => $this->title($type, $handle),
                    'sites' => array_values(array_intersect($scanner->sites($type, $handle), $sites)),
                ];
            }
        }

        return $rows;
    }

    private function title(ContentType $type, string $handle): string
    {
        $container = match ($type) {
            ContentType::Entry => Collection::find($handle),
            ContentType::Term => Taxonomy::find($handle),
            ContentType::GlobalSet => GlobalSet::findByHandle($handle),
            default => Nav::findByHandle($handle),
        };

        return (string) ($container?->title() ?: $handle);
    }
}
