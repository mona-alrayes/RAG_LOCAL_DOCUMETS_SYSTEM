<?php

namespace App\Services\Documents;

use App\Enums\FileType;
use App\Exceptions\DuplicateDocumentException;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DocumentStorageService
{
    private const DOCUMENTS_DISK = 'documents';

    private const QUARANTINE_DISK = 'document_quarantine';

    public function storePermanent(User $user, UploadedFile $file): Document
    {
        return $this->storeOnDisk(
            $user,
            $file,
            self::DOCUMENTS_DISK,
        );
    }

    public function storeQuarantined(
        User $user,
        UploadedFile $file,
    ): Document {
        return $this->storeOnDisk(
            $user,
            $file,
            self::QUARANTINE_DISK,
        );
    }

    private function storeOnDisk(
        User $user,
        UploadedFile $file,
        string $diskName,
    ): Document {
        $fileType = FileType::from(
            strtolower($file->getClientOriginalExtension()),
        );

        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        if (! is_string($mimeType) || ! is_int($fileSize)) {
            throw new RuntimeException(
                'Unable to determine trusted document metadata.',
            );
        }

        $disk = Storage::disk($diskName);

        do {
            $storedName = Str::ulid().'.'.$fileType->value;
            $filePath = $user->id.'/'.$storedName;
        } while ($disk->exists($filePath));

        $storedPath = $disk->putFileAs(
            (string) $user->id,
            $file,
            $storedName,
        );

        if (! is_string($storedPath)) {
            throw new RuntimeException('Unable to store document.');
        }

        try {
            $stream = $disk->readStream($storedPath);

            if (! is_resource($stream)) {
                throw new RuntimeException(
                    'Unable to read stored document.',
                );
            }

            try {
                $hashContext = hash_init('sha256');
                hash_update_stream($hashContext, $stream);
                $sha256 = hash_final($hashContext);
            } finally {
                fclose($stream);
            }

            $duplicate = $user->documents()
                ->where('sha256', $sha256)
                ->first();

            if ($duplicate instanceof Document) {
                throw new DuplicateDocumentException($duplicate);
            }

            return $user->documents()->create([
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'file_path' => $storedPath,
                'file_type' => $fileType,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'sha256' => $sha256,
            ]);
        } catch (Throwable $exception) {
            $disk->delete($storedPath);

            throw $exception;
        }
    }

    public function promoteQuarantined(Document $document): void
    {
        $source = Storage::disk(self::QUARANTINE_DISK);
        $destination = Storage::disk(self::DOCUMENTS_DISK);
        $path = $document->file_path;

        if (! $source->exists($path)) {
            throw new RuntimeException(
                'Quarantined document file does not exist.',
            );
        }

        if ($destination->exists($path)) {
            throw new RuntimeException(
                'Permanent document file already exists.',
            );
        }

        $stream = $source->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException(
                'Unable to read quarantined document.',
            );
        }

        try {
            $written = $destination->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if ($written !== true) {
            try {
                $destination->delete($path);
            } catch (Throwable) {
                // Best-effort cleanup only; the quarantine source is still intact.
            }

            throw new RuntimeException(
                'Unable to promote quarantined document.',
            );
        }

        try {
            $deleted = $source->delete($path);

            if ($deleted !== true) {
                throw new RuntimeException(
                    'Unable to remove quarantined document after promotion.',
                );
            }
        } catch (Throwable $exception) {
            try {
                $destination->delete($path);
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }

    public function delete(Document $document): void
    {
        foreach (
            [
                self::DOCUMENTS_DISK,
                self::QUARANTINE_DISK,
            ] as $diskName
        ) {
            $disk = Storage::disk($diskName);

            if (! $disk->exists($document->file_path)) {
                continue;
            }

            $deleted = $disk->delete($document->file_path);

            if ($deleted !== true) {
                throw new RuntimeException(
                    'Unable to delete document file from private storage.',
                );
            }
        }
    }
}
