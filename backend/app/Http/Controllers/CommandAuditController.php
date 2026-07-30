<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommandAuditController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * Companion stays stateless — it executes and streams output but never
     * talks to the Laravel backend directly (the same reason TestRunnerService
     * and DeploymentService are fed by the frontend relaying real results,
     * not Companion itself). This is that same relay, for commands/file
     * deletes: the frontend calls this right after Companion returns a
     * result, so there's a durable record of what actually ran.
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:command,file_delete'],
            'command' => ['required_if:type,command', 'nullable', 'string', 'max:2000'],
            'path' => ['required_if:type,file_delete', 'nullable', 'string', 'max:1000'],
            'risk_level' => ['required', 'string', 'in:safe,sensitive,dangerous'],
            'exit_code' => ['sometimes', 'nullable', 'integer'],
        ]);

        $action = $data['type'] === 'command' ? 'companion.command_executed' : 'companion.file_deleted';

        $this->audit->record($request->user(), $action, [
            'command' => $data['command'] ?? null,
            'path' => $data['path'] ?? null,
            'risk_level' => $data['risk_level'],
            'exit_code' => $data['exit_code'] ?? null,
        ], project: $project);

        // Additive to the durable AuditLog row above — a structured log
        // line for anything watching the log stream in real time (an
        // alerting rule, a SIEM) rather than polling the audit_logs table.
        if (in_array($data['risk_level'], ['sensitive', 'dangerous'], true)) {
            Log::warning('security.risky_command_executed', [
                'user_id' => $request->user()->id,
                'project_id' => $project->id,
                'action' => $action,
                'risk_level' => $data['risk_level'],
                'exit_code' => $data['exit_code'] ?? null,
            ]);
        }

        return response()->noContent();
    }
}
