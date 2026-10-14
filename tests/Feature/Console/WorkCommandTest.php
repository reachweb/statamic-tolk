<?php

use Carbon\CarbonInterval as Duration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Symfony\Component\Process\Process as SymfonyProcess;

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

it('cancels the run and stops before its next unit when the command that started it has ended without cancelling it', function () {
    if (! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the posix extension.');
    }

    // Stands in for tolk:translate; it is not this process's parent, so the worker checks that the process exists.
    $command = new SymfonyProcess([PHP_BINARY, '-r', 'sleep(60);']);
    $command->start();
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);
    $engine = ScriptedEngine::bind(function (EngineCall $call) use ($command) {
        // kill -9 while the worker waits for the model, as the out-of-memory killer ends the command.
        $command->signal(SIGKILL);
        $command->wait();

        return ScriptedEngine::translated($call);
    });

    try {
        [$exitCode] = workCommand(['run' => $run->id, '--parent' => $command->getPid()]);
    } finally {
        $command->stop(0);
    }

    expect($exitCode)->toBe(0)
        ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Cancelled)
        ->and(RunFixtures::statuses($run))->toBe(['translated', 'pending'])
        ->and($engine->calls)->toHaveCount(1);
});

it('stops without cancelling the run when the Control Panel resumed it after its command ended', function () {
    if (! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the posix extension.');
    }

    config(['tolk.runs.execution' => 'browser']);
    $command = new SymfonyProcess([PHP_BINARY, '-r', 'sleep(60);']);
    $command->start();
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);
    $engine = ScriptedEngine::bind(function (EngineCall $call) use ($command, $run) {
        $command->signal(SIGKILL);
        $command->wait();

        // While this worker waits for the model, another one cancels the run, and the user resumes it on its run page,
        // as RunsController@resume does, which hands it to the browser.
        app(RunProcessor::class)->cancel(RunFixtures::stored($run));
        $resumed = RunFixtures::stored($run);
        app(RunProcessor::class)->resume($resumed);
        app(RunDispatcher::class)->start($resumed);

        return ScriptedEngine::translated($call);
    });

    try {
        [$exitCode] = workCommand(['run' => $run->id, '--parent' => $command->getPid()]);
    } finally {
        $command->stop(0);
    }

    expect($exitCode)->toBe(0)
        ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Running)
        ->and(RunFixtures::stored($run)->driver)->toBe(RunDriver::Browser)
        ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending'])
        ->and($engine->calls)->toHaveCount(1);
});

it('keeps working while the command that started it runs', function (Closure $parent) {
    if (! function_exists('posix_getppid')) {
        $this->markTestSkipped('Needs the posix extension.');
    }

    $this->fakeTranslations();
    $run = RunFixtures::started(['driver' => RunDriver::Cli]);

    [$exitCode] = workCommand(['run' => $run->id, '--parent' => $parent()]);

    expect($exitCode)->toBe(0)
        ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Running)
        ->and(RunFixtures::statuses($run))->toBe(['translated', 'translated']);
})->with([
    'its parent' => [fn () => posix_getppid()],
    // Started through a shell, as Symfony's Process does on PHP built with --enable-sigchild.
    'another process' => [fn () => getmypid()],
]);

it('refuses an unknown run, an invalid lane and an invalid parent', function (Closure $arguments, string $message) {
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
    'parent 0' => [fn ($run) => ['run' => $run->id, '--parent' => '0'], 'Invalid parent [0]. Use the process id of the command that starts the worker.'],
    'parent x' => [fn ($run) => ['run' => $run->id, '--parent' => 'x'], 'Invalid parent [x].'],
]);

it('is hidden from the command list', function () {
    expect(Artisan::all()['tolk:work']->isHidden())->toBeTrue();
});
