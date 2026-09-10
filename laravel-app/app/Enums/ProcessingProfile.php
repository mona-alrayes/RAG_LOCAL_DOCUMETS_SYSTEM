<?php

namespace App\Enums;

/**
 * Defines the available document processing profiles.
 *
 * مسارات معالجة الوثائق المتاحة.
 */
enum ProcessingProfile: string
{
    case Cloud = 'cloud';
    case HybridLocal = 'hybrid_local';
}
