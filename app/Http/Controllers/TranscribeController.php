<?php

namespace App\Http\Controllers;

use App\Jobs\FinalizeTranscript;
use App\Jobs\ProcessAudioChunk;
use App\Models\AudioChunk;
use App\Models\DictationSession;
use App\Models\Transcript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TranscribeController extends Controller
{
    /**
     * Display the session list page
     */
    public function index(): Response
    {
        $sessions = auth()->user()->dictationSessions()
            ->with(['transcripts' => function ($query) {
                $query->latest()->take(1);
            }])
            ->latest()
            ->get()
            ->map(function ($session) {
                return [
                    'id' => $session->id,
                    'title' => $session->title ?? 'Untitled Session',
                    'status' => $session->status,
                    'created_at' => $session->created_at,
                    'preview' => $session->latestTranscript()
                        ? Str::limit($session->latestTranscript()->text, 100)
                        : '',
                ];
            });

        return Inertia::render('transcribe/index', [
            'sessions' => $sessions,
        ]);
    }

    /**
     * Display the recording page
     */
    public function create(): Response
    {
        return Inertia::render('transcribe/record');
    }

    /**
     * Create a new dictation session
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $session = auth()->user()->dictationSessions()->create([
            'title' => $validated['title'] ?? 'Untitled Session',
            'status' => 'recording',
        ]);

        return response()->json([
            'session_id' => $session->id,
            'status' => 'created',
        ], 201);
    }

    /**
     * Show a specific session
     */
    public function show(DictationSession $session): Response
    {
        $this->authorize('view', $session);

        $session->load(['transcripts' => function ($query) {
            $query->latest();
        }]);

        $latestTranscript = $session->latestTranscript();

        return Inertia::render('transcribe/show', [
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'status' => $session->status,
                'created_at' => $session->created_at,
                'transcript' => $latestTranscript ? [
                    'text' => $latestTranscript->text,
                    'type' => $latestTranscript->type,
                    'meta' => $latestTranscript->meta,
                    'uncertain_words' => $latestTranscript->uncertain_words,
                ] : null,
            ],
        ]);
    }

    /**
     * Upload an audio chunk
     */
    public function uploadChunk(Request $request, DictationSession $session): JsonResponse
    {
        $this->authorize('update', $session);

        $validated = $request->validate([
            'file' => 'required|file|mimes:webm,ogg,mp3,wav,m4a|max:10240', // 10MB max
            'start_time' => 'required|numeric|min:0',
            'end_time' => 'required|numeric|min:0',
        ]);

        $file = $request->file('file');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->storeAs('audio-chunks', $filename);

        $chunk = $session->audioChunks()->create([
            'filename' => $filename,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'uploaded_at' => now(),
        ]);

        // Dispatch job to process this chunk
        ProcessAudioChunk::dispatch($chunk);

        return response()->json([
            'chunk_id' => $chunk->id,
            'status' => 'processing',
        ]);
    }

    /**
     * Get the latest transcript for a session
     */
    public function getTranscript(DictationSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $transcripts = $session->transcripts()
            ->where('type', 'asr_partial')
            ->latest()
            ->get();

        $latestFinal = $session->transcripts()
            ->where('type', 'llm_final')
            ->latest()
            ->first();

        return response()->json([
            'partials' => $transcripts->map(fn($t) => [
                'id' => $t->id,
                'text' => $t->text,
                'meta' => $t->meta,
                'created_at' => $t->created_at,
            ]),
            'final' => $latestFinal ? [
                'text' => $latestFinal->text,
                'meta' => $latestFinal->meta,
                'uncertain_words' => $latestFinal->uncertain_words,
            ] : null,
        ]);
    }

    /**
     * Request finalization (LLM polish) of the session
     */
    public function finalize(DictationSession $session): JsonResponse
    {
        $this->authorize('update', $session);

        $session->update(['status' => 'processing']);

        FinalizeTranscript::dispatch($session);

        return response()->json([
            'status' => 'queued',
            'message' => 'Transcript finalization in progress',
        ]);
    }

    /**
     * Accept a word alternative
     */
    public function acceptWord(Request $request, DictationSession $session): JsonResponse
    {
        $this->authorize('update', $session);

        $validated = $request->validate([
            'transcript_id' => 'required|exists:transcripts,id',
            'word_index' => 'required|integer|min:0',
            'accepted_text' => 'required|string',
        ]);

        $transcript = Transcript::findOrFail($validated['transcript_id']);

        $meta = $transcript->meta ?? [];
        if (isset($meta['words'][$validated['word_index']])) {
            $meta['words'][$validated['word_index']]['text'] = $validated['accepted_text'];
            $meta['words'][$validated['word_index']]['user_edited'] = true;
        }

        $transcript->update(['meta' => $meta]);

        // Rebuild the text from words
        $newText = collect($meta['words'])->pluck('text')->implode(' ');
        $transcript->update(['text' => $newText]);

        return response()->json([
            'status' => 'updated',
            'transcript' => [
                'text' => $transcript->text,
                'meta' => $transcript->meta,
            ],
        ]);
    }

    /**
     * Delete a session
     */
    public function destroy(DictationSession $session): JsonResponse
    {
        $this->authorize('delete', $session);

        // Delete all audio chunks
        foreach ($session->audioChunks as $chunk) {
            Storage::delete('audio-chunks/' . $chunk->filename);
        }

        $session->delete();

        return response()->json([
            'status' => 'deleted',
        ]);
    }
}
