<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation
            && $this->user()?->can(
                'update',
                $conversation,
            ) === true;
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');

        if (is_string($title)) {
            $this->merge([
                'title' => trim($title),
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
