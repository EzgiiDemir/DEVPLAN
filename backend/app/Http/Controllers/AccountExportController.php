<?php

namespace App\Http\Controllers;

use App\Services\AccountExportService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountExportController extends Controller
{
    public function __construct(
        private AccountExportService $exporter,
        private AuditLogService $audit,
    ) {}

    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $zipPath = $this->exporter->build($user);

        $this->audit->record($user, 'account.data_exported');

        return response()->download($zipPath, 'devplan-account-export.zip')->deleteFileAfterSend();
    }
}
