<?php

namespace App\Jobs;

use App\Models\DictationSession;
use App\Services\ClaudeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FinalizeTranscript implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DictationSession $session
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ClaudeService $claude): void
    {
        try {
            // Gather all ASR partial transcripts
            $partials = $this->session->transcripts()
                ->where('type', 'asr_partial')
                ->orderBy('created_at')
                ->get();

            if ($partials->isEmpty()) {
                Log::warning('No partial transcripts to finalize', [
                    'session_id' => $this->session->id,
                ]);
                $this->session->update(['status' => 'ready']);
                return;
            }

            // Combine all partial transcripts
            $fullText = $partials->pluck('text')->implode(' ');

            // Gather all word-level metadata
            $allWords = [];
            foreach ($partials as $partial) {
                if (isset($partial->meta['words'])) {
                    $allWords = array_merge($allWords, $partial->meta['words']);
                }
            }

            $metadata = [
                'words' => $allWords,
                'partial_count' => $partials->count(),
            ];

            // Call Claude to polish the transcript
            $polished = $claude->polishTranscript($fullText, $metadata);

            // Store the final transcript
            $this->session->transcripts()->create([
                'type' => 'llm_final',
                'text' => $polished['text'],
                'meta' => [
                    'segments' => $polished['segments'] ?? [],
                    'uncertain_words' => $polished['uncertain_words'] ?? [],
                    'words' => $allWords, // Keep original ASR words for reference
                ],
            ]);

            $this->session->update(['status' => 'ready']);

            Log::info('Transcript finalized successfully', [
                'session_id' => $this->session->id,
                'original_length' => strlen($fullText),
                'polished_length' => strlen($polished['text']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to finalize transcript', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->session->update(['status' => 'recording']); // Reset status
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FinalizeTranscript job failed permanently', [
            'session_id' => $this->session->id,
            'error' => $exception->getMessage(),
        ]);

        $this->session->update(['status' => 'recording']);
    }
}
