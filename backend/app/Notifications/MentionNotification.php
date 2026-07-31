<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired the moment a comment mentions a teammate — previously this had a
 * durable Mention row (Phase 10) but nothing ever told the mentioned user;
 * they'd only find out by happening to revisit that exact thread.
 */
class MentionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Comment $comment,
        private User $author,
        private Project $project,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mention',
            'project_id' => $this->project->id,
            'project_title' => $this->project->title,
            'comment_id' => $this->comment->id,
            'author_name' => $this->author->name,
            'excerpt' => mb_substr($this->comment->body, 0, 140),
        ];
    }
}
