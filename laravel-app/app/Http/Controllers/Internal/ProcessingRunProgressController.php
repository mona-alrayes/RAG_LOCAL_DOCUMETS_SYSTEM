<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ProcessingRunEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessingRunEventRequest;
use App\Services\Documents\ProcessingRunProgressor;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ProcessingRunProgressController extends Controller
{
    public function __invoke(
        ProcessingRunEventRequest $request,
        string $processingRun,
        ProcessingRunProgressor $processingRunProgressor,
    ): JsonResponse {
        $validated = $request->validated();
        $processingRunId = (int) $processingRun;

        if (
            (int) $validated['processing_run_id']
            !== $processingRunId
        ) {
            throw ValidationException::withMessages([
                'processing_run_id' => [
                    'The processing run identifier does not match the route.',
                ],
            ]);
        }

        $updatedRun = $processingRunProgressor->markIndexingStarted(
            userId: (int) $validated['user_id'],
            documentId: (int) $validated['document_id'],
            processingRunId: $processingRunId,
        );

        return response()->json([
            'event' => ProcessingRunEvent::IndexingStarted->value,
            'processing_run_id' => (int) $updatedRun->getKey(),
            'status' => $updatedRun->status->value,
        ]);
    }
}
