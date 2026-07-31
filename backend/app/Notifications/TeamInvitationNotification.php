<?php

namespace App\Notifications;

use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * For an invitee who already has a DevPlan account — the invitation email
 * (TeamInvitationMail) still goes out either way, but an existing user is
 * far more likely to notice an in-app notification the next time they're
 * already using the product than to check an inbox for it.
 */
class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private Team $team, private User $inviter, private string $role) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'team_invitation',
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'inviter_name' => $this->inviter->name,
            'role' => $this->role,
        ];
    }
}
