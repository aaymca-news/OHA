<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that lets an invited person set their password. The link is single
 * use and expires after 7 days (the "invites" password broker).
 */
class InvitationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token, public string $invitedBy)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitation.show', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('You have been invited to the AAYMCA Organizational Health dashboard')
            ->line("{$this->invitedBy} has created an account for you on the Africa Alliance of YMCAs Organizational Health & Development dashboard.")
            ->action('Set your password', $url)
            ->line('This link works once and expires in 7 days. If you were not expecting it, you can ignore this email.');
    }
}
