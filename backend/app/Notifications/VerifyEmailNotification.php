<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Overrides Laravel's default VerifyEmail notification only in where the
 * link points: `URL::temporarySignedRoute()` still generates a real signed
 * URL against the named `verification.verify` API route (so the id/hash/
 * expiry/signature are genuine and independently verifiable), but the link
 * actually emailed points at the frontend SPA's verify-email page, which
 * reads those exact query parameters back out and forwards them to the API.
 */
class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $signedBackendUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        );

        $query = parse_url($signedBackendUrl, PHP_URL_QUERY);
        $frontendUrl = rtrim(config('app.frontend_url'), '/').'/verify-email?'.$query;

        return (new MailMessage)
            ->subject('Verify your DevPlan email address')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', $frontendUrl)
            ->line('This verification link will expire in 60 minutes.')
            ->line('If you did not create an account, no further action is required.');
    }
}
