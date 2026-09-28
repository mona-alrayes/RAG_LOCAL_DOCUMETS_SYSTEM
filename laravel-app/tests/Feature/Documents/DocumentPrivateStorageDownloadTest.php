<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentPrivateStorageDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_private_stored_document(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $content = "Private UTF-8 document content.\n";
        $storedName = Str::ulid().'.txt';
        $filePath = $user->id.'/'.$storedName;

        Storage::disk('documents')->put(
            $filePath,
            $content,
        );

        $document = $user->documents()->create([
            'original_name' => 'notes.txt',
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        $this->actingAs($user)
            ->get("/documents/{$document->id}/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('notes.txt');
    }

    public function test_other_user_cannot_download_or_directly_access_private_document(): void
    {
        Storage::fake('documents');
        Storage::fake('public');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $storedName = Str::ulid().'.txt';
        $filePath = $owner->id.'/'.$storedName;

        Storage::disk('documents')->put(
            $filePath,
            'private document',
        );

        $document = $owner->documents()->create([
            'original_name' => 'private.txt',
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen('private document'),
            'sha256' => hash('sha256', 'private document'),
        ]);

        $this->actingAs($otherUser)
            ->get("/documents/{$document->id}/download")
            ->assertForbidden();

        $directAccess = $this->get(
            '/storage/'.$document->file_path,
        );

        $this->assertContains(
            $directAccess->status(),
            [403, 404],
        );

        Storage::disk('public')
            ->assertMissing($document->file_path);

        Storage::disk('documents')
            ->assertExists($document->file_path);
    }

    public function test_owner_can_preview_private_pdf_document_inline(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $content = "%PDF-1.4\nFake PDF content\n";
        $storedName = Str::ulid().'.pdf';
        $filePath = $user->id.'/'.$storedName;

        Storage::disk('documents')->put(
            $filePath,
            $content,
        );

        $document = $user->documents()->create([
            'original_name' => 'report.pdf',
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        $this->actingAs($user)
            ->get("/documents/{$document->id}/preview")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader(
                'Content-Disposition',
                'inline; filename=report.pdf',
            );
    }

    public function test_owner_can_preview_private_txt_document_inline(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $content = "Private UTF-8 text document.\n";
        $storedName = Str::ulid().'.txt';
        $filePath = $user->id.'/'.$storedName;

        Storage::disk('documents')->put(
            $filePath,
            $content,
        );

        $document = $user->documents()->create([
            'original_name' => 'notes.txt',
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        $this->actingAs($user)
            ->get("/documents/{$document->id}/preview")
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'text/plain; charset=UTF-8',
            )
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_docx_document_cannot_be_previewed(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $content = 'fake docx content';
        $storedName = Str::ulid().'.docx';
        $filePath = $user->id.'/'.$storedName;

        Storage::disk('documents')->put(
            $filePath,
            $content,
        );

        $document = $user->documents()->create([
            'original_name' => 'document.docx',
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_type' => 'docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        $this->actingAs($user)
            ->get("/documents/{$document->id}/preview")
            ->assertNotFound();
    }

    public function test_other_user_cannot_preview_private_document(): void
    {
        Storage::fake('documents');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $content = "private content\n";
        $storedName = Str::ulid().'.txt';
        $filePath = $owner->id.'/'.$storedName;

        Storage::disk('documents')->put(
            $filePath,
            $content,
        );

        $document = $owner->documents()->create([
            'original_name' => 'private.txt',
            'stored_name' => $storedName,
            'file_path' => $filePath,
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        $this->actingAs($otherUser)
            ->get("/documents/{$document->id}/preview")
            ->assertForbidden();
    }
}
