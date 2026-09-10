<?php

namespace App\Livewire\Conversations;

use App\Models\Conversation;
use App\Services\Conversations\ConversationAnswerLifecycleService;
use App\Services\Conversations\Presentation\ConversationReadService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use LogicException;

final class Chat extends Component
{
    #[Locked]
    public int $conversationId;

    public string $question = '';

    private ConversationAnswerLifecycleService $answerLifecycleService;

    private ConversationReadService $conversationReadService;

    public function boot(
        ConversationAnswerLifecycleService $answerLifecycleService,
        ConversationReadService $conversationReadService,
    ): void {
        $this->answerLifecycleService =
            $answerLifecycleService;

        $this->conversationReadService =
            $conversationReadService;
    }

    public function mount(
        int $conversationId,
    ): void {
        $this->conversationId =
            $conversationId;

        Gate::authorize(
            'view',
            $this->ownedConversation(),
        );
    }

    public function ask(): void
    {
        $this->resetErrorBag();

        $this->validate(
            [
                'question' => [
                    'required',
                    'string',
                ],
            ],
            [
                'question.required' => 'اكتب السؤال أولاً.',
            ],
        );

        $question = trim($this->question);

        if ($question === '') {
            $this->addError(
                'question',
                'اكتب السؤال أولاً.',
            );

            return;
        }

        $conversation =
            $this->ownedConversation();

        Gate::authorize(
            'view',
            $conversation,
        );

        $this->answerLifecycleService
            ->start(
                user: Auth::user(),
                conversation: $conversation,
                question: $question,
            );

        $this->reset('question');
    }

    public function retry(
        int $assistantMessageId,
    ): void {
        $this->resetErrorBag('retry');

        $conversation =
            $this->ownedConversation();

        Gate::authorize(
            'view',
            $conversation,
        );

        try {
            $this->answerLifecycleService
                ->retry(
                    user: Auth::user(),
                    conversation: $conversation,
                    assistantMessageId: $assistantMessageId,
                );
        } catch (LogicException) {
            $this->addError(
                'retry',
                'تعذر إعادة المحاولة لهذه الإجابة.',
            );
        }
    }

    #[On('conversation-answer-terminal')]
    public function refreshAnswers(): void
    {
        Gate::authorize(
            'view',
            $this->ownedConversation(),
        );
    }

    public function render(): View
    {
        $conversation =
            $this->ownedConversation();

        Gate::authorize(
            'view',
            $conversation,
        );

        return view(
            'livewire.conversations.chat',
            [
                'conversation' => $conversation,
                'messages' => $this
                    ->conversationReadService
                    ->messagesForConversation(
                        $conversation,
                        Auth::user(),
                    ),
            ],
        );
    }

    private function ownedConversation(): Conversation
    {
        return Auth::user()
            ->conversations()
            ->findOrFail(
                $this->conversationId,
            );
    }
}
