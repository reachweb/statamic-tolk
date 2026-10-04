<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Http\Requests\ModelsRequest;
use Reach\StatamicTolk\Support\Settings;

class ModelsController extends Controller
{
    public function index(ModelsRequest $request, ModelCatalog $catalog, Settings $settings): JsonResponse
    {
        return response()->json([
            'catalog' => $catalog->grouped(),
            'defaults' => $this->defaults($catalog, $settings),
        ]);
    }

    private function defaults(ModelCatalog $catalog, Settings $settings): array
    {
        try {
            $translator = $catalog->resolveTranslator($settings);
            $reviewer = $catalog->resolveReviewer($settings, $translator);
        } catch (NoProviderConfigured|MissingDefaultModel) {
            return ['translator' => null, 'reviewer' => null, 'fallback' => null];
        }

        $fallback = $catalog->resolveFallback($settings, $translator);

        return [
            'translator' => (string) $translator,
            'reviewer' => (string) $reviewer,
            'fallback' => $fallback ? (string) $fallback : null,
        ];
    }
}
