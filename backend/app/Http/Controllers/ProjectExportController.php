<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AuditLogService;
use App\Services\ProjectExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectExportController extends Controller
{
    public function __construct(
        private ProjectExportService $exporter,
        private AuditLogService $audit,
    ) {}

    public function export(Project $project): BinaryFileResponse
    {
        $this->authorize('view', $project);

        $zipPath = $this->exporter->build($project);

        $this->audit->record(request()->user(), 'project.exported', [], project: $project);

        $filename = str($project->title)->slug()->value().'-devplan-export.zip';

        return response()->download($zipPath, $filename)->deleteFileAfterSend();
    }
}
