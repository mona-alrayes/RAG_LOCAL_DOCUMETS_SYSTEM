<?php

namespace App\Http\Requests;

use App\Enums\ProcessingRunEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessingRunEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'event' => [
                'required',
                'string',
                Rule::enum(ProcessingRunEvent::class),
            ],
            'user_id' => ['required', 'integer', 'min:1'],
            'document_id' => ['required', 'integer', 'min:1'],
            'processing_run_id' => ['required', 'integer', 'min:1'],
            'correlation_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
