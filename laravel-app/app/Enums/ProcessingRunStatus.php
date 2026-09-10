<?php

namespace App\Enums;

/**
 * Defines the lifecycle statuses of a document processing run.
 *
 * حالات دورة حياة محاولة معالجة الوثيقة.
 */
enum ProcessingRunStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
