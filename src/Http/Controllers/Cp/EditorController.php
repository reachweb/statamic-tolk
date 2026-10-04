<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Reach\StatamicTolk\Http\EditorStatus;
use Reach\StatamicTolk\Http\Requests\EditorBaselineRequest;
use Reach\StatamicTolk\Http\Requests\EditorConfirmRequest;
use Reach\StatamicTolk\Http\Requests\EditorPlanRequest;
use Reach\StatamicTolk\Http\Requests\EditorStatusRequest;
use Reach\StatamicTolk\Http\Requests\EditorUnitRequest;
use Statamic\Facades\User;

class EditorController extends Controller
{
    public function status(EditorStatusRequest $request, EditorStatus $status): JsonResponse
    {
        return response()->json($status->for($request->validated('reference'), User::current()));
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
     * The endpoints are built in T5.4.
     */
    private function notBuiltYet(): never
    {
        abort(501);
    }
}
