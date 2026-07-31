<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GDPR Article 17 (right to erasure). The database does most of the actual
 * work: `projects.user_id`, `subscriptions.user_id`, `team_members.user_id`,
 * `comments.user_id`, etc. all cascade on delete, so deleting the User row
 * fans out through everything genuinely theirs alone. What this service
 * adds is the two things the schema can't express on its own: (1) refusing
 * to delete when that cascade would take a *shared* team's projects down
 * with it, and (2) cancelling any live Stripe subscription before the local
 * record disappears, so nothing keeps billing a deleted account's card.
 */
class AccountDeletionService
{
    public function __construct(
        private StripeBillingService $billing,
        private AuditLogService $audit,
    ) {}

    public function delete(User $user): void
    {
        $this->assertCanDelete($user);

        DB::transaction(function () use ($user) {
            $this->cancelActiveSubscription($user);

            $personalTeam = $user->teams()
                ->wherePivot('role', 'owner')
                ->where('personal', true)
                ->first();

            // Written before the delete below, not after — audit_logs.user_id
            // still references a real row at this point; once user_id is
            // gone, an insert pointing at it would violate the FK, not just
            // read back as null on some later SELECT.
            $this->audit->record($user, 'account.deleted', ['email' => $user->email]);

            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Cascades: owned projects (and, through those, every module/
            // task/comment/feature request/deployment/etc. belonging to
            // them), subscriptions, team_members rows, and anything else
            // with a cascadeOnDelete user_id foreign key.
            $user->delete();

            // Only ever this user's own personal team (never shared, by
            // construction) — safe to remove now that it has no members or
            // projects left.
            $personalTeam?->delete();
        });
    }

    /**
     * Blocks deletion wherever the users.id cascade would silently take
     * other people's shared work with it: a project this user created
     * inside a non-personal (shared) team, or a non-personal team where
     * they're the only owner left.
     */
    private function assertCanDelete(User $user): void
    {
        $sharedProjects = Project::where('user_id', $user->id)
            ->whereHas('team', fn ($q) => $q->where('personal', false))
            ->pluck('title');

        if ($sharedProjects->isNotEmpty()) {
            throw new RuntimeException(
                'Transfer or delete these team projects before deleting your account: '.$sharedProjects->implode(', '),
            );
        }

        $soleOwnedTeams = $user->teams()
            ->wherePivot('role', 'owner')
            ->where('personal', false)
            ->get()
            ->filter(fn (Team $team) => $team->members()->where('role', 'owner')->count() <= 1);

        if ($soleOwnedTeams->isNotEmpty()) {
            throw new RuntimeException(
                'Transfer ownership or delete these teams before deleting your account: '.$soleOwnedTeams->pluck('name')->implode(', '),
            );
        }
    }

    /**
     * A GDPR erasure request isn't optional the way a billing sync is —
     * if Stripe rejects the cancellation (bad ID, network blip, API
     * outage), the account is still deleted; the failure is logged loudly
     * instead, for support to reconcile Stripe's side by hand, rather than
     * leaving someone unable to exercise their right to erasure because of
     * an unrelated billing-provider hiccup.
     */
    private function cancelActiveSubscription(User $user): void
    {
        $subscription = $user->subscriptions()->latest()->first();

        if (! $subscription?->stripe_subscription_id || ! $this->billing->isConfigured()) {
            return;
        }

        try {
            $this->billing->cancelImmediately($subscription);
        } catch (RuntimeException $e) {
            Log::error('account_deletion.stripe_cancellation_failed', [
                'user_id' => $user->id,
                'stripe_subscription_id' => $subscription->stripe_subscription_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
