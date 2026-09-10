<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateConversationDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation
            && ($this->user()?->can('update', $conversation) ?? false);
    }

    public function rules(): array
    {
        return [
            'document_ids' => [
                'sometimes',
                'array',
            ],
            'document_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('documents', 'id')
                    ->where(
                        fn ($query) => $query->where(
                            'user_id',
                            $this->user()->id,
                        ),
                    ),
            ],
        ];
    }
}
