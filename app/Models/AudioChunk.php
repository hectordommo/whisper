<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioChunk extends Model
{
    protected $fillable = [
        'dictation_session_id',
        'filename',
        'start_time',
        'end_time',
        'uploaded_at',
    ];

    protected $casts = [
        'start_time' => 'float',
        'end_time' => 'float',
        'uploaded_at' => 'datetime',
    ];

    public function dictationSession(): BelongsTo
    {
        return $this->belongsTo(DictationSession::class);
    }

    public function getFullPathAttribute(): string
    {
        return storage_path('app/audio-chunks/' . $this->filename);
    }
}
