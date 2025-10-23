<?php

use App\Http\Controllers\TranscribeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Transcription page routes (Inertia)
    Route::prefix('transcribe')->name('transcribe.')->group(function () {
        Route::get('/', [TranscribeController::class, 'index'])->name('index');
        Route::get('/record', [TranscribeController::class, 'create'])->name('create');
        Route::get('/sessions/{session}', [TranscribeController::class, 'show'])->name('show');
        Route::delete('/sessions/{session}', [TranscribeController::class, 'destroy'])->name('destroy');
    });
});

require __DIR__.'/settings.php';
