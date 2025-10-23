<?php

use App\Services\WhisperService;
use Illuminate\Support\Facades\Http;

test('whisper service transcribes audio successfully', function () {
    // Mock successful Whisper API response
    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => Http::response([
            'text' => 'esto es una prueba de audio uno dos tres',
            'language' => 'es',
            'duration' => 3.5,
            'words' => [
                ['word' => 'esto', 'start' => 0.0, 'end' => 0.5],
                ['word' => 'es', 'start' => 0.5, 'end' => 0.7],
                ['word' => 'una', 'start' => 0.7, 'end' => 1.0],
                ['word' => 'prueba', 'start' => 1.0, 'end' => 1.5],
                ['word' => 'de', 'start' => 1.5, 'end' => 1.7],
                ['word' => 'audio', 'start' => 1.7, 'end' => 2.2],
                ['word' => 'uno', 'start' => 2.2, 'end' => 2.6],
                ['word' => 'dos', 'start' => 2.6, 'end' => 3.0],
                ['word' => 'tres', 'start' => 3.0, 'end' => 3.5],
            ],
        ], 200),
    ]);

    $service = new WhisperService('test-api-key');

    // Use the test audio file
    $audioPath = base_path('tests/Fixtures/sample-audio.webm');
    $result = $service->transcribeChunk($audioPath, 'es');

    expect($result)->toBeArray();
    expect($result['text'])->toBe('esto es una prueba de audio uno dos tres');
    expect($result['language'])->toBe('es');
    expect($result['duration'])->toBe(3.5);
    expect($result['words'])->toHaveCount(9);
    expect($result['words'][0]['text'])->toBe('esto');
    expect($result['words'][0]['start'])->toEqual(0.0);
    expect($result['words'][0]['end'])->toEqual(0.5);
    expect($result['words'][0]['confidence'])->toBeGreaterThan(0.0);

    // Verify the HTTP request was made correctly
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

test('whisper service handles API errors gracefully', function () {
    // Mock failed API response
    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => Http::response([
            'error' => [
                'message' => 'Invalid API key',
                'type' => 'invalid_request_error',
            ],
        ], 401),
    ]);

    $service = new WhisperService('invalid-api-key');
    $audioPath = base_path('tests/Fixtures/sample-audio.webm');

    expect(fn() => $service->transcribeChunk($audioPath))
        ->toThrow(\Exception::class, 'Whisper API request failed');
});

test('whisper service validates API key successfully', function () {
    Http::fake([
        'api.openai.com/v1/models' => Http::response(['data' => []], 200),
    ]);

    $service = new WhisperService('valid-api-key');
    $isValid = $service->validateApiKey();

    expect($isValid)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer valid-api-key');
    });
});

test('whisper service detects invalid API key', function () {
    Http::fake([
        'api.openai.com/v1/models' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $service = new WhisperService('invalid-api-key');
    $isValid = $service->validateApiKey();

    expect($isValid)->toBeFalse();
});

test('whisper service uses config API key when none provided', function () {
    config(['services.openai.api_key' => 'config-api-key']);

    Http::fake([
        'api.openai.com/v1/models' => Http::response(['data' => []], 200),
    ]);

    $service = new WhisperService();
    $service->validateApiKey();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer config-api-key');
    });
});

test('whisper service formats response without words correctly', function () {
    // Mock response without word-level timestamps
    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => Http::response([
            'text' => 'hola mundo',
            'language' => 'es',
            'duration' => 1.5,
            // No 'words' array
        ], 200),
    ]);

    $service = new WhisperService('test-api-key');
    $audioPath = base_path('tests/Fixtures/sample-audio.webm');
    $result = $service->transcribeChunk($audioPath);

    expect($result['text'])->toBe('hola mundo');
    expect($result['words'])->toBeArray();
    expect($result['words'])->toBeEmpty();
});

test('whisper service defaults to Spanish language', function () {
    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => Http::response([
            'text' => 'test',
            'language' => 'es',
            'duration' => 1.0,
            'words' => [],
        ], 200),
    ]);

    $service = new WhisperService('test-api-key');
    $audioPath = base_path('tests/Fixtures/sample-audio.webm');

    // Call without language parameter
    $result = $service->transcribeChunk($audioPath);

    // Verify the response contains Spanish language
    expect($result['language'])->toBe('es');

    // Verify the request was made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});
