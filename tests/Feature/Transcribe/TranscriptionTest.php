<?php

use App\Jobs\ProcessAudioChunk;
use App\Models\AudioChunk;
use App\Models\DictationSession;
use App\Models\User;
use App\Services\WhisperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('audio chunk job is dispatched when chunk is uploaded', function () {
    Queue::fake();

    // Ensure audio-chunks directory exists
    $audioChunksPath = storage_path('app/audio-chunks');
    if (!is_dir($audioChunksPath)) {
        mkdir($audioChunksPath, 0755, true);
    }

    $user = User::factory()->create();
    $session = DictationSession::factory()->create(['user_id' => $user->id]);

    $audioPath = base_path('tests/Fixtures/sample-audio.webm');
    $file = new \Illuminate\Http\UploadedFile($audioPath, 'sample-audio.webm', 'audio/webm', null, true);

    $response = $this->actingAs($user)->postJson("/transcribe/sessions/{$session->id}/chunks", [
        'file' => $file,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(ProcessAudioChunk::class);

    // Cleanup
    $session->refresh();
    $chunk = $session->audioChunks()->first();
    if ($chunk) {
        @unlink(storage_path('app/audio-chunks/' . $chunk->filename));
    }
});

test('process audio chunk job calls whisper service', function () {
    Storage::fake('local');

    $session = DictationSession::factory()->create();

    // Create audio chunk in DB
    $chunk = AudioChunk::create([
        'dictation_session_id' => $session->id,
        'filename' => 'test-audio.webm',
        'start_time' => 0,
        'end_time' => 10,
        'uploaded_at' => now(),
    ]);

    // Copy real audio file to storage for testing
    $sourcePath = base_path('tests/Fixtures/sample-audio.webm');
    $destPath = storage_path('app/audio-chunks/test-audio.webm');

    if (!is_dir(dirname($destPath))) {
        mkdir(dirname($destPath), 0755, true);
    }
    copy($sourcePath, $destPath);

    // Mock WhisperService
    $this->mock(WhisperService::class, function ($mock) {
        $mock->shouldReceive('transcribeChunk')
            ->once()
            ->andReturn([
                'text' => 'esto es una prueba de audio uno dos tres',
                'language' => 'es',
                'duration' => 3.5,
                'words' => [
                    ['text' => 'esto', 'confidence' => 0.98, 'start' => 0.0, 'end' => 0.5],
                    ['text' => 'es', 'confidence' => 0.99, 'start' => 0.5, 'end' => 0.7],
                    ['text' => 'una', 'confidence' => 0.95, 'start' => 0.7, 'end' => 1.0],
                    ['text' => 'prueba', 'confidence' => 0.92, 'start' => 1.0, 'end' => 1.5],
                    ['text' => 'de', 'confidence' => 0.97, 'start' => 1.5, 'end' => 1.7],
                    ['text' => 'audio', 'confidence' => 0.94, 'start' => 1.7, 'end' => 2.2],
                    ['text' => 'uno', 'confidence' => 0.96, 'start' => 2.2, 'end' => 2.6],
                    ['text' => 'dos', 'confidence' => 0.95, 'start' => 2.6, 'end' => 3.0],
                    ['text' => 'tres', 'confidence' => 0.97, 'start' => 3.0, 'end' => 3.5],
                ],
            ]);
    });

    // Process the job
    $job = new ProcessAudioChunk($chunk);
    $job->handle(app(WhisperService::class));

    // Verify transcript was created
    $this->assertDatabaseHas('transcripts', [
        'dictation_session_id' => $session->id,
        'type' => 'asr_partial',
    ]);

    $transcript = $session->transcripts()->first();
    expect($transcript->text)->toBe('esto es una prueba de audio uno dos tres');
    expect($transcript->meta['words'])->toHaveCount(9);
    expect($transcript->meta['language'])->toBe('es');

    // Cleanup
    @unlink($destPath);
});

test('transcription endpoint returns partial transcripts', function () {
    $user = User::factory()->create();
    $session = DictationSession::factory()->create(['user_id' => $user->id]);

    // Create some partial transcripts
    $session->transcripts()->create([
        'type' => 'asr_partial',
        'text' => 'esto es una prueba',
        'meta' => [
            'words' => [
                ['text' => 'esto', 'confidence' => 0.98],
                ['text' => 'es', 'confidence' => 0.99],
                ['text' => 'una', 'confidence' => 0.95],
                ['text' => 'prueba', 'confidence' => 0.92],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson("/transcribe/sessions/{$session->id}/transcript");

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'partials' => [
            '*' => ['id', 'text', 'meta', 'created_at'],
        ],
        'final',
    ]);

    $data = $response->json();
    expect($data['partials'])->toHaveCount(1);
    expect($data['partials'][0]['text'])->toBe('esto es una prueba');
});
