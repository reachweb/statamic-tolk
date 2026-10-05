<?php

use Carbon\CarbonInterval as Duration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
});

/**
 * @return array{0: int, 1: string}
 */
function workCommand(array $arguments): array
{
    $exitCode = Artisan::call('tolk:work', $arguments);

    return [$exitCode, Artisan::output()];
}

it('translates every item of the run as the worker of its lane, and applies none', function () {
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);
    $holders = [];
    ScriptedEngine::bind(function (EngineCall $call) use ($run, &$holders) {
        foreach (RunFixtures::items($run) as $item) {
            $holders[] = $item->claim?->by;
        }

        return ScriptedEngine::translated($call);
    });

    [$exitCode] = workCommand(['run' => $run->id, '--lane' => 2]);

    expect($exitCode)->toBe(0)
        ->and(RunFixtures::statuses($run))->toBe(['translated', 'translated'])
        ->and(array_values(array_unique(array_filter($holders))))->toBe(['cli-2'])
        ->and($this->hello->fresh()->existsIn('el'))->toBeFalse()
        ->and($this->world->fresh()->existsIn('el'))->toBeFalse();
});

it('ends at once when the run is not running', function () {
    $this->fakeTranslations();
    $run = RunFixtures::pending(['driver' => RunDriver::Cli]);

    [$exitCode] = workCommand(['run' => $run->id]);

    expect($exitCode)->toBe(0)
        ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending']);
    $this->assertNoModelCalls();
});

it('sleeps through a provider pause and goes on', function () {
    Sleep::fake(syncWithCarbon: true);
    ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
        ? throw ProviderPaused::for('anthropic', 7)
        : ScriptedEngine::translated($call));
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);

    [$exitCode] = workCommand(['run' => $run->id]);

    expect($exitCode)->toBe(0)
        ->and(RunFixtures::statuses($run))->toBe(['translated', 'translated']);
    Sleep::assertSlept(fn (Duration $duration) => $duration->totalSeconds === 7.0);
});

it('reads the settings again before each call', function () {
    $this->fakeTranslations();
    $settings = Mockery::mock(Settings::class)->makePartial();
    app()->instance(Settings::class, $settings);
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);

    workCommand(['run' => $run->id]);

    $settings->shouldHaveReceived('fresh')->atLeast()->once();
});

it('ends quietly when the run is deleted while it works', function () {
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);
    ScriptedEngine::bind(function (EngineCall $call) use ($run) {
        app(RunRepository::class)->delete($run);

        return ScriptedEngine::translated($call);
    });

    [$exitCode] = workCommand(['run' => $run->id]);

    expect($exitCode)->toBe(0)
        ->and(app(RunRepository::class)->find($run->id))->toBeNull();
});

it('refuses an unknown run and an invalid lane', function (Closure $arguments, string $message) {
    $this->fakeTranslations();
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);

    [$exitCode, $output] = workCommand($arguments($run));

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($message)
        ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending']);
    $this->assertNoModelCalls();
})->with([
    'unknown run' => [fn () => ['run' => 'missing'], 'Run [missing] not found.'],
    'lane 0' => [fn ($run) => ['run' => $run->id, '--lane' => '0'], 'Invalid lane [0]. Use a whole number of at least 1.'],
    'lane x' => [fn ($run) => ['run' => $run->id, '--lane' => 'x'], 'Invalid lane [x].'],
]);

it('is hidden from the command list', function () {
    expect(Artisan::all()['tolk:work']->isHidden())->toBeTrue();
});
