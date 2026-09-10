<?php

namespace App\Http\Controllers;

use App\Http\Resources\DocumentDetailResource;
use App\Models\Document;
use App\Services\Documents\Presentation\DocumentReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentPollingController extends Controller
{
    public function __invoke(
        Request $request,
        Document $document,
        DocumentReadService $documentReadService,
    ): DocumentDetailResource {
        Gate::authorize('view', $document);

        $documentDetail = $documentReadService->detailForUser(
            $request->user(),
            $document->id,
        );

        return new DocumentDetailResource($documentDetail);
    }
}
