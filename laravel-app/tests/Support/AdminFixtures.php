<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait AdminFixtures
{
    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    protected function document(string $status = 'ready'): Document
    {
        Storage::fake('documents');
        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent('notes.txt', 'Example document content.'),
        );
        $document->forceFill(['status' => $status])->save();

        return $document;
    }
}
