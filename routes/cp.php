<?php

use Illuminate\Support\Facades\Route;
use Reach\StatamicTolk\Http\Controllers\Cp\AuditController;
use Reach\StatamicTolk\Http\Controllers\Cp\EditorController;
use Reach\StatamicTolk\Http\Controllers\Cp\ModelsController;
use Reach\StatamicTolk\Http\Controllers\Cp\OverviewController;
use Reach\StatamicTolk\Http\Controllers\Cp\ProvidersController;
use Reach\StatamicTolk\Http\Controllers\Cp\RunsController;
use Reach\StatamicTolk\Http\Controllers\Cp\SiteProfileController;

Route::name('tolk.')->prefix('tolk')->group(function () {
    Route::middleware('can:run bulk translations')->group(function () {
        // Not `/`: for a page the nav does not list, such as a run's, the CP marks the nav child whose URL is its prefix.
        Route::get('overview', [OverviewController::class, 'index'])->name('overview');
        Route::post('overview/scan', [OverviewController::class, 'scan'])->name('overview.scan');
        Route::get('audit', [AuditController::class, 'index'])->name('audit');
    });

    // The settings ability and `configure fields`; AuditLocalizableRequest checks.
    Route::post('audit/localizable', [AuditController::class, 'localizable'])->name('audit.localizable');

    Route::name('editor.')->prefix('editor')->middleware('can:translate content')->group(function () {
        Route::get('status', [EditorController::class, 'status'])->name('status');
        Route::post('plan', [EditorController::class, 'plan'])->name('plan');
        Route::post('review', [EditorController::class, 'review'])->name('review');
        Route::post('unit', [EditorController::class, 'unit'])->name('unit');
        Route::post('confirm', [EditorController::class, 'confirm'])->name('confirm');
        Route::post('baseline', [EditorController::class, 'baseline'])->name('baseline');
        Route::post('push', [EditorController::class, 'push'])->name('push');
    });

    Route::name('runs.')->prefix('runs')->group(function () {
        Route::middleware('can:run bulk translations')->group(function () {
            Route::get('/', [RunsController::class, 'index'])->name('index');
            Route::post('/', [RunsController::class, 'store'])->name('store');
            Route::get('{run}', [RunsController::class, 'show'])->name('show');
            Route::post('{run}/estimate', [RunsController::class, 'estimate'])->name('estimate');
            Route::post('{run}/start', [RunsController::class, 'start'])->name('start');
            Route::post('{run}/resume', [RunsController::class, 'resume'])->name('resume');
            Route::post('{run}/retry', [RunsController::class, 'retry'])->name('retry');
        });

        // The addon's settings ability (`editSettings`, §A14); RunResetRequest checks.
        Route::post('reset', [RunsController::class, 'reset'])->name('reset');

        // The run's creator may follow and drive it, and move it from the queue to their page, without `run bulk
        // translations` (§A14); RunFollowRequest checks.
        Route::get('{run}/state', [RunsController::class, 'state'])->name('state');
        Route::post('{run}/tick', [RunsController::class, 'tick'])->name('tick');
        Route::post('{run}/apply', [RunsController::class, 'apply'])->name('apply');
        Route::post('{run}/cancel', [RunsController::class, 'cancel'])->name('cancel');
        Route::post('{run}/take-over', [RunsController::class, 'takeOver'])->name('take-over');
    });

    Route::get('models', [ModelsController::class, 'index'])->name('models.index');

    // The addon's settings ability (`editSettings`, §A14); ProviderTestRequest, SiteProfileGenerateRequest and
    // UsageResetRequest check.
    Route::post('providers/test', [ProvidersController::class, 'test'])->name('providers.test');
    Route::post('site-profile/generate', [SiteProfileController::class, 'generate'])->name('site-profile.generate');
    Route::post('usage/reset', [OverviewController::class, 'resetUsage'])->name('usage.reset');
});
