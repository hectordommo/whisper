<?php

namespace App\Jobs;

use App\Models\AudioChunk;
use App\Services\WhisperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAudioChunk implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [10, 30, 60]; // Exponential backoff in seconds

    /**
     * Create a new job instance.
     */
    public function __construct(
        public AudioChunk $chunk
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhisperService $whisper): void
    {
        try {
            $audioPath = $this->chunk->full_path;

            if (!file_exists($audioPath)) {
                Log::error('Audio chunk file not found', [
                    'chunk_id' => $this->chunk->id,
                    'path' => $audioPath,
                ]);
                return;
            }

            // Transcribe the audio chunk
            $result = $whisper->transcribeChunk($audioPath, 'es');

            // Store the partial transcript
            $this->chunk->dictationSession->transcripts()->create([
                'type' => 'asr_partial',
                'text' => $result['text'],
                'meta' => [
                    'words' => $result['words'],
                    'language' => $result['language'],
                    'duration' => $result['duration'],
                    'chunk_id' => $this->chunk->id,
                    'start_time' => $this->chunk->start_time,
                    'end_time' => $this->chunk->end_time,
                ],
            ]);

            Log::info('Audio chunk processed successfully', [
                'chunk_id' => $this->chunk->id,
                'session_id' => $this->chunk->dictation_session_id,
                'word_count' => count($result['words']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process audio chunk', [
                'chunk_id' => $this->chunk->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAudioChunk job failed permanently', [
            'chunk_id' => $this->chunk->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
