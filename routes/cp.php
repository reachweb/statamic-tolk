<?php

use Illuminate\Support\Facades\Route;
use Reach\StatamicTolk\Http\Controllers\Cp\EditorController;
use Reach\StatamicTolk\Http\Controllers\Cp\ModelsController;

Route::name('tolk.')->prefix('tolk')->group(function () {
    Route::name('editor.')->prefix('editor')->middleware('can:translate content')->group(function () {
        Route::get('status', [EditorController::class, 'status'])->name('status');
        Route::post('plan', [EditorController::class, 'plan'])->name('plan');
        Route::post('review', [EditorController::class, 'review'])->name('review');
        Route::post('unit', [EditorController::class, 'unit'])->name('unit');
        Route::post('confirm', [EditorController::class, 'confirm'])->name('confirm');
        Route::post('baseline', [EditorController::class, 'baseline'])->name('baseline');
    });

    Route::get('models', [ModelsController::class, 'index'])->name('models.index');
});
