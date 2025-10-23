<?php

use App\Jobs\FinalizeTranscript;
use App\Models\DictationSession;
use App\Models\User;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('finalize transcript job combines partials and calls claude', function () {
    $user = User::factory()->create();
    $session = DictationSession::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    // Create multiple partial transcripts
    $session->transcripts()->create([
        'type' => 'asr_partial',
        'text' => 'esto es una prueba',
        'meta' => [
            'words' => [
                ['text' => 'esto', 'confidence' => 0.98, 'start' => 0.0, 'end' => 0.5],
                ['text' => 'es', 'confidence' => 0.99, 'start' => 0.5, 'end' => 0.7],
                ['text' => 'una', 'confidence' => 0.95, 'start' => 0.7, 'end' => 1.0],
                ['text' => 'prueba', 'confidence' => 0.92, 'start' => 1.0, 'end' => 1.5],
            ],
        ],
    ]);

    $session->transcripts()->create([
        'type' => 'asr_partial',
        'text' => 'de audio uno dos tres',
        'meta' => [
            'words' => [
                ['text' => 'de', 'confidence' => 0.97, 'start' => 1.5, 'end' => 1.7],
                ['text' => 'audio', 'confidence' => 0.94, 'start' => 1.7, 'end' => 2.2],
                ['text' => 'uno', 'confidence' => 0.96, 'start' => 2.2, 'end' => 2.6],
                ['text' => 'dos', 'confidence' => 0.95, 'start' => 2.6, 'end' => 3.0],
                ['text' => 'tres', 'confidence' => 0.97, 'start' => 3.0, 'end' => 3.5],
            ],
        ],
    ]);

    // Mock ClaudeService
    $this->mock(ClaudeService::class, function ($mock) {
        $mock->shouldReceive('polishTranscript')
            ->once()
            ->withArgs(function ($text, $metadata) {
                return $text === 'esto es una prueba de audio uno dos tres'
                    && count($metadata['words']) === 9;
            })
            ->andReturn([
                'text' => 'Esto es una prueba de audio: uno, dos, tres.',
                'segments' => [
                    ['text' => 'Esto es una prueba de audio: uno, dos, tres.', 'start' => 0.0, 'end' => 3.5],
                ],
                'uncertain_words' => [],
            ]);
    });

    // Process the job
    $job = new FinalizeTranscript($session);
    $job->handle(app(ClaudeService::class));

    // Verify final transcript was created
    $this->assertDatabaseHas('transcripts', [
        'dictation_session_id' => $session->id,
        'type' => 'llm_final',
        'text' => 'Esto es una prueba de audio: uno, dos, tres.',
    ]);

    // Verify session status updated to ready
    $session->refresh();
    expect($session->status)->toBe('ready');

    // Verify metadata includes uncertain words
    $finalTranscript = $session->transcripts()->where('type', 'llm_final')->first();
    expect($finalTranscript->meta)->toHaveKey('segments');
    expect($finalTranscript->meta)->toHaveKey('uncertain_words');
    expect($finalTranscript->meta)->toHaveKey('words');
    expect($finalTranscript->meta['words'])->toHaveCount(9);
});

test('finalization handles uncertain words correctly', function () {
    $user = User::factory()->create();
    $session = DictationSession::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    // Create partial with low confidence words
    $session->transcripts()->create([
        'type' => 'asr_partial',
        'text' => 'hola mundo prueba test',
        'meta' => [
            'words' => [
                ['text' => 'hola', 'confidence' => 0.95],
                ['text' => 'mundo', 'confidence' => 0.62], // Low confidence
                ['text' => 'prueba', 'confidence' => 0.90],
                ['text' => 'test', 'confidence' => 0.55], // Low confidence
            ],
        ],
    ]);

    // Mock ClaudeService with uncertain words
    $this->mock(ClaudeService::class, function ($mock) {
        $mock->shouldReceive('polishTranscript')
            ->once()
            ->andReturn([
                'text' => 'Hola mundo, prueba test.',
                'segments' => [
                    ['text' => 'Hola mundo, prueba test.', 'start' => 0.0, 'end' => 2.0],
                ],
                'uncertain_words' => [
                    [
                        'word' => 'mundo',
                        'position' => 5,
                        'confidence' => 0.62,
                        'alternatives' => ['mando', 'mudo'],
                    ],
                    [
                        'word' => 'test',
                        'position' => 21,
                        'confidence' => 0.55,
                        'alternatives' => ['texto', 'best'],
                    ],
                ],
            ]);
    });

    // Process the job
    $job = new FinalizeTranscript($session);
    $job->handle(app(ClaudeService::class));

    // Verify uncertain words were stored
    $finalTranscript = $session->transcripts()->where('type', 'llm_final')->first();
    expect($finalTranscript->meta['uncertain_words'])->toHaveCount(2);
    expect($finalTranscript->meta['uncertain_words'][0]['word'])->toBe('mundo');
    expect($finalTranscript->meta['uncertain_words'][0]['alternatives'])->toContain('mando');
    expect($finalTranscript->meta['uncertain_words'][1]['word'])->toBe('test');
    expect($finalTranscript->meta['uncertain_words'][1]['alternatives'])->toContain('texto');
});

test('finalization handles empty partials gracefully', function () {
    $user = User::factory()->create();
    $session = DictationSession::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    // No partial transcripts created

    // Process the job
    $job = new FinalizeTranscript($session);
    $job->handle(app(ClaudeService::class));

    // Verify no final transcript was created
    $this->assertDatabaseMissing('transcripts', [
        'dictation_session_id' => $session->id,
        'type' => 'llm_final',
    ]);

    // Verify session status still updated to ready
    $session->refresh();
    expect($session->status)->toBe('ready');
});

test('finalization endpoint triggers job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $session = DictationSession::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    // Create a partial transcript
    $session->transcripts()->create([
        'type' => 'asr_partial',
        'text' => 'esto es una prueba',
        'meta' => ['words' => []],
    ]);

    $response = $this->actingAs($user)->postJson("/transcribe/sessions/{$session->id}/finalize");

    $response->assertStatus(200);
    Queue::assertPushed(FinalizeTranscript::class);
});
