<?php

namespace Reach\StatamicTolk\Ai;

use Reach\StatamicTolk\Ai\Agents\ConnectionTestAgent;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Runs\UsageRecorder;
use Reach\StatamicTolk\Support\Sanitizer;
use Throwable;

/**
 * The settings page's "Test connection": one tiny structured call to a provider's fast model. A provider that fails
 * the call is a result, not an error; the call is recorded in the usage log like the editor's.
 */
final readonly class ConnectionTester
{
    public const int TIMEOUT = 20;

    private const int OUTPUT_TOKENS = 16;

    public function __construct(
        private ModelCatalog $catalog,
        private Providers $providers,
        private ErrorClassifier $classifier,
        private UsageRecorder $recorder,
    ) {}

    /**
     * The model a test calls: the provider's fast model, else its quality model.
     *
     * @throws MissingDefaultModel when the catalog has neither
     */
    public function model(string $provider): ModelRef
    {
        try {
            return $this->catalog->default($provider, Tier::Fast);
        } catch (MissingDefaultModel) {
            return $this->catalog->default($provider, Tier::Quality);
        }
    }

    /**
     * The fake engine answers for every provider, configured or not, without a call.
     *
     * @return array{ok: true, model: string, label: string, latency_ms: int, fake: bool}|array{ok: false, model: string, label: string, error: string}
     *
     * @throws ProviderNotConfigured
     * @throws MissingDefaultModel
     */
    public function test(string $provider): array
    {
        $fake = FakeEngine::enabled();

        if (! $fake && ! $this->providers->isConfigured($provider)) {
            throw ProviderNotConfigured::for($provider);
        }

        $model = $this->model($provider);
        $result = ['model' => (string) $model, 'label' => $this->catalog->label($model)];
        $started = hrtime(true);

        if ($fake) {
            FakeEngine::delay();
            $usage = new Usage(calls: 1);
        } else {
            $agent = new ConnectionTestAgent(
                model: $model,
                instructions: 'This is a connection test. Return the word ok as the reply.',
                providerOptions: $this->catalog->options($model),
                timeout: self::TIMEOUT,
                maxTokens: $this->catalog->outputCap($model, self::OUTPUT_TOKENS),
            );

            try {
                $usage = Usage::fromResponse($agent->prompt('Reply with ok.'));
            } catch (Throwable $exception) {
                return ['ok' => false, ...$result, 'error' => $this->error($exception, $model)];
            }
        }

        $latency = self::since($started);
        $this->recorder->record($model, $usage, UsageSource::Editor);

        return ['ok' => true, ...$result, 'latency_ms' => $latency, 'fake' => $fake];
    }

    private function error(Throwable $exception, ModelRef $model): string
    {
        $error = $this->classifier->classify($exception, $model->provider);

        return Sanitizer::message($error === null
            ? __('The test failed: :message', ['message' => $exception->getMessage()])
            : $this->describe($error, $model, $exception));
    }

    private function describe(ProviderError $error, ModelRef $model, Throwable $exception): string
    {
        $replace = [
            'provider' => Providers::label($model->provider),
            'model' => $model->model,
            'status' => $error->describe(),
            // The SDK's own message names the provider by Tolk's internal name; the HTTP client's says what failed.
            'message' => $error->kind === ProviderErrorKind::Unavailable && $exception->getPrevious()
                ? $exception->getPrevious()->getMessage()
                : $error->message,
            'seconds' => self::TIMEOUT,
        ];

        return match (true) {
            $error->kind === ProviderErrorKind::Timeout => __(':provider did not answer within :seconds seconds.', $replace),
            $error->kind === ProviderErrorKind::Unavailable => __(':provider could not be reached: :message', $replace),
            in_array($error->status, [401, 403], true) => __(':provider refused the API key (:status): :message', $replace),
            $error->status === 404 => __(':provider does not offer the model :model (:status): :message', $replace),
            $error->kind === ProviderErrorKind::Rejected => __(':provider refused the account (:status): :message', $replace),
            $error->status === 429 => __(':provider is limiting requests (:status): :message', $replace),
            $error->kind === ProviderErrorKind::Paused => __(':provider is busy or unavailable (:status): :message', $replace),
            default => __(':provider refused the request (:status): :message', $replace),
        };
    }

    private static function since(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
