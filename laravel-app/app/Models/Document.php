<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Represents a document owned by a user.
 *
 * يمثل وثيقة يملكها مستخدم.
 */
#[Fillable([
    'original_name',
    'stored_name',
    'title',
    'file_path',
    'file_type',
    'mime_type',
    'file_size',
    'sha256',
])]
class Document extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * تحويل نوع الملف وحالة الوثيقة.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_type' => FileType::class,
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * Get the user who owns the document.
     *
     * المستخدم المالك للوثيقة.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the conversations using this document.
     *
     * المحادثات المرتبطة بهذه الوثيقة.
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class);
    }

    /**
     * Get the processing runs created for this document.
     *
     * محاولات المعالجة التابعة للوثيقة.
     */
    public function processingRuns(): HasMany
    {
        return $this->hasMany(ProcessingRun::class);
    }

    /**
     * Get the currently active indexed processing run for this document.
     */
    public function activeProcessingRun(): BelongsTo
    {
        return $this->belongsTo(
            ProcessingRun::class,
            'active_processing_run_id',
        );
    }

    /**
     * Get the latest processing attempt for this document.
     */
    public function latestAttempt(): HasOne
    {
        return $this->hasOne(ProcessingRun::class)->latestOfMany();
    }
}
