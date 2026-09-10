<?php

namespace App\Services\Ai\Validation;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Services\Ai\Data\ProcessDocumentRequestData;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

final class ProcessDocumentResponseValidator
{
    private const PROCESSING_STAGES = [
        'parse',
        'chunk',
        'dense_embedding',
        'sparse_representation',
        'total',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(
        array $payload,
        ProcessDocumentRequestData $requestData,
    ): array {
        $validator = Validator::make($payload, $this->rules());

        if ($validator->fails()) {
            throw new UnexpectedValueException(
                'Invalid process document response.',
            );
        }

        $validated = $validator->validated();

        if (
            ! array_is_list($validated['warnings'])
            || $validated['document_id'] !== $requestData->documentId
            || $validated['processing_run_id']
                !== $requestData->processingRunId
            || $validated['profile']
                !== $requestData->processingProfile->value
            || $validated['status'] !== ProcessingRunStatus::Indexed->value
            || data_get($validated, 'profile_snapshot.profile')
                !== $requestData->processingProfile->value
            || (
                $validated['vector_dimension'] !== null
                && data_get(
                    $validated,
                    'profile_snapshot.dense_embedding.vector_dimension',
                ) !== $validated['vector_dimension']
            )
            || (
                $requestData->processingProfile === ProcessingProfile::Cloud
                && ! is_array(data_get($validated, 'profile_snapshot.batching'))
            )
        ) {
            throw new UnexpectedValueException(
                'Invalid process document response.',
            );
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'document_id' => ['required', $this->strictInteger(1)],
            'processing_run_id' => ['required', $this->strictInteger(1)],
            'profile' => ['required', Rule::enum(ProcessingProfile::class)],
            'status' => [
                'required',
                Rule::enum(ProcessingRunStatus::class)
                    ->only([ProcessingRunStatus::Indexed]),
            ],
            'qdrant_collection' => ['required', 'string'],
            'profile_snapshot' => [
                'required',
                'array:profile,chunking,dense_embedding,sparse_representation,batching',
            ],
            'profile_snapshot.profile' => [
                'required',
                Rule::enum(ProcessingProfile::class),
            ],
            'profile_snapshot.chunking' => [
                'required',
                'array:chunk_size,chunk_overlap',
            ],
            'profile_snapshot.chunking.chunk_size' => [
                'required',
                $this->strictInteger(1),
            ],
            'profile_snapshot.chunking.chunk_overlap' => [
                'required',
                $this->strictInteger(0),
            ],
            'profile_snapshot.dense_embedding' => [
                'required',
                'array:provider,model,vector_dimension',
            ],
            'profile_snapshot.dense_embedding.provider' => [
                'required',
                'string',
            ],
            'profile_snapshot.dense_embedding.model' => [
                'required',
                'string',
            ],
            'profile_snapshot.dense_embedding.vector_dimension' => [
                'required',
                $this->strictInteger(1),
            ],
            'profile_snapshot.sparse_representation' => [
                'required',
                'array:provider,model,tokenizer,language,disable_stemmer',
            ],
            'profile_snapshot.sparse_representation.provider' => [
                'required',
                'string',
            ],
            'profile_snapshot.sparse_representation.model' => [
                'required',
                'string',
            ],
            'profile_snapshot.sparse_representation.tokenizer' => [
                'present',
                'nullable',
                'string',
            ],
            'profile_snapshot.sparse_representation.language' => [
                'present',
                'nullable',
                'string',
            ],
            'profile_snapshot.sparse_representation.disable_stemmer' => [
                'present',
                'nullable',
                $this->strictBoolean(),
            ],
            'profile_snapshot.batching' => [
                'present',
                'nullable',
                'array:batch_size,wait_between_batches_seconds,rate_limit_retry_wait_seconds,max_retries',
            ],
            'profile_snapshot.batching.batch_size' => [
                'required_with:profile_snapshot.batching',
                $this->strictInteger(1),
            ],
            'profile_snapshot.batching.wait_between_batches_seconds' => [
                'required_with:profile_snapshot.batching',
                $this->nonNegativeNumber(),
            ],
            'profile_snapshot.batching.rate_limit_retry_wait_seconds' => [
                'required_with:profile_snapshot.batching',
                $this->nonNegativeNumber(),
            ],
            'profile_snapshot.batching.max_retries' => [
                'required_with:profile_snapshot.batching',
                $this->strictInteger(0),
            ],
            'total_pages' => [
                'present',
                'nullable',
                $this->strictInteger(0),
            ],
            'total_chunks' => ['required', $this->strictInteger(0)],
            'vector_count' => ['required', $this->strictInteger(0)],
            'vector_dimension' => [
                'present',
                'nullable',
                $this->strictInteger(1),
            ],
            'stage_timings_ms' => [
                'present',
                'array:'.implode(',', self::PROCESSING_STAGES),
            ],
            'stage_timings_ms.*' => [$this->strictInteger(0)],
            'warnings' => ['present', 'array'],
            'warnings.*' => ['array:code,message,stage'],
            'warnings.*.code' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
            'warnings.*.message' => [
                'required',
                'string',
                'max:300',
                'regex:/\S/u',
                'not_regex:/[\x00-\x1F\x7F]/u',
            ],
            'warnings.*.stage' => [
                'present',
                'nullable',
                Rule::in(self::PROCESSING_STAGES),
            ],
        ];
    }

    private function strictInteger(int $minimum): Closure
    {
        return static function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ) use ($minimum): void {
            if (! is_int($value) || $value < $minimum) {
                $fail("The {$attribute} field must be a valid integer.");
            }
        };
    }

    private function strictBoolean(): Closure
    {
        return static function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ): void {
            if (! is_bool($value)) {
                $fail("The {$attribute} field must be true or false.");
            }
        };
    }

    private function nonNegativeNumber(): Closure
    {
        return static function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ): void {
            if (
                (! is_int($value) && ! is_float($value))
                || $value < 0
            ) {
                $fail("The {$attribute} field must be a valid number.");
            }
        };
    }
}
