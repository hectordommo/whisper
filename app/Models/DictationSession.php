<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DictationSession extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'title',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audioChunks(): HasMany
    {
        return $this->hasMany(AudioChunk::class);
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(Transcript::class);
    }

    public function latestTranscript(): ?Transcript
    {
        return $this->transcripts()
            ->where('type', 'llm_final')
            ->latest()
            ->first() ?? $this->transcripts()
            ->where('type', 'asr_final')
            ->latest()
            ->first();
    }
}
