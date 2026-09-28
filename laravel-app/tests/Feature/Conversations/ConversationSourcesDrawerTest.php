<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\ProcessingRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationSourcesDrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_multiple_sources_and_non_null_reranker_score(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة المصادر',
        ]);

        $firstDocument = $this->createDocument(
            $user,
            'first-source.pdf',
            'الوثيقة الأولى',
        );

        $secondDocument = $this->createDocument(
            $user,
            'second-source.pdf',
            'الوثيقة الثانية',
        );

        $message = $this->createAssistantMessage(
            $conversation,
            'إجابة تعتمد على أكثر من مصدر.',
        );

        $message->sources()->create([
            'processing_run_id' => $this
                ->createProcessingRun($firstDocument)
                ->id,
            'qdrant_point_id' => 'source-point-one',
            'chunk_index' => 4,
            'source_snapshot' => [
                'source' => 'first-source.pdf',
                'page' => 3,
                'section' => 'القسم الأول',
                'excerpt' => 'المقتطف الأول من الوثيقة.',
            ],
            'relevance_score' => null,
        ]);

        $message->sources()->create([
            'processing_run_id' => $this
                ->createProcessingRun($secondDocument)
                ->id,
            'qdrant_point_id' => 'source-point-two',
            'chunk_index' => 8,
            'source_snapshot' => [
                'source' => 'second-source.pdf',
                'page' => 9,
                'section' => 'القسم الثاني',
                'excerpt' => 'المقتطف الثاني من الوثيقة.',
            ],
            'relevance_score' => null,
            'reranker_score' => 0.87,
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('إجابة تعتمد على أكثر من مصدر.')
            ->assertSee('عرض المصادر')
            ->assertSee('الوثيقة الأولى')
            ->assertSee('الوثيقة الثانية')
            ->assertSee('الصفحة')
            ->assertSee('القسم الأول')
            ->assertSee('المقتطف الأول من الوثيقة.')
            ->assertSee('المقتطف الثاني من الوثيقة.')
            ->assertSee('درجة إعادة الترتيب')
            ->assertSee('0.87')
            ->assertSee('data-message-source-card', false)
            ->assertSee('data-source-reranker-score', false)
            ->assertDontSee('درجة الصلة')
            ->assertDontSee('data-source-relevance', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee(
                'aria-controls="conversation-sources-dialog-'.$message->id.'"',
                false,
            )
            ->assertSee(
                'aria-labelledby="conversation-sources-title-'.$message->id.'"',
                false,
            );
    }

    public function test_nullable_metadata_and_null_reranker_do_not_render_retrieval_score_as_relevance(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة nullable',
        ]);

        $document = $this->createDocument(
            $user,
            'nullable-source.txt',
            null,
        );

        $message = $this->createAssistantMessage(
            $conversation,
            'إجابة بمصدر بدون صفحة أو قسم.',
        );

        $message->sources()->create([
            'processing_run_id' => $this
                ->createProcessingRun($document)
                ->id,
            'qdrant_point_id' => 'nullable-source-point',
            'chunk_index' => 0,
            'source_snapshot' => [
                'source' => 'nullable-source.txt',
                'page' => null,
                'section' => null,
                'excerpt' => 'مقتطف بدون بيانات اختيارية.',
                'retrieval_score' => 0.998877,
            ],
            'relevance_score' => null,
            'reranker_score' => null,
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('nullable-source.txt')
            ->assertSee('مقتطف بدون بيانات اختيارية.')
            ->assertDontSee('درجة إعادة الترتيب')
            ->assertDontSee('درجة الصلة')
            ->assertDontSee('0.998877')
            ->assertDontSee('data-source-reranker-score', false);
    }

    public function test_cross_user_source_is_not_rendered(): void
    {
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $owner->conversations()->create([
            'title' => 'محادثة آمنة',
        ]);

        $otherDocument = $this->createDocument(
            $otherUser,
            'private-other-user.txt',
            'وثيقة مستخدم آخر السرية',
        );

        $message = $this->createAssistantMessage(
            $conversation,
            'إجابة المالك.',
        );

        $message->sources()->create([
            'processing_run_id' => $this
                ->createProcessingRun($otherDocument)
                ->id,
            'qdrant_point_id' => 'cross-user-source-point',
            'chunk_index' => 2,
            'source_snapshot' => [
                'source' => 'private-other-user.txt',
                'page' => 1,
                'section' => 'سري',
                'excerpt' => 'محتوى لا يجب أن يظهر للمالك.',
            ],
            'relevance_score' => 0.95,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('إجابة المالك.')
            ->assertDontSee('وثيقة مستخدم آخر السرية')
            ->assertDontSee('private-other-user.txt')
            ->assertDontSee('محتوى لا يجب أن يظهر للمالك.')
            ->assertDontSee('data-message-source-trigger', false);
    }

    public function test_answer_without_sources_renders_without_source_button(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة بدون مصادر',
        ]);

        $this->createAssistantMessage(
            $conversation,
            'جواب مكتمل بدون مصادر.',
        );

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('جواب مكتمل بدون مصادر.')
            ->assertDontSee('data-message-source-trigger', false);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function createAssistantMessage(
        Conversation $conversation,
        string $content,
    ): Message {
        return $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => $content,
        ]);
    }

    private function createDocument(
        User $user,
        string $originalName,
        ?string $title,
    ): Document {
        $unique = uniqid('m6_', true);

        return $user->documents()->create([
            'original_name' => $originalName,
            'stored_name' => $unique.'-'.$originalName,
            'title' => $title,
            'file_path' => 'documents/'.$unique.'/'.$originalName,
            'file_type' => str_ends_with($originalName, '.pdf')
                ? 'pdf'
                : 'txt',
            'mime_type' => str_ends_with($originalName, '.pdf')
                ? 'application/pdf'
                : 'text/plain',
            'file_size' => 100,
            'sha256' => hash('sha256', $unique),
        ]);
    }

    private function createProcessingRun(
        Document $document,
    ): ProcessingRun {
        return $document->processingRuns()->create([
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
    }
}
