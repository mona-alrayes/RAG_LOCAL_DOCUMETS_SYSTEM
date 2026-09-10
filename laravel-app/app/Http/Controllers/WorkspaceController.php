<?php

namespace App\Http\Controllers;

use App\Enums\DocumentAvailability;
use App\Services\Documents\Presentation\DocumentReadService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly DocumentReadService $documentReadService,
    ) {}

    public function __invoke(Request $request): View
    {
        $dashboard = $this->documentReadService->dashboardForUser(
            $request->user(),
        );

        $countsByStatus = $dashboard['counts_by_status'];

        return view('workspace.index', [
            'dashboard' => [
                'totalDocuments' => array_sum($countsByStatus),
                'readyDocuments' => $countsByStatus[
                    DocumentAvailability::Ready->value
                ] ?? 0,
                'processingDocuments' => $dashboard['active_processing_count'],
                'failedDocuments' => $countsByStatus[
                    DocumentAvailability::Failed->value
                ] ?? 0,
                'reprocessingCount' => $dashboard['reprocessing_count'],
                'recentDocuments' => $dashboard['recent_documents'],
                'recentFailures' => $dashboard['recent_failures'],
            ],
        ]);
    }
}
