<?php

namespace Tests\Feature\Conversations;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConversationDocumentPivotTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_document_pivot_has_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('conversation_document'));

        $this->assertTrue(Schema::hasColumns('conversation_document', [
            'conversation_id',
            'document_id',
        ]));
    }

    public function test_conversation_can_have_multiple_documents_and_relationships_work_both_ways(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة',
        ]);

        $firstDocument = $this->createDocument($user, 'first.txt');
        $secondDocument = $this->createDocument($user, 'second.txt');

        $conversation->documents()->attach([
            $firstDocument->id,
            $secondDocument->id,
        ]);

        $this->assertCount(2, $conversation->fresh()->documents);
        $this->assertTrue($conversation->fresh()->documents->contains($firstDocument));
        $this->assertTrue($conversation->fresh()->documents->contains($secondDocument));

        $this->assertTrue($firstDocument->fresh()->conversations->contains($conversation));
        $this->assertTrue($secondDocument->fresh()->conversations->contains($conversation));
    }

    public function test_document_can_belong_to_multiple_conversations(): void
    {
        $user = User::factory()->create();

        $firstConversation = $user->conversations()->create([
            'title' => 'الأولى',
        ]);

        $secondConversation = $user->conversations()->create([
            'title' => 'الثانية',
        ]);

        $document = $this->createDocument($user, 'shared.txt');

        $firstConversation->documents()->attach($document);
        $secondConversation->documents()->attach($document);

        $this->assertCount(2, $document->fresh()->conversations);
        $this->assertTrue($document->fresh()->conversations->contains($firstConversation));
        $this->assertTrue($document->fresh()->conversations->contains($secondConversation));
    }

    public function test_duplicate_conversation_document_pair_is_rejected(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة',
        ]);

        $document = $this->createDocument($user, 'duplicate.txt');

        $conversation->documents()->attach($document);

        $this->expectException(QueryException::class);

        $conversation->documents()->attach($document);
    }

    private function createDocument(User $user, string $name): Document
    {
        return $user->documents()->create([
            'original_name' => $name,
            'stored_name' => uniqid('document_', true).'.txt',
            'title' => $name,
            'file_path' => 'documents/'.$name,
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'sha256' => hash('sha256', $name),
        ]);
    }
}
