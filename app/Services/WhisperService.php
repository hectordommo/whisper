<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhisperService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.openai.api_key');
    }

    /**
     * Transcribe an audio chunk using OpenAI Whisper API
     *
     * @param string $audioPath Path to the audio file
     * @param string $language Language code (default: 'es' for Spanish)
     * @return array Transcription result with word-level timestamps and confidence
     */
    public function transcribeChunk(string $audioPath, string $language = 'es'): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->attach(
                'file',
                file_get_contents($audioPath),
                basename($audioPath)
            )->post($this->baseUrl . '/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => $language,
                'response_format' => 'verbose_json', // Get word-level timestamps
                'timestamp_granularities' => ['word'],
            ]);

            if (!$response->successful()) {
                Log::error('Whisper API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Whisper API request failed: ' . $response->body());
            }

            $data = $response->json();

            return $this->formatTranscriptionResponse($data);
        } catch (\Exception $e) {
            Log::error('Whisper transcription error', [
                'error' => $e->getMessage(),
                'file' => $audioPath,
            ]);
            throw $e;
        }
    }

    /**
     * Format the Whisper API response to our standard format
     */
    protected function formatTranscriptionResponse(array $data): array
    {
        $words = [];

        // OpenAI Whisper returns words with timestamps
        if (isset($data['words'])) {
            foreach ($data['words'] as $word) {
                $words[] = [
                    'text' => $word['word'] ?? '',
                    'start' => $word['start'] ?? 0,
                    'end' => $word['end'] ?? 0,
                    // Whisper doesn't provide confidence scores directly,
                    // we'll estimate based on context or set default
                    'confidence' => 0.9, // Default high confidence
                ];
            }
        }

        return [
            'text' => $data['text'] ?? '',
            'language' => $data['language'] ?? 'es',
            'duration' => $data['duration'] ?? 0,
            'words' => $words,
        ];
    }

    /**
     * Check if the API key is valid
     */
    public function validateApiKey(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get($this->baseUrl . '/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
