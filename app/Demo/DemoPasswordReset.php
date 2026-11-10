<?php

namespace App\Demo;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The demo site's only email to a real person: the link that lets a visitor choose a new
 * password for their demo account. Works once, for 60 minutes. Marked "Demo" as it
 * leaves (DemoMail).
 */
class DemoPasswordReset extends Notification
{
    public function __construct(public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(DemoVisitor $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Choose a new password for your OHA demo')
            ->greeting("Hello {$notifiable->name},")
            ->line('Someone asked to choose a new password for your account on the OHA demo site.')
            ->action('Choose a new password', route('demo.password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('This link works once and expires in 60 minutes. If you did not ask for it, you can ignore this email: your password stays the same.');
    }
}
