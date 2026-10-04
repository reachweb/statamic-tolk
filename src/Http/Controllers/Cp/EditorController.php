<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Reach\StatamicTolk\Http\Requests\EditorBaselineRequest;
use Reach\StatamicTolk\Http\Requests\EditorConfirmRequest;
use Reach\StatamicTolk\Http\Requests\EditorPlanRequest;
use Reach\StatamicTolk\Http\Requests\EditorStatusRequest;
use Reach\StatamicTolk\Http\Requests\EditorUnitRequest;

class EditorController extends Controller
{
    public function status(EditorStatusRequest $request): JsonResponse
    {
        $this->notBuiltYet();
    }

    public function plan(EditorPlanRequest $request): JsonResponse
    {
        $this->notBuiltYet();
    }

    public function unit(EditorUnitRequest $request): JsonResponse
    {
        $this->notBuiltYet();
    }

    public function confirm(EditorConfirmRequest $request): JsonResponse
    {
        $this->notBuiltYet();
    }

    public function baseline(EditorBaselineRequest $request): JsonResponse
    {
        $this->notBuiltYet();
    }

    /**
     * The endpoints are built in T5.3 (status) and T5.4 (the others).
     */
    private function notBuiltYet(): never
    {
        abort(501);
    }
}
