<?php

namespace App\Http\Requests;

use App\Enums\ProcessingProfile;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReprocessDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && ($this->user()?->can('reprocess', $document) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'processing_profile' => [
                'required',
                Rule::enum(ProcessingProfile::class),
            ],
        ];
    }

    public function processingProfile(): ProcessingProfile
    {
        return ProcessingProfile::from(
            (string) $this->validated('processing_profile'),
        );
    }
}
