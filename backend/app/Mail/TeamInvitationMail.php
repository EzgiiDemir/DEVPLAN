<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TeamInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->invitation->team->name} on DevPlan",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invitation',
            with: [
                'teamName' => $this->invitation->team->name,
                'inviterName' => $this->invitation->invitedBy->name,
                'role' => $this->invitation->role,
                'acceptUrl' => rtrim(config('app.frontend_url', config('app.url')), '/')."/invitations/{$this->invitation->token}",
            ],
        );
    }
}
