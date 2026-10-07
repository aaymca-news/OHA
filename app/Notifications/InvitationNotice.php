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

    /**
     * @param  list<string>  $movements  the movements an AAYMCA user is assigned to assess
     * @param  string|null  $chairOf  the movement a Board Chairperson represents
     */
    public function __construct(public string $token, public string $invitedBy, public array $movements = [], public ?string $chairOf = null)
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

        $mail = (new MailMessage)
            ->subject('You have been invited to the AAYMCA Organizational Health dashboard')
            ->line("{$this->invitedBy} has created an account for you on the Africa Alliance of YMCAs Organizational Health & Development dashboard.");

        if ($this->chairOf !== null) {
            $mail->line("You are invited as the Board Chairperson of {$this->chairOf}: you will read its approved report and sign its Organisational Development Plan.");
        } elseif ($this->movements !== []) {
            $mail->line('You are assigned to assess: '.collect($this->movements)->join(', ', ' and ').'. They will be waiting in My Work when you sign in.');
        }

        return $mail
            ->action('Set your password', $url)
            ->line('This link works once and expires in 7 days. If you were not expecting it, you can ignore this email.');
    }
}
