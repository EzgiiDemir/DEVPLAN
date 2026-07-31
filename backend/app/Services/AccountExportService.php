<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * A GDPR Article 20 (data portability) export of one user's own account:
 * their profile, team memberships, subscription, audit history, and
 * notifications, plus every project they can access — each folded in via
 * ProjectExportService::addProjectToZip() so the per-project file layout
 * is defined in exactly one place. Team-shared project content isn't
 * solely "their" data, but excluding it would make this export useless as
 * an actual account backup — the same tension GDPR export tools generally
 * resolve by including everything the account can see.
 */
class AccountExportService
{
    public function __construct(private ProjectExportService $projectExporter) {}

    public function build(User $user): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'devplan-account-export-').'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('account.json', $this->json([
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'oauth_provider' => $user->oauth_provider,
            'two_factor_enabled' => $user->hasMfaEnabled(),
            'onboarding_completed_at' => $user->onboarding_completed_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'exported_at' => now()->toIso8601String(),
        ]));

        $zip->addFromString('teams.json', $this->json($user->teams->map(fn ($team) => [
            'name' => $team->name,
            'personal' => (bool) $team->personal,
            'role' => $team->pivot->role,
        ])));

        $subscription = $user->subscriptions()->latest()->first();
        $zip->addFromString('subscription.json', $this->json($subscription ? [
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
        ] : null));

        $zip->addFromString('audit_log.json', $this->json(
            AuditLog::where('user_id', $user->id)->latest()->get()->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'project_id' => $log->project_id,
                'team_id' => $log->team_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
        ));

        $zip->addFromString('notifications.json', $this->json(
            $user->notifications()->get()->map(fn ($notification) => [
                'type' => $notification->data['type'] ?? null,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ]),
        ));

        $teamIds = $user->teams()->pluck('teams.id');
        $projects = Project::whereIn('team_id', $teamIds)->get();

        foreach ($projects as $project) {
            $folder = "projects/{$project->id}-".Str::slug($project->title ?: 'project').'/';
            $this->projectExporter->addProjectToZip($zip, $project, $folder);
        }

        $zip->addFromString('README.txt', $this->readme($user));

        $zip->close();

        return $zipPath;
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function readme(User $user): string
    {
        return <<<TEXT
        DevPlan account data export — {$user->name} ({$user->email})
        Generated: {$user->created_at?->toIso8601String()}

        This archive contains everything DevPlan holds about your account:
        your profile, team memberships, subscription, audit history, and
        notifications, plus a full export of every project you can access
        under projects/.

        It does not contain your password (only Laravel ever sees the
        hash, never the plaintext) or any project's actual source code —
        that lives in your own local git repository.
        TEXT;
    }
}
