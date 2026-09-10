<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\UpdateConversationDocumentsRequest;
use App\Http\Requests\UpdateConversationRequest;
use App\Models\Conversation;
use App\Services\Conversations\ConversationDeletionService;
use App\Services\Conversations\Exceptions\ConversationHasPendingAnswer;
use App\Services\Conversations\Presentation\ConversationReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationReadService $conversationReadService,
        private readonly ConversationDeletionService $conversationDeletionService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Conversation::class);

        return view('conversations.index', [
            'conversations' => $this->conversationReadService
                ->forUser($request->user()),
        ]);
    }

    public function store(
        StoreConversationRequest $request,
    ): RedirectResponse {
        $conversation = $request->user()
            ->conversations()
            ->create([
                'title' => $request->validated('title'),
            ]);

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', 'تم إنشاء المحادثة بنجاح.');
    }

    public function show(
        Request $request,
        Conversation $conversation,
    ): View {
        Gate::authorize('view', $conversation);

        return view('conversations.show', [
            'conversation' => $conversation,
            'conversations' => $this->conversationReadService
                ->forUser($request->user()),
            'messages' => $this->conversationReadService
                ->messagesForConversation(
                    $conversation,
                    $request->user(),
                ),
        ]);
    }

    public function update(
        UpdateConversationRequest $request,
        Conversation $conversation,
    ): RedirectResponse {
        $conversation->forceFill([
            'title' => $request->validated('title'),
        ])->save();

        return back()->with(
            'success',
            'تم تعديل اسم المحادثة بنجاح.',
        );
    }

    public function destroy(
        Request $request,
        Conversation $conversation,
    ): RedirectResponse {
        Gate::authorize(
            'delete',
            $conversation,
        );

        try {
            $this->conversationDeletionService->delete(
                $request->user(),
                $conversation,
            );
        } catch (ConversationHasPendingAnswer) {
            return back()->withErrors([
                'conversation_delete' => 'لا يمكن حذف المحادثة أثناء إنشاء إجابة. انتظر انتهاء الإجابة ثم أعد المحاولة.',
            ]);
        }

        return redirect()
            ->route('conversations.index')
            ->with(
                'success',
                'تم حذف المحادثة بنجاح.',
            );
    }

    public function updateDocuments(
        UpdateConversationDocumentsRequest $request,
        Conversation $conversation,
    ): RedirectResponse {
        $conversation->documents()->sync(
            $request->validated('document_ids', []),
        );

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', 'تم تحديث وثائق المحادثة بنجاح.');
    }
}
