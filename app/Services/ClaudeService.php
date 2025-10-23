<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.anthropic.com/v1';
    protected string $model = 'claude-3-5-sonnet-20241022';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.anthropic.api_key');
    }

    /**
     * Polish a transcript using Claude
     *
     * @param string $text Raw ASR transcript
     * @param array $metadata Word-level metadata from ASR
     * @return array Polished transcript with uncertainty markers
     */
    public function polishTranscript(string $text, array $metadata = []): array
    {
        $systemPrompt = $this->getSpanishMexicoPrompt();
        $userMessage = $this->formatUserMessage($text, $metadata);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($this->baseUrl . '/messages', [
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $userMessage,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Claude API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Claude API request failed: ' . $response->body());
            }

            $data = $response->json();

            return $this->parseClaudeResponse($data);
        } catch (\Exception $e) {
            Log::error('Claude polishing error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the system prompt for Spanish (Mexico) transcription editing
     */
    protected function getSpanishMexicoPrompt(): string
    {
        return <<<'PROMPT'
Eres un editor de transcripciones en español (español de México).

Entrada: transcripción automática con datos por palabra (confianza, alternativas cuando existan).

Tarea:
- Añadir puntuación y tildes correctamente
- Mantener el registro de voz hablado (no reescribir a estilo formal)
- Quitar o marcar muletillas si la confianza es baja (ej: "eh", "este", "pues")
- Si el hablante empezó una frase y se cortó por cambio de idea, reordena ligeramente para que el texto fluya, pero NO inventes información
- Para palabras con baja confianza (<0.7) o con alternativas, márcalas en tu respuesta

Salida: JSON con campos:
- text: texto final editado
- segments: array de segmentos con start, end, text
- uncertain_words: array de palabras inciertas con sus alternativas

Ejemplo de salida:
{
  "text": "Hola, yo quería decirte que mañana vamos a la reunión.",
  "segments": [
    {"start": 0.0, "end": 5.3, "text": "Hola, yo quería decirte que mañana vamos a la reunión."}
  ],
  "uncertain_words": [
    {"word": "decirte", "position": 3, "alternatives": ["decirle"], "confidence": 0.6}
  ]
}
PROMPT;
    }

    /**
     * Format the user message with transcript and metadata
     */
    protected function formatUserMessage(string $text, array $metadata): string
    {
        $message = "Transcripción automática:\n\n";
        $message .= $text . "\n\n";

        if (!empty($metadata['words'])) {
            $message .= "Metadatos de palabras:\n";
            $message .= json_encode($metadata['words'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return $message;
    }

    /**
     * Parse Claude's response
     */
    protected function parseClaudeResponse(array $data): array
    {
        $content = $data['content'][0]['text'] ?? '';

        // Try to extract JSON from the response
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            try {
                $parsed = json_decode($matches[0], true);
                if ($parsed) {
                    return $parsed;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to parse Claude JSON response', ['content' => $content]);
            }
        }

        // Fallback: return raw text
        return [
            'text' => $content,
            'segments' => [],
            'uncertain_words' => [],
        ];
    }

    /**
     * Check if the API key is valid
     */
    public function validateApiKey(): bool
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->get($this->baseUrl . '/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
