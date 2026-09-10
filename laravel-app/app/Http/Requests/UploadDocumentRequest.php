<?php

namespace App\Http\Requests;

use App\Enums\ProcessingProfile;
use App\Models\Document;
use App\Rules\SecureDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxSizeKilobytes = (int) config(
            'documents.upload.max_size_kilobytes',
            10240,
        );

        return [
            'document' => [
                'bail',
                'required',
                'file',
                'max:'.$maxSizeKilobytes,
                new SecureDocumentUpload,
            ],
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
