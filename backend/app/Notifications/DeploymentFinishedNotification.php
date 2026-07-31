<?php

namespace App\Notifications;

use App\Models\Deployment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Deployments run for real minutes via a background Companion process
 * (DeploymentWizard) — a user who navigates away from Studio while it runs
 * previously had no way to find out it finished (or failed) short of
 * reopening the deployment history panel and checking.
 */
class DeploymentFinishedNotification extends Notification
{
    use Queueable;

    public function __construct(private Deployment $deployment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deployment_finished',
            'project_id' => $this->deployment->project_id,
            'deployment_id' => $this->deployment->id,
            'platform' => $this->deployment->platform,
            'status' => $this->deployment->status,
            'live_url' => $this->deployment->live_url,
        ];
    }
}
