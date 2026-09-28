<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MessagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_table_has_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('messages'));

        $this->assertTrue(Schema::hasColumns('messages', [
            'id',
            'conversation_id',
            'role',
            'status',
            'content',
            'execution_snapshot',
            'metrics',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_message_sources_table_has_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('message_sources'));

        $this->assertTrue(Schema::hasColumns('message_sources', [
            'id',
            'message_id',
            'processing_run_id',
            'qdrant_point_id',
            'chunk_index',
            'source_snapshot',
            'relevance_score',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_conversation_can_store_messages_with_snapshots_and_metrics(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة تجريبية',
        ]);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => 'هذه إجابة تجريبية.',
            'execution_snapshot' => [
                'document_ids' => [10, 20],
            ],
            'metrics' => [
                'query_embedding' => 20,
                'retrieval' => 120,
                'fusion' => 15,
                'reranking' => 80,
                'context_building' => 10,
                'generation' => 900,
                'total' => 1145,
            ],
        ]);

        $message = $message->fresh();

        $this->assertInstanceOf(Message::class, $message);
        $this->assertTrue($message->conversation->is($conversation));
        $this->assertTrue($conversation->fresh()->messages->contains($message));

        $this->assertSame(MessageRole::Assistant, $message->role);
        $this->assertSame(MessageStatus::Completed, $message->status);

        $this->assertSame(
            ['document_ids' => [10, 20]],
            $message->execution_snapshot,
        );

        $this->assertSame(120, $message->metrics['retrieval']);
        $this->assertSame(1145, $message->metrics['total']);
    }

    public function test_message_can_store_source_provenance_and_relevance_score(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة',
        ]);

        $document = $this->createDocument($user);

        $processingRun = $document->processingRuns()->create([
            'profile' => 'cloud',
            'status' => 'indexed',
            'profile_snapshot' => [
                'profile' => 'cloud',
            ],
            'stage_timings_ms' => [],
            'warnings' => [],
            'qdrant_collection' => 'rag_documents_cloud',
            'indexed_at' => now(),
        ]);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => 'الإجابة.',
        ]);

        $source = $message->sources()->create([
            'processing_run_id' => $processingRun->id,
            'qdrant_point_id' => 'bf9f6557-d595-599b-8704-6a115fd102c3',
            'chunk_index' => 17,
            'source_snapshot' => [
                'source' => $document->original_name,
                'page' => 5,
                'section' => 'القسم الأول',
                'excerpt' => 'مقتطف المصدر المستخدم في الإجابة.',
            ],
            'relevance_score' => 0.94,
        ]);

        $source = $source->fresh();

        $this->assertInstanceOf(MessageSource::class, $source);
        $this->assertTrue($source->message->is($message));
        $this->assertTrue($source->processingRun->is($processingRun));

        $this->assertSame(
            $document->id,
            $source->processingRun->document_id,
        );

        $this->assertSame(
            'cloud',
            $source->processingRun->profile->value,
        );

        $this->assertSame(17, $source->chunk_index);
        $this->assertSame(0.94, $source->relevance_score);

        $this->assertSame(
            'مقتطف المصدر المستخدم في الإجابة.',
            $source->source_snapshot['excerpt'],
        );
    }

    public function test_pending_message_can_exist_without_content(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة',
        ]);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
        ]);

        $message = $message->fresh();

        $this->assertSame(MessageStatus::Pending, $message->status);
        $this->assertNull($message->content);
    }

    public function test_deleting_conversation_cascades_messages_and_sources(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة',
        ]);

        $document = $this->createDocument($user);

        $processingRun = $document->processingRuns()->create([
            'profile' => 'cloud',
            'status' => 'indexed',
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => 'الإجابة.',
        ]);

        $source = $message->sources()->create([
            'processing_run_id' => $processingRun->id,
            'qdrant_point_id' => 'bf9f6557-d595-599b-8704-6a115fd102c4',
            'chunk_index' => 0,
            'source_snapshot' => [
                'source' => $document->original_name,
                'excerpt' => 'المصدر.',
            ],
            'relevance_score' => 0.90,
        ]);

        $conversation->delete();

        $this->assertDatabaseMissing('messages', [
            'id' => $message->id,
        ]);

        $this->assertDatabaseMissing('message_sources', [
            'id' => $source->id,
        ]);
    }

    public function test_deleting_processing_run_cascades_sources_but_keeps_message(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة',
        ]);

        $document = $this->createDocument($user);

        $processingRun = $document->processingRuns()->create([
            'profile' => 'cloud',
            'status' => 'indexed',
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => 'الإجابة.',
        ]);

        $source = $message->sources()->create([
            'processing_run_id' => $processingRun->id,
            'qdrant_point_id' => 'bf9f6557-d595-599b-8704-6a115fd102c5',
            'chunk_index' => 0,
            'source_snapshot' => [
                'source' => $document->original_name,
                'excerpt' => 'المصدر.',
            ],
            'relevance_score' => 0.91,
        ]);

        $processingRun->delete();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
        ]);

        $this->assertDatabaseMissing('message_sources', [
            'id' => $source->id,
        ]);
    }

    private function createDocument(User $user): Document
    {
        return $user->documents()->create([
            'original_name' => 'source.txt',
            'stored_name' => uniqid('document_', true).'.txt',
            'title' => 'Source',
            'file_path' => 'documents/source.txt',
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'sha256' => hash('sha256', uniqid('source_', true)),
        ]);
    }
}
