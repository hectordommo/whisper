<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcript extends Model
{
    protected $fillable = [
        'dictation_session_id',
        'type',
        'text',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function dictationSession(): BelongsTo
    {
        return $this->belongsTo(DictationSession::class);
    }

    public function getUncertainWordsAttribute(): array
    {
        if (!isset($this->meta['words'])) {
            return [];
        }

        return array_filter($this->meta['words'], function ($word) {
            return isset($word['confidence']) && $word['confidence'] < 0.7;
        });
    }
}
