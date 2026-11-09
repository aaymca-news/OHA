<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A workflow message: work has been routed to someone, or something they are
 * waiting on has moved. Kept in the app (the bell) and sent by email.
 *
 * Queued, and only after the transaction that caused it commits, so nobody is
 * told about a change that was rolled back.
 */
class WorkflowNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $tone  action | good | serious | info
     * @param  bool  $byEmail  false: in the app only (a colleague's routine change)
     */
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $link = null,
        public string $tone = 'info',
        public bool $byEmail = true,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->byEmail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->body);

        return $this->link !== null ? $mail->action('Open in the dashboard', url($this->link)) : $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'link' => $this->link,
            'tone' => $this->tone,
        ];
    }
}
