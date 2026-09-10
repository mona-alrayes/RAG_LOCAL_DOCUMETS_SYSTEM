<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'role',
    'status',
    'content',
    'execution_snapshot',
    'metrics',
])]
class Message extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'status' => MessageStatus::class,
            'execution_snapshot' => 'array',
            'metrics' => 'array',
        ];
    }

    /**
     * Get the conversation that owns this message.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the sources used for this message.
     */
    public function sources(): HasMany
    {
        return $this->hasMany(MessageSource::class);
    }
}
