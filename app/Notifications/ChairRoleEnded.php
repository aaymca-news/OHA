<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a Board Chairperson, by email, that someone else now represents their movement
 * and that their account has been deactivated. Email only: they can no longer sign in
 * to see a notice in the dashboard.
 */
class ChairRoleEnded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $movement, public string $replacedBy, public ?string $newChair = null)
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
        return (new MailMessage)
            ->subject("Your role as Board Chairperson of {$this->movement} on the OHA platform has ended")
            ->greeting('Dear '.(strtok(trim((string) ($notifiable->name ?? '')), ' ') ?: 'colleague').',')
            ->line("{$this->replacedBy} has appointed ".($this->newChair !== null ? "{$this->newChair} as " : 'a new ')."Board Chairperson of {$this->movement} on the AAYMCA Organizational Health platform.")
            ->line('Your account on the platform has therefore been deactivated, and you can no longer sign in. Documents you signed remain on record.')
            ->line('Thank you for your service to the movement. If you believe this is a mistake, please contact the AAYMCA Secretariat.');
    }
}
