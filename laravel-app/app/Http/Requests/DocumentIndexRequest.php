<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use App\Models\Document;
use App\Services\Documents\Presentation\Query\DocumentListCriteria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Document::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'nullable',
                Rule::enum(DocumentStatus::class),
            ],

            'file_type' => [
                'nullable',
                Rule::enum(FileType::class),
            ],

            'profile' => [
                'nullable',
                Rule::enum(ProcessingProfile::class),
            ],

            'sort_by' => [
                'nullable',
                Rule::in([
                    'created_at',
                    'updated_at',
                    'title',
                    'original_name',
                    'file_size',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ];
    }

    public function criteria(): DocumentListCriteria
    {
        $validated = $this->validated();

        return new DocumentListCriteria(
            search: isset($validated['search'])
                ? trim((string) $validated['search'])
                : null,

            status: isset($validated['status'])
                ? DocumentStatus::from((string) $validated['status'])
                : null,

            fileType: isset($validated['file_type'])
                ? FileType::from((string) $validated['file_type'])
                : null,

            profile: isset($validated['profile'])
                ? ProcessingProfile::from((string) $validated['profile'])
                : null,

            sortBy: (string) ($validated['sort_by'] ?? 'created_at'),

            sortDirection: (string) (
                $validated['sort_direction'] ?? 'desc'
            ),

            perPage: (int) ($validated['per_page'] ?? 10),
        );
    }
}
