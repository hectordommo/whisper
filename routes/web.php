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

    // Transcription routes
    Route::prefix('transcribe')->name('transcribe.')->group(function () {
        Route::get('/', [TranscribeController::class, 'index'])->name('index');
        Route::get('/record', [TranscribeController::class, 'create'])->name('create');
        Route::post('/sessions', [TranscribeController::class, 'store'])->name('store');
        Route::get('/sessions/{session}', [TranscribeController::class, 'show'])->name('show');
        Route::post('/sessions/{session}/chunks', [TranscribeController::class, 'uploadChunk'])->name('uploadChunk');
        Route::get('/sessions/{session}/transcript', [TranscribeController::class, 'getTranscript'])->name('getTranscript');
        Route::post('/sessions/{session}/finalize', [TranscribeController::class, 'finalize'])->name('finalize');
        Route::post('/sessions/{session}/accept-word', [TranscribeController::class, 'acceptWord'])->name('acceptWord');
        Route::delete('/sessions/{session}', [TranscribeController::class, 'destroy'])->name('destroy');
    });
});

require __DIR__.'/settings.php';
