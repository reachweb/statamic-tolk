<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\SiteProfileGenerator;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProfileFailed;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\FailsWithJson;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\RaisesTimeLimit;
use Reach\StatamicTolk\Http\Requests\SiteProfileGenerateRequest;

class SiteProfileController extends Controller
{
    use FailsWithJson;
    use RaisesTimeLimit;

    public function generate(SiteProfileGenerateRequest $request, SiteProfileGenerator $generator, ModelCatalog $catalog): JsonResponse
    {
        $this->raiseTimeLimit(SiteProfileGenerator::TIMEOUT + 30);

        try {
            $model = $generator->model($request->translator($catalog));
            $result = $generator->generate($model, UsageSource::Editor);
        } catch (NoProviderConfigured|ProviderNotConfigured $exception) {
            $this->fail(422, 'not_configured', $exception->getMessage());
        } catch (MissingDefaultModel $exception) {
            $this->fail(422, 'no_model', $exception->getMessage());
        } catch (InvalidModelRef $exception) {
            $this->fail(422, 'invalid_model', $exception->getMessage());
        } catch (ProfileFailed $exception) {
            $this->fail(422, 'failed', $exception->getMessage());
        }

        return response()->json([...$result, 'model' => (string) $model, 'label' => $catalog->label($model)]);
    }
}
