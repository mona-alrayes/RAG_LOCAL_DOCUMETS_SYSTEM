<?php

namespace App\Enums;

/**
 * Defines the supported document file types.
 *
 * أنواع الملفات المدعومة.
 */
enum FileType: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Txt = 'txt';
}
