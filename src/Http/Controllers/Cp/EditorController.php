<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Exceptions\UnknownUnit;
use Reach\StatamicTolk\Http\EditorStatus;
use Reach\StatamicTolk\Http\EditorTranslation;
use Reach\StatamicTolk\Http\Requests\EditorBaselineRequest;
use Reach\StatamicTolk\Http\Requests\EditorConfirmRequest;
use Reach\StatamicTolk\Http\Requests\EditorPlanRequest;
use Reach\StatamicTolk\Http\Requests\EditorReviewRequest;
use Reach\StatamicTolk\Http\Requests\EditorStatusRequest;
use Reach\StatamicTolk\Http\Requests\EditorUnitRequest;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\Sanitizer;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

class EditorController extends Controller
{
    public function __construct(private readonly TargetFactory $targets) {}

    public function status(EditorStatusRequest $request, EditorStatus $status): JsonResponse
    {
        return response()->json($status->for($request->validated('reference'), User::current()));
    }

    public function plan(EditorPlanRequest $request, EditorTranslation $translation, Settings $settings, ModelCatalog $catalog): JsonResponse
    {
        $user = User::current();
        $target = $this->authorized($this->localization($request->validated('reference')), $user);
        $options = $this->options($settings, $catalog, $user, [
            'mode' => $request->validated('mode'),
            'overwriteManual' => $request->boolean('overwrite_manual'),
            'fields' => $request->validated('fields') === null ? null : array_values($request->validated('fields')),
            'skip' => array_fill_keys(array_values($request->validated('skip', [])), EditorTranslation::EDITED_IN_FORM),
            'translator' => $request->validated('translator'),
            'review' => $request->validated('review'),
            'reviewer' => $request->validated('reviewer'),
        ]);

        return $this->attempt(fn () => $translation->plan($target, $options));
    }

    /**
     * Reviews the open form's values (T7.5). The plan's units run through `unit`, as a translation's do.
     */
    public function review(EditorReviewRequest $request, EditorTranslation $translation, Settings $settings, ModelCatalog $catalog): JsonResponse
    {
        $user = User::current();
        $target = $this->authorized($this->localization($request->validated('reference')), $user);
        // Only mode review keeps a reviewer override while the review setting is off.
        $options = $this->options($settings, $catalog, $user, [
            'mode' => Mode::Review,
            'overwriteManual' => $request->boolean('overwrite_manual'),
            'reviewer' => $request->validated('reviewer'),
        ]);

        return $this->attempt(fn () => $translation->review($target, $options, $request->validated('values')));
    }

    public function unit(EditorUnitRequest $request, EditorTranslation $translation, PlanStore $store): JsonResponse
    {
        $user = User::current();
        $plan = $store->get($request->validated('plan'))
            ?? $this->fail(410, 'plan_expired', __('This translation has expired. Start it again.'));

        if ($plan->options->userId !== (string) $user->id()) {
            throw new AuthorizationException;
        }

        try {
            $target = $this->targets->fromDescription($plan->target);
        } catch (TargetNotFound|InvalidTarget|UnknownSite) {
            $target = null;
        }

        if (! $target?->exists()) {
            $this->fail(410, 'target_missing', __('The entry this translation was made for no longer exists.'));
        }

        $this->authorized($target, $user);
        $this->raiseTimeLimit();

        return $this->attempt(fn () => $translation->unit($plan, $target, $request->validated('unit')));
    }

    public function confirm(EditorConfirmRequest $request, StateWriter $stateWriter, EditorStatus $status): JsonResponse
    {
        $user = User::current();
        $reference = $request->validated('reference');
        $notSaved = [];

        if ($target = $this->localizationOrNull($reference)) {
            $report = $stateWriter->confirm($this->authorized($target, $user), commit: true);

            // A field that a condition hid is not posted, so the save stored no override for it.
            $notSaved = array_values(array_filter(
                array_intersect($request->validated('delivered', []), $report->unresolved),
                fn (string $handle) => ! $target->hasOverride($handle),
            ));
        }

        return response()->json([...$status->for($reference, $user), 'not_saved' => $notSaved]);
    }

    public function baseline(EditorBaselineRequest $request, StateWriter $stateWriter, EditorStatus $status): JsonResponse
    {
        $user = User::current();
        $reference = $request->validated('reference');
        $fields = $request->validated('fields');
        $count = 0;

        if ($target = $this->localizationOrNull($reference)) {
            $count = $stateWriter->baseline(
                $this->authorized($target, $user),
                $fields === null ? null : array_values($fields),
                includeMachine: $request->boolean('protect'),
                commit: true,
            );
        }

        return response()->json([...$status->for($reference, $user), 'baselined' => $count]);
    }

    private function options(Settings $settings, ModelCatalog $catalog, UserContract $user, array $overrides): PlanOptions
    {
        try {
            return PlanOptions::fromSettings($settings, $catalog, [...$overrides, 'profile' => 'web', 'userId' => (string) $user->id()]);
        } catch (NoProviderConfigured|MissingDefaultModel $exception) {
            $this->fail(422, 'no_translator', $exception->getMessage());
        }
    }

    private function localization(string $reference): Target
    {
        try {
            return $this->targets->fromReference($reference);
        } catch (InvalidTargetRef|TargetNotFound) {
            $this->fail(404, 'unknown_reference', __('The entry to translate was not found.'));
        } catch (NotALocalization|NotSupportedYet $exception) {
            $this->fail(422, 'not_translatable', $exception->getMessage());
        }
    }

    /**
     * Null when the reference names no localization: confirm and baseline then only return the status.
     */
    private function localizationOrNull(string $reference): ?Target
    {
        try {
            return $this->targets->fromReference($reference);
        } catch (InvalidTargetRef|TargetNotFound|NotALocalization|NotSupportedYet) {
            return null;
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorized(Target $target, UserContract $user): Target
    {
        if (! $target->authorize($user)) {
            throw new AuthorizationException;
        }

        return $target;
    }

    /**
     * Maps what a plan or a unit can run into to a response the unit runner acts on (T5.8).
     */
    private function attempt(Closure $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ProviderPaused $exception) {
            $this->fail(429, 'provider_paused', $exception->getMessage(), $exception->seconds);
        } catch (ProviderUnavailable $exception) {
            $this->fail(503, 'provider_unavailable', $exception->getMessage(), $exception->seconds);
        } catch (ProviderRejected $exception) {
            $this->fail(502, 'provider_rejected', $exception->getMessage());
        } catch (UnitBusy $exception) {
            $this->fail(409, 'busy', $exception->getMessage());
        } catch (SourceChanged) {
            $this->fail(409, 'source_changed', __('The origin was changed while it was being translated. Start again.'));
        } catch (UnknownUnit $exception) {
            $this->fail(404, 'unknown_unit', $exception->getMessage());
        }
    }

    /**
     * Gives a unit time for its one provider call, without lowering a limit that is higher or off.
     */
    private function raiseTimeLimit(): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && function_exists('set_time_limit')) {
            set_time_limit(max($limit, (int) config('tolk.http.timeout.web', 45) + 15));
        }
    }

    private function fail(int $status, string $code, string $message, ?int $retryAfter = null): never
    {
        $body = ['message' => Sanitizer::message($message), 'code' => $code];
        $headers = [];

        if ($retryAfter !== null) {
            $body['retry_after'] = max(1, $retryAfter);
            $headers['Retry-After'] = (string) $body['retry_after'];
        }

        throw new HttpResponseException(response()->json($body, $status, $headers));
    }
}
