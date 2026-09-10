<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Validates uploaded documents before they enter the processing pipeline.
 *
 * هذا الـRule مسؤول عن التحقق الأمني الأولي للملفات المرفوعة.
 *
 * يتحقق من:
 * - صحة عملية الرفع.
 * - أمان اسم الملف.
 * - الامتداد وMIME type.
 * - بنية PDF وDOCX وTXT.
 * - حماية DOCX من archive path traversal والملفات المضغوطة الضخمة.
 *
 * الهدف هو رفض الملفات غير المدعومة أو المشبوهة قبل تخزينها ومعالجتها.
 */
class SecureDocumentUpload implements ValidationRule
{
    /**
     * Validate the uploaded document.
     *
     * ينفذ سلسلة التحقق الرئيسية، ثم يستدعي validator مناسب
     * حسب نوع الملف: PDF أو DOCX أو TXT.
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(
                'documents.validation.secure_upload.invalid_file'
            )->translate();

            return;
        }

        $originalName = $value->getClientOriginalName();

        $originalPath = method_exists($value, 'getClientOriginalPath')
            ? $value->getClientOriginalPath()
            : $originalName;

        if (
            $originalPath !== $originalName
            || ! $this->hasSafeOriginalName($originalName)
        ) {
            $fail(
                'documents.validation.secure_upload.unsafe_filename'
            )->translate();

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $allowedTypes = config('documents.upload.types', []);
        $allowedMimeTypes = $allowedTypes[$extension] ?? null;

        if (! is_array($allowedMimeTypes)) {
            $fail(
                'documents.validation.secure_upload.unsupported_type'
            )->translate();

            return;
        }

        $mimeType = $value->getMimeType();

        if (
            ! is_string($mimeType)
            || ! in_array($mimeType, $allowedMimeTypes, true)
        ) {
            $fail(
                'documents.validation.secure_upload.content_mismatch'
            )->translate();

            return;
        }

        $path = $value->getRealPath();

        if (! is_string($path)) {
            $fail(
                'documents.validation.secure_upload.inspection_failed'
            )->translate();

            return;
        }

        $isValid = match ($extension) {
            'pdf' => $this->isValidPdf($path),
            'docx' => $this->isValidDocx($path),
            'txt' => $this->isValidText($path),
            default => false,
        };

        if (! $isValid) {
            $fail(
                'documents.validation.secure_upload.malformed_or_unsafe'
            )->translate();
        }
    }

    /**
     * Check whether the original filename is safe and supported.
     *
     * يمنع أسماء الملفات غير الصالحة أو التي تحتوي مسارات خطرة،
     * ويتأكد أن الامتداد موجود ضمن الأنواع المسموحة في config.
     */
    private function hasSafeOriginalName(string $originalName): bool
    {
        $maxLength = (int) config(
            'documents.upload.max_original_name_length',
            255,
        );

        if (
            $originalName === ''
            || ! mb_check_encoding($originalName, 'UTF-8')
            || mb_strlen($originalName, 'UTF-8') > $maxLength
        ) {
            return false;
        }

        $configuredTypes = config('documents.upload.types', []);

        if (! is_array($configuredTypes)) {
            return false;
        }

        $extensions = array_filter(
            array_keys($configuredTypes),
            static fn (mixed $extension): bool => is_string($extension),
        );

        if ($extensions === []) {
            return false;
        }

        $extensionPattern = implode(
            '|',
            array_map(
                static fn (string $extension): string => preg_quote(
                    $extension,
                    '/',
                ),
                $extensions,
            ),
        );

        $pattern = '/\A'
            .'[\p{L}\p{N}]'
            .'(?:[\p{L}\p{N} _-]*[\p{L}\p{N}_-])?'
            .'\.(?:'.$extensionPattern.')'
            .'\z/uiD';

        return preg_match($pattern, $originalName) === 1;
    }

    /**
     * Perform a basic structural validation for PDF files.
     *
     * يتحقق أن المحتوى يبدأ بتوقيع PDF ويحتوي علامة نهاية الملف.
     */
    private function isValidPdf(string $path): bool
    {
        $content = file_get_contents($path);

        if ($content === false) {
            return false;
        }

        return str_starts_with($content, '%PDF-')
            && str_contains($content, '%%EOF');
    }

    /**
     * Validate the basic structure and safety limits of a DOCX archive.
     *
     * بما أن DOCX هو ZIP archive، يتم التحقق من الملفات الأساسية داخله،
     * عدد entries، الحجم بعد فك الضغط، وعدم وجود مسارات خطرة.
     */
    private function isValidDocx(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            $maxEntries = (int) config(
                'documents.upload.docx.max_entries',
                1000,
            );

            $maxUncompressedBytes = (int) config(
                'documents.upload.docx.max_uncompressed_bytes',
                50 * 1024 * 1024,
            );

            if ($zip->numFiles > $maxEntries) {
                return false;
            }

            $requiredEntries = [
                '[Content_Types].xml',
                '_rels/.rels',
                'word/document.xml',
            ];

            foreach ($requiredEntries as $requiredEntry) {
                if ($zip->locateName($requiredEntry) === false) {
                    return false;
                }
            }

            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);

                if ($entry === false) {
                    return false;
                }

                $entryName = $entry['name'] ?? null;
                $entrySize = $entry['size'] ?? null;

                if (
                    ! is_string($entryName)
                    || ! is_int($entrySize)
                    || $this->hasUnsafeArchivePath($entryName)
                ) {
                    return false;
                }

                $totalUncompressedBytes += $entrySize;

                if ($totalUncompressedBytes > $maxUncompressedBytes) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }

    /**
     * Detect unsafe paths inside ZIP/DOCX archives.
     *
     * يمنع absolute paths وWindows drive paths واستخدام ".."
     * لتجنب archive path traversal.
     */
    private function hasUnsafeArchivePath(string $entryName): bool
    {
        return str_starts_with($entryName, '/')
            || str_starts_with($entryName, '\\')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/', $entryName) === 1
            || in_array(
                '..',
                preg_split('/[\\\\\/]+/', $entryName),
                true,
            );
    }

    /**
     * Validate TXT content and reject disguised binary documents.
     *
     * يتحقق أن الملف UTF-8 نصي ولا يحتوي null bytes،
     * كما يرفض الملفات التي تبدو PDF أو ZIP/DOCX متنكرة بامتداد TXT.
     */
    private function isValidText(string $path): bool
    {
        $content = file_get_contents($path);

        if (
            $content === false
            || str_contains($content, "\0")
            || ! mb_check_encoding($content, 'UTF-8')
        ) {
            return false;
        }

        $prefix = substr($content, 0, 1024);

        if (str_contains($prefix, '%PDF-')) {
            return false;
        }

        $zipSignatures = [
            "PK\x03\x04",
            "PK\x05\x06",
            "PK\x07\x08",
        ];

        foreach ($zipSignatures as $signature) {
            if (str_starts_with($content, $signature)) {
                return false;
            }
        }

        return true;
    }
}
