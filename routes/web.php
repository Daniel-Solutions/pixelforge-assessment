<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\RetryGenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generate')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:generations')->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');
    Route::post('generations/{generation}/retry', RetryGenerationController::class)->middleware('throttle:generations')->name('generations.retry');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditsController::class, 'store'])->name('credits.store');

    Route::get('admin/report', AdminReportController::class)->name('admin.report');
});

require __DIR__.'/settings.php';
