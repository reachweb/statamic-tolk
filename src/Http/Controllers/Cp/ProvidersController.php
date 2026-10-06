<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Reach\StatamicTolk\Ai\ConnectionTester;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\FailsWithJson;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\RaisesTimeLimit;
use Reach\StatamicTolk\Http\Requests\ProviderTestRequest;

class ProvidersController extends Controller
{
    use FailsWithJson;
    use RaisesTimeLimit;

    public function test(ProviderTestRequest $request, ConnectionTester $tester): JsonResponse
    {
        $this->raiseTimeLimit(ConnectionTester::TIMEOUT + 10);

        try {
            return response()->json($tester->test($request->validated('provider')));
        } catch (ProviderNotConfigured $exception) {
            $this->fail(422, 'not_configured', $exception->getMessage());
        } catch (MissingDefaultModel $exception) {
            $this->fail(422, 'no_model', $exception->getMessage());
        }
    }
}
