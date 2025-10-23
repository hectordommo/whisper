<?php

use App\Services\ClaudeService;
use Illuminate\Support\Facades\Http;

test('claude service polishes transcript successfully', function () {
    // Mock successful Claude API response
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'text' => 'Esto es una prueba de audio: uno, dos, tres.',
                        'segments' => [
                            [
                                'start' => 0.0,
                                'end' => 3.5,
                                'text' => 'Esto es una prueba de audio: uno, dos, tres.',
                            ],
                        ],
                        'uncertain_words' => [],
                    ]),
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $service = new ClaudeService('test-api-key');

    $text = 'esto es una prueba de audio uno dos tres';
    $metadata = [
        'words' => [
            ['text' => 'esto', 'confidence' => 0.98, 'start' => 0.0, 'end' => 0.5],
            ['text' => 'es', 'confidence' => 0.99, 'start' => 0.5, 'end' => 0.7],
            ['text' => 'una', 'confidence' => 0.95, 'start' => 0.7, 'end' => 1.0],
        ],
    ];

    $result = $service->polishTranscript($text, $metadata);

    expect($result)->toBeArray();
    expect($result['text'])->toBe('Esto es una prueba de audio: uno, dos, tres.');
    expect($result['segments'])->toBeArray();
    expect($result['segments'])->toHaveCount(1);
    expect($result['uncertain_words'])->toBeArray();

    // Verify the HTTP request was made correctly
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-3-5-sonnet-20241022'
            && isset($request['system'])
            && isset($request['messages']);
    });
});

test('claude service identifies uncertain words', function () {
    // Mock Claude response with uncertain words
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'text' => 'Hola mundo, esto es una prueba.',
                        'segments' => [
                            ['start' => 0.0, 'end' => 2.5, 'text' => 'Hola mundo, esto es una prueba.'],
                        ],
                        'uncertain_words' => [
                            [
                                'word' => 'mundo',
                                'position' => 1,
                                'confidence' => 0.62,
                                'alternatives' => ['mando', 'mudo'],
                            ],
                        ],
                    ]),
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $service = new ClaudeService('test-api-key');

    $text = 'hola mundo esto es una prueba';
    $metadata = [
        'words' => [
            ['text' => 'hola', 'confidence' => 0.95],
            ['text' => 'mundo', 'confidence' => 0.62], // Low confidence
            ['text' => 'esto', 'confidence' => 0.90],
        ],
    ];

    $result = $service->polishTranscript($text, $metadata);

    expect($result['uncertain_words'])->toHaveCount(1);
    expect($result['uncertain_words'][0]['word'])->toBe('mundo');
    expect($result['uncertain_words'][0]['confidence'])->toBe(0.62);
    expect($result['uncertain_words'][0]['alternatives'])->toContain('mando');
});

test('claude service handles API errors gracefully', function () {
    // Mock failed API response
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ], 401),
    ]);

    $service = new ClaudeService('invalid-api-key');

    expect(fn() => $service->polishTranscript('test text'))
        ->toThrow(\Exception::class, 'Claude API request failed');
});

test('claude service validates API key successfully', function () {
    Http::fake([
        'api.anthropic.com/v1/models' => Http::response(['data' => []], 200),
    ]);

    $service = new ClaudeService('valid-api-key');
    $isValid = $service->validateApiKey();

    expect($isValid)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/models'
            && $request->hasHeader('x-api-key', 'valid-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01');
    });
});

test('claude service detects invalid API key', function () {
    Http::fake([
        'api.anthropic.com/v1/models' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $service = new ClaudeService('invalid-api-key');
    $isValid = $service->validateApiKey();

    expect($isValid)->toBeFalse();
});

test('claude service uses config API key when none provided', function () {
    config(['services.anthropic.api_key' => 'config-api-key']);

    Http::fake([
        'api.anthropic.com/v1/models' => Http::response(['data' => []], 200),
    ]);

    $service = new ClaudeService();
    $service->validateApiKey();

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key', 'config-api-key');
    });
});

test('claude service falls back when JSON parsing fails', function () {
    // Mock response without valid JSON
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'This is plain text without JSON structure',
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $service = new ClaudeService('test-api-key');
    $result = $service->polishTranscript('test text');

    expect($result)->toBeArray();
    expect($result['text'])->toBe('This is plain text without JSON structure');
    expect($result['segments'])->toBeArray();
    expect($result['segments'])->toBeEmpty();
    expect($result['uncertain_words'])->toBeArray();
    expect($result['uncertain_words'])->toBeEmpty();
});

test('claude service formats user message with metadata', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'text' => 'Test',
                        'segments' => [],
                        'uncertain_words' => [],
                    ]),
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $service = new ClaudeService('test-api-key');

    $text = 'hola mundo';
    $metadata = [
        'words' => [
            ['text' => 'hola', 'confidence' => 0.95],
            ['text' => 'mundo', 'confidence' => 0.88],
        ],
    ];

    $service->polishTranscript($text, $metadata);

    Http::assertSent(function ($request) use ($text) {
        $messages = $request['messages'] ?? [];
        $userMessage = $messages[0]['content'] ?? '';

        return str_contains($userMessage, $text)
            && str_contains($userMessage, 'Metadatos de palabras')
            && str_contains($userMessage, 'hola')
            && str_contains($userMessage, 'mundo');
    });
});

test('claude service includes Spanish Mexico prompt in system message', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'text' => 'Test',
                        'segments' => [],
                        'uncertain_words' => [],
                    ]),
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $service = new ClaudeService('test-api-key');
    $service->polishTranscript('test text');

    Http::assertSent(function ($request) {
        $system = $request['system'] ?? '';

        return str_contains($system, 'español de México')
            && str_contains($system, 'puntuación')
            && str_contains($system, 'tildes')
            && str_contains($system, 'confianza');
    });
});
