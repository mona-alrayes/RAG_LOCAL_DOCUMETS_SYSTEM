<?php

namespace App\Services\Admin;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Models\Document;
use App\Models\User;
use App\Rules\SecureDocumentUpload;
use App\Services\Documents\DocumentDeletionService;
use App\Services\Documents\DocumentProcessingDispatcher;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageDocuments
{
    public function upload(User $actor, int $ownerId, UploadedFile $file, ProcessingProfile $profile): Document
    {
        AdminAccess::authorize($actor);
        Validator::make(['document' => $file], ['document' => ['required', 'file', 'max:'.config('documents.upload.max_size_kilobytes', 10240), new SecureDocumentUpload]])->validate();

        return DB::transaction(function () use ($actor, $ownerId, $file, $profile) {
            $owner = User::query()->lockForUpdate()->findOrFail($ownerId);
            if ($owner->suspended_at !== null) {
                throw ValidationException::withMessages(['owner_id' => 'لا يمكن رفع وثيقة لحساب معطّل.']);
            }
            $document = app(DocumentUploadService::class)->store($owner, $file, $profile);
            AdminAudit::record($actor->id, 'document.upload', 'documents', $document->id);

            return $document;
        });
    }

    public function rename(User $actor, int $id, string $title): void
    {
        AdminAccess::authorize($actor);
        Validator::make(['title' => $title], ['title' => 'required|string|max:255'])->validate();
        DB::transaction(function () use ($actor, $id, $title) {
            $document = Document::query()->lockForUpdate()->findOrFail($id);
            $document->update(['title' => $title]);
            AdminAudit::record($actor->id, 'document.rename', 'documents', $id);
        });
    }

    public function reprocess(User $actor, int $id, ProcessingProfile $profile): void
    {
        AdminAccess::authorize($actor);
        DB::transaction(function () use ($actor, $id, $profile) {
            $document = Document::query()->lockForUpdate()->findOrFail($id);
            if ($document->status !== DocumentStatus::Ready) {
                throw ValidationException::withMessages(['processing_profile' => 'إعادة المعالجة تتطلب مستنداً آمناً وجاهزاً. استخدم إعادة المحاولة للمحاولة الفاشلة.']);
            }
            app(DocumentProcessingDispatcher::class)->dispatchReprocessing($document, $profile);
            AdminAudit::record($actor->id, 'document.reprocess', 'documents', $id);
        });
    }

    public function delete(User $actor, int $id): void
    {
        AdminAccess::authorize($actor);
        $document = Document::findOrFail($id);
        // Security scan jobs hold a document reference; let them finish before cleanup.
        if (in_array($document->status->value, ['pending', 'scanning'], true)) {
            throw ValidationException::withMessages(['delete' => 'انتظر انتهاء الفحص الأمني قبل حذف المستند.']);
        }
        try {
            app(DocumentDeletionService::class)->delete($document);
            AdminAudit::record($actor->id, 'document.delete', 'documents', $id);
        } catch (\Throwable $error) {
            AdminAudit::record($actor->id, 'document.delete', 'documents', $id, 'failed');
            throw $error;
        }
    }

    public function download(User $actor, int $id): StreamedResponse
    {
        AdminAccess::authorize($actor);
        $document = Document::findOrFail($id);
        abort_unless($document->status === DocumentStatus::Ready && Storage::disk('documents')->exists($document->file_path), 404);
        AdminAudit::record($actor->id, 'document.download', 'documents', $id);

        return Storage::disk('documents')->download($document->file_path, $document->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
