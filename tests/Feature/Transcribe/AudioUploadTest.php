<?php

use App\Models\DictationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('user can upload audio chunk to session', function () {
    Queue::fake();

    // Ensure audio-chunks directory exists
    $audioChunksPath = storage_path('app/audio-chunks');
    if (!is_dir($audioChunksPath)) {
        mkdir($audioChunksPath, 0755, true);
    }

    $user = User::factory()->create();
    $session = DictationSession::factory()->create(['user_id' => $user->id]);

    // Use the real test audio file
    $audioPath = base_path('tests/Fixtures/sample-audio.webm');
    $file = new UploadedFile($audioPath, 'sample-audio.webm', 'audio/webm', null, true);

    $response = $this->actingAs($user)->postJson("/transcribe/sessions/{$session->id}/chunks", [
        'file' => $file,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['chunk_id', 'status']);

    // Verify file was stored
    $this->assertDatabaseHas('audio_chunks', [
        'dictation_session_id' => $session->id,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $chunk = $session->audioChunks()->first();
    expect($chunk)->not->toBeNull();

    // Verify file exists in storage
    $filePath = storage_path('app/audio-chunks/' . $chunk->filename);
    expect(file_exists($filePath))->toBeTrue();

    // Cleanup
    @unlink($filePath);
});

test('upload fails with invalid file type', function () {
    $user = User::factory()->create();
    $session = DictationSession::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->actingAs($user)->postJson("/transcribe/sessions/{$session->id}/chunks", [
        'file' => $file,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);
});

test('upload fails for file that is too large', function () {
    $user = User::factory()->create();
    $session = DictationSession::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('huge-audio.webm', 11000); // 11MB, over limit

    $response = $this->actingAs($user)->postJson("/transcribe/sessions/{$session->id}/chunks", [
        'file' => $file,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);
});

test('user cannot upload to another users session', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $session = DictationSession::factory()->create(['user_id' => $otherUser->id]);

    $file = UploadedFile::fake()->create('audio.webm', 100, 'audio/webm');

    $response = $this->actingAs($user)->postJson("/transcribe/sessions/{$session->id}/chunks", [
        'file' => $file,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $response->assertStatus(403);
});

test('upload requires authentication', function () {
    $session = DictationSession::factory()->create();
    $file = UploadedFile::fake()->create('audio.webm', 100, 'audio/webm');

    $response = $this->postJson("/transcribe/sessions/{$session->id}/chunks", [
        'file' => $file,
        'start_time' => 0,
        'end_time' => 10,
    ]);

    $response->assertStatus(401);
});
