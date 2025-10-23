<?php

use App\Http\Controllers\TranscribeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Test endpoint to verify authentication
Route::middleware(['web', 'auth'])->get('/test-auth', function () {
    return response()->json([
        'authenticated' => true,
        'user' => auth()->user()->only(['id', 'name', 'email']),
    ]);
});

// Transcription API routes - these need to return JSON, not Inertia responses
// Using web middleware group to access session cookies for authentication
Route::middleware(['web', 'auth'])->prefix('transcribe')->name('api.transcribe.')->group(function () {
    Route::post('/sessions', [TranscribeController::class, 'store'])->name('store');
    Route::post('/sessions/{session}/chunks', [TranscribeController::class, 'uploadChunk'])->name('uploadChunk');
    Route::get('/sessions/{session}/transcript', [TranscribeController::class, 'getTranscript'])->name('getTranscript');
    Route::post('/sessions/{session}/finalize', [TranscribeController::class, 'finalize'])->name('finalize');
    Route::post('/sessions/{session}/accept-word', [TranscribeController::class, 'acceptWord'])->name('acceptWord');
});
