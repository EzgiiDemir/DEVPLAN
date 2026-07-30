<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Project-scoped history — requires 'manage' (admin/owner), the same
     * bar as Phase 10's project sharing settings, since this can reveal
     * who did what across the whole team.
     */
    public function index(Request $request, Project $project)
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return AuditLog::where('project_id', $project->id)
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * A user's own account-security history (login/MFA/session events) —
     * always visible to them regardless of project role, since it's about
     * their own account, not a project they may or may not manage.
     */
    public function me(Request $request)
    {
        return AuditLog::where('user_id', $request->user()->id)
            ->whereIn('action', [
                'auth.login', 'auth.login_failed', 'auth.logout', 'auth.register',
                'auth.mfa_enabled', 'auth.mfa_disabled', 'auth.mfa_challenge_failed',
                'auth.session_revoked',
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
