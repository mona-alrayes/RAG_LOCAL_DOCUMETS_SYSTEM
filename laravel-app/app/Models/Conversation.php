<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a conversation owned by a user.
 *
 * يمثل محادثة يملكها مستخدم.
 */
#[Fillable([
    'title',
])]
class Conversation extends Model
{
    /**
     * Get the user who owns the conversation.
     *
     * المستخدم المالك للمحادثة.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the documents attached to the conversation.
     *
     * الوثائق المرتبطة بالمحادثة.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class);
    }

    /**
     * Get the messages that belong to the conversation.
     *
     * الرسائل التابعة للمحادثة.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
