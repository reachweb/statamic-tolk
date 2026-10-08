<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\FailsWithJson;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\RaisesTimeLimit;
use Reach\StatamicTolk\Http\Requests\RunEstimateRequest;
use Reach\StatamicTolk\Http\Requests\RunFollowRequest;
use Reach\StatamicTolk\Http\Requests\RunStateRequest;
use Reach\StatamicTolk\Http\Requests\RunStoreRequest;
use Reach\StatamicTolk\Http\Requests\RunTickRequest;
use Reach\StatamicTolk\Http\RunState;
use Reach\StatamicTolk\Runs\ApplyReport;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Runs\WorkReport;
use Statamic\Facades\User;

/**
 * Runs over HTTP (§A12, §A13): the run pages, creating a run, its estimate, and the browser driver's `tick` and `apply`.
 * Creating a run starts nothing (D19); the page starts it.
 */
class RunsController extends Controller
{
    use FailsWithJson;
    use RaisesTimeLimit;

    public const int RECENT = 50;

    public const int ESTIMATE_BUDGET_MS = 1500;

    public const int APPLY_SECONDS = 10;

    public function __construct(
        private readonly RunRepository $runs,
        private readonly RunState $state,
    ) {}

    public function index(): Response
    {
        return Inertia::render('tolk::Runs/Index', [
            'runs' => array_map($this->state->summary(...), $this->runs->recent(self::RECENT)),
        ]);
    }

    public function show(RunStateRequest $request, string $run): Response
    {
        $stored = $this->runs->find($run) ?? abort(404);

        return Inertia::render('tolk::Runs/Show', [
            'state' => $this->stateOf($stored, $request),
            'urls' => $this->state->urls($stored),
            'models' => $this->state->models($stored),
            'lanes' => RunDispatcher::browserLanes(),
        ]);
    }

    public function store(RunStoreRequest $request, RunPlanner $planner): JsonResponse
    {
        $driver = RunDispatcher::resolveDriver();

        try {
            $planned = $planner->build(new RunRequest(
                sites: $request->validated('sites'),
                driver: $driver,
                sourceSite: $request->validated('source_site'),
                collections: $request->validated('collections', []),
                taxonomies: $request->validated('taxonomies', []),
                globals: $request->validated('globals', []),
                navs: $request->validated('navs', []),
                entryIds: $request->validated('entry_ids', []),
                termIds: $request->validated('term_ids', []),
                options: $request->validated('options', []),
                user: User::current(),
            ));
        } catch (UnknownSite|TargetNotFound|InvalidArgumentException|InvalidModelRef|MissingDefaultModel|NoProviderConfigured $exception) {
            $this->fail(422, 'invalid_run', $exception->getMessage());
        }

        $run = $this->runs->create($planned->run, $planned->items);

        return response()->json(['run' => $run->id, 'url' => cp_route('tolk.runs.show', $run->id)], 201);
    }

    public function state(RunFollowRequest $request): JsonResponse
    {
        return response()->json($this->stateOf($this->followed($request), $request));
    }

    /**
     * Estimates the next items within about ESTIMATE_BUDGET_MS. `from` 0 starts over; any other index than where the
     * last call stopped returns the stored progress.
     */
    public function estimate(RunEstimateRequest $request, string $run, RunPlanner $planner): JsonResponse
    {
        $progress = $planner->estimate($this->find($run), (int) $request->validated('from'), self::ESTIMATE_BUDGET_MS);

        return response()->json($progress->toArray());
    }

    public function start(RunStateRequest $request, string $run, RunDispatcher $dispatcher): JsonResponse
    {
        $stored = $this->find($run);
        $dispatcher->start($stored);

        return response()->json($this->stateOf($stored, $request));
    }

    /**
     * Translates for one browser lane until it ran one unit, so the request makes at most one provider call (D24), or
     * until `runs.tick_seconds` have passed. Other drivers' runs are not ticked, so the page's lanes end and it follows
     * the run by polling.
     */
    public function tick(RunTickRequest $request, RunProcessor $processor): JsonResponse
    {
        $run = $this->followed($request);
        $report = new WorkReport;

        if ($run->driver === RunDriver::Browser) {
            $seconds = max(1, (int) config('tolk.runs.tick_seconds'));
            $timeout = (int) config('tolk.http.timeout.web', 45);
            // A unit starts only before the deadline, so the request ends at most one provider timeout after it.
            $this->raiseTimeLimit($seconds + $timeout + 15);
            $report = $processor->translate($run, $request->workerId(), CarbonImmutable::now()->addSeconds($seconds), $seconds + $timeout + 30, maxUnits: 1);
        }

        return response()->json([...$this->stateOf($run, $request), 'report' => $report->toArray()]);
    }

    /**
     * Saves translated items for about APPLY_SECONDS, waiting for the global write lock only until then.
     */
    public function apply(RunFollowRequest $request, RunProcessor $processor): JsonResponse
    {
        $run = $this->followed($request);
        $report = new ApplyReport;

        if ($run->driver === RunDriver::Browser) {
            $this->raiseTimeLimit(self::APPLY_SECONDS + 50);
            $report = $processor->applyReady($run, 'browser-'.User::current()->id().'-apply', CarbonImmutable::now()->addSeconds(self::APPLY_SECONDS));
        }

        return response()->json([...$this->stateOf($run, $request), 'report' => $report->toArray()]);
    }

    public function cancel(RunFollowRequest $request, RunProcessor $processor): JsonResponse
    {
        $run = $this->followed($request);
        $processor->cancel($run);

        return response()->json($this->stateOf($run, $request));
    }

    public function resume(RunStateRequest $request, string $run, RunProcessor $processor, RunDispatcher $dispatcher): JsonResponse
    {
        $stored = $this->find($run);

        if (! $processor->resume($stored)) {
            $this->fail(409, 'not_resumable', __('Only a cancelled, aborted or running run can be resumed.'));
        }

        $dispatcher->start($stored);

        return response()->json($this->stateOf($stored, $request));
    }

    /**
     * Puts the failed items back, with the items their failure skipped, and runs the run again.
     */
    public function retry(RunStateRequest $request, string $run, RunProcessor $processor, RunDispatcher $dispatcher): JsonResponse
    {
        $stored = $this->find($run);
        $retried = $processor->retry($stored);

        if ($retried > 0) {
            $dispatcher->start($stored);
        }

        return response()->json([...$this->stateOf($stored, $request), 'retried' => $retried]);
    }

    /**
     * Moves a queue run to this browser, for a site whose queue has no worker; the page then starts its lanes.
     */
    public function takeOver(RunStateRequest $request, string $run, RunDispatcher $dispatcher): JsonResponse
    {
        $stored = $this->find($run);
        $dispatcher->takeOver($stored);

        return response()->json($this->stateOf($stored, $request));
    }

    private function find(string $id): Run
    {
        return $this->runs->find($id) ?? $this->notFound();
    }

    private function followed(RunFollowRequest $request): Run
    {
        return $request->run() ?? $this->notFound();
    }

    private function stateOf(Run $run, RunStateRequest $request): array
    {
        return $this->state->for($run, $request->page(), $request->perPage(), $request->status());
    }

    private function notFound(): never
    {
        $this->fail(404, 'run_not_found', __('This run no longer exists.'));
    }
}
