<?php

namespace App\Notifications;

use App\Support\DateFormats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The weekly ODP follow-up digest (Stage 2): one email a week with what needs attention
 * on each movement this person follows up: the follow-up under way (open, coming up or
 * past its agreed date) and the overall review still to write. Built from
 * App\Queries\FollowUpDigest. Also kept in the app (the bell), in short.
 */
class FollowUpDigestNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{movement: string, link: string, follow_up: string|null, review: string|null}>  $sections  plain text, ready to send
     */
    public function __construct(public array $sections) {}

    /**
     * Turns FollowUpDigest's sections into the plain lines this notice sends.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public static function from(array $sections): self
    {
        return new self(array_map(fn (array $s) => [
            'movement' => $s['movement']->name,
            'link' => route('follow-up.show', $s['assessment'], false),
            'follow_up' => ($f = $s['follow_up']) !== null
                ? 'Follow-up '.$f['number'].match (true) {
                    $f['overdue'] => ' is past its agreed date ('.$f['due_on']->format(DateFormats::DAY).') and not closed yet.',
                    $f['open'] => ' is open'.($f['due_on'] !== null ? ', planned for '.$f['due_on']->format(DateFormats::DAY) : '').': add the evidence and notes on the tasks.',
                    default => ' is planned for '.$f['due_on']->format(DateFormats::DAY).'.',
                }
                : null,
            'review' => ($s['review_due'] ?? false) ? 'The three follow-ups are closed: the overall review of the ODP is still to write.' : null,
        ], $sections));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('This week’s ODP follow-up')
            ->line('What needs attention on the ODP follow-up this week.');

        foreach ($this->sections as $s) {
            $mail->line('**'.$s['movement'].'**');
            foreach (['follow_up', 'review'] as $key) {
                if (($s[$key] ?? null) !== null) {
                    $mail->line($s[$key]);
                }
            }
            $mail->line('[Open '.$s['movement'].'’s follow-up]('.url($s['link']).')');
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $lines = collect($this->sections)->map(fn (array $s) => $s['movement'].': '.collect([$s['follow_up'], $s['review'] ?? null])->filter()->join(' '));

        return [
            'subject' => 'This week’s ODP follow-up',
            'body' => $lines->join(' '),
            'link' => count($this->sections) === 1 ? $this->sections[0]['link'] : null,
            'tone' => 'action',
        ];
    }
}
