<?php

namespace App\Http\Controllers;

use App\Enums\FileType;
use App\Exceptions\AiServiceException;
use App\Exceptions\DocumentDeletionException;
use App\Exceptions\DocumentReprocessingException;
use App\Exceptions\DuplicateDocumentException;
use App\Http\Requests\DeleteDocumentRequest;
use App\Http\Requests\DocumentIndexRequest;
use App\Http\Requests\ReprocessDocumentRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Document;
use App\Services\Ai\ProcessingCapabilityService;
use App\Services\Documents\DocumentDeletionService;
use App\Services\Documents\DocumentProcessingDispatcher;
use App\Services\Documents\DocumentUploadService;
use App\Services\Documents\Presentation\DocumentReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles authenticated document HTTP endpoints.
 *
 * Responsibilities:
 * - Display the document library.
 * - Display document details and processing history.
 * - Upload new documents.
 * - Preview supported private files.
 * - Download original private files.
 * - Start document reprocessing.
 * - Delete documents.
 *
 * Business logic is delegated to dedicated application services in order
 * to keep the controller thin and preserve separation of concerns.
 */
class DocumentController extends Controller
{
    /**
     * Display the authenticated user's document library.
     *
     * Documents are loaded through DocumentReadService as
     * presentation-ready DTOs instead of exposing Eloquent models
     * directly to the view.
     *
     * Processing capabilities are resolved at runtime. If the capability
     * service cannot be reached or returns invalid data, the UI fails closed
     * by treating all processing profiles as unavailable.
     */
    public function index(
        DocumentIndexRequest $request,
        DocumentReadService $documentReadService,
        ProcessingCapabilityService $processingCapabilityService,
    ): View {
        $documents = $documentReadService
            ->paginateForUser(
                $request->user(),
                $request->criteria(),
            )
            ->appends($request->validated());

        $hasAnyDocuments = $documentReadService->hasAnyForUser(
            $request->user(),
        );

        try {
            $availableProcessingProfiles = $processingCapabilityService
                ->availableProfiles();
        } catch (AiServiceException) {
            $availableProcessingProfiles = [];
        }

        return view('documents.index', compact(
            'documents',
            'hasAnyDocuments',
            'availableProcessingProfiles',
        ));
    }

    /**
     * Display presentation-ready details for one owned document.
     *
     * Authorization is checked before loading the read model.
     *
     * DocumentReadService provides:
     * - Document summary.
     * - Active processing run.
     * - Latest processing attempt.
     * - Complete processing timeline.
     *
     * The active run and latest attempt are intentionally separate because
     * they may differ while reprocessing is running or after a failed
     * reprocessing attempt.
     *
     * Runtime processing capabilities are also passed to the view so the UI
     * does not offer reprocessing through an unavailable processing profile.
     */
    public function show(
        Request $request,
        Document $document,
        DocumentReadService $documentReadService,
        ProcessingCapabilityService $processingCapabilityService,
    ): View {
        Gate::authorize('view', $document);

        $documentDetail = $documentReadService->detailForUser(
            $request->user(),
            $document->id,
        );

        try {
            $availableProcessingProfiles = $processingCapabilityService
                ->availableProfiles();
        } catch (AiServiceException) {
            $availableProcessingProfiles = [];
        }

        return view('documents.show', compact(
            'documentDetail',
            'availableProcessingProfiles',
        ));
    }

    /**
     * Store a newly uploaded document and start its initial processing.
     *
     * Upload validation and processing profile validation are handled by
     * UploadDocumentRequest.
     *
     * DocumentUploadService owns persistence and processing dispatch logic.
     * The controller only translates known application failures into safe
     * user-facing redirects and messages.
     */
    public function store(
        UploadDocumentRequest $request,
        DocumentUploadService $uploadService,
    ): RedirectResponse {
        try {
            $document = $uploadService->store(
                $request->user(),
                $request->file('document'),
                $request->processingProfile(),
            );
        } catch (DuplicateDocumentException $exception) {
            return redirect()
                ->route('documents.show', $exception->document)
                ->with(
                    'warning',
                    __('documents.commands.upload.duplicate'),
                );
        } catch (AiServiceException $exception) {
            $message = match ($exception->errorCode) {
                'processing_profile_unavailable' => __('documents.commands.upload.profile_unavailable'),

                default => __('documents.commands.upload.service_unavailable'),
            };

            return redirect()
                ->route('documents.index')
                ->with('error', $message);
        }

        return redirect()
            ->route('documents.show', $document)
            ->with(
                'success',
                __('documents.commands.upload.success'),
            );
    }

    /**
     * Stream a supported private document inline for browser preview.
     *
     * Preview uses the same authorization ability as download because both
     * operations grant access to the document's private file contents.
     *
     * Supported preview types:
     * - PDF: rendered by the browser's native PDF viewer.
     * - TXT: rendered as UTF-8 plain text.
     *
     * DOCX preview is intentionally unsupported because browsers do not
     * provide reliable native Word document rendering.
     *
     * Files remain on the private documents disk and are never exposed
     * through a public storage URL.
     */
    public function preview(Document $document): StreamedResponse
    {
        Gate::authorize('download', $document);

        abort_unless(
            in_array(
                $document->file_type,
                [
                    FileType::Pdf,
                    FileType::Txt,
                ],
                true,
            ),
            404,
        );

        $disk = Storage::disk('documents');

        abort_unless(
            $disk->exists($document->file_path),
            404,
        );

        return $disk->response(
            $document->file_path,
            $document->original_name,
            [
                'Content-Type' => $document->file_type === FileType::Pdf
                    ? 'application/pdf'
                    : 'text/plain; charset=UTF-8',

                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    /**
     * Download the original private document.
     *
     * Ownership is enforced through DocumentPolicy before accessing the file.
     * The file remains on the private documents disk and is returned as an
     * attachment using the user's original filename.
     */
    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('download', $document);

        $disk = Storage::disk('documents');

        abort_unless(
            $disk->exists($document->file_path),
            404,
        );

        return $disk->download(
            $document->file_path,
            $document->original_name,
            [
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Start a new processing attempt for an already usable document.
     *
     * Reprocessing orchestration belongs to DocumentProcessingDispatcher.
     *
     * Starting a new attempt does not replace the currently active run.
     * Therefore, a document may remain usable through its previous active
     * indexed run while the latest attempt is processing, indexing, or failed.
     *
     * Known application and AI service failures are translated into safe
     * localized messages without exposing internal exception details.
     */
    public function reprocess(
        ReprocessDocumentRequest $request,
        Document $document,
        DocumentProcessingDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->dispatchReprocessing(
                $document,
                $request->processingProfile(),
            );
        } catch (DocumentReprocessingException $exception) {
            $message = match ($exception->reason) {
                DocumentReprocessingException::NO_ACTIVE_RUN => __('documents.commands.reprocess.no_active_run'),

                DocumentReprocessingException::INVALID_ACTIVE_RUN => __('documents.commands.reprocess.invalid_active_run'),

                DocumentReprocessingException::ALREADY_IN_PROGRESS => __('documents.commands.reprocess.already_in_progress'),

                default => __('documents.commands.reprocess.failed'),
            };

            return redirect()
                ->route('documents.show', $document)
                ->with('error', $message);
        } catch (AiServiceException $exception) {
            $message = match ($exception->errorCode) {
                'processing_profile_unavailable' => __('documents.commands.reprocess.profile_unavailable'),

                default => __('documents.commands.reprocess.service_unavailable'),
            };

            return redirect()
                ->route('documents.show', $document)
                ->with('error', $message);
        }

        return redirect()
            ->route('documents.show', $document)
            ->with(
                'success',
                __('documents.commands.reprocess.started'),
            );
    }

    /**
     * Delete an owned document through the existing deletion service.
     *
     * DocumentDeletionService owns all deletion and cleanup rules.
     *
     * The controller does not directly remove processing runs, vector data,
     * or stored files. It only translates the application command result into
     * an appropriate HTTP redirect and safe user-facing message.
     */
    public function destroy(
        DeleteDocumentRequest $request,
        Document $document,
        DocumentDeletionService $deletionService,
    ): RedirectResponse {
        try {
            $deletionService->delete($document);
        } catch (DocumentDeletionException $exception) {
            $message = match ($exception->reason) {
                DocumentDeletionException::PROCESSING_IN_PROGRESS => __('documents.commands.delete.processing_in_progress'),

                default => __('documents.commands.delete.failed'),
            };

            return redirect()
                ->route('documents.show', $document)
                ->with('error', $message);
        } catch (AiServiceException) {
            return redirect()
                ->route('documents.show', $document)
                ->with(
                    'error',
                    __('documents.commands.delete.cleanup_failed'),
                );
        }

        return redirect()
            ->route('documents.index')
            ->with(
                'success',
                __('documents.commands.delete.success'),
            );
    }
}
