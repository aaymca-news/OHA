<?php

namespace App\Notifications;

use App\Models\OdpTask;
use App\Support\DateFormats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The weekly ODP follow-up digest (Stage 2): one email a week with what needs attention
 * on each movement this person follows up, instead of one email per task. Built from
 * App\Queries\FollowUpDigest. Also kept in the app (the bell), in short.
 */
class FollowUpDigestNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{movement: string, link: string, overdue: list<string>, due_soon: list<string>, waiting: list<string>, meeting: string|null, reassess: string|null, review?: string|null}>  $sections  plain text, ready to send
     */
    public function __construct(public array $sections) {}

    /**
     * Turns FollowUpDigest's sections into the plain lines this notice sends.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public static function from(array $sections): self
    {
        $task = fn (OdpTask $t) => ($t->ref ? $t->ref.' ' : '').Str::limit($t->task, 90).($t->due_on ? ' (due '.$t->due_on->format(DateFormats::DAY).')' : '');

        return new self(array_map(fn (array $s) => [
            'movement' => $s['movement']->name,
            'link' => route('follow-up.show', $s['assessment'], false),
            'overdue' => $s['overdue']->map($task)->all(),
            'due_soon' => $s['due_soon']->map($task)->all(),
            'waiting' => $s['waiting']->map($task)->all(),
            'meeting' => $s['meeting'] !== null ? 'Follow-up meeting '.$s['meeting']['number'].' is due on '.$s['meeting']['due_on']->format(DateFormats::DAY).'.' : null,
            'reassess' => $s['reassess_on'] !== null
                ? ($s['reassess_on']->isPast() ? 'The next assessment was due on ' : 'The next assessment is due on ').$s['reassess_on']->format(DateFormats::DAY).'.'
                : null,
            'review' => ($s['review_due'] ?? false) ? 'The overall review of the ODP is not written yet: write it before the next assessment.' : null,
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
            foreach ([['overdue', 'Overdue'], ['due_soon', 'Due in the next 30 days'], ['waiting', 'Reported done, waiting for your confirmation']] as [$key, $heading]) {
                if ($s[$key] !== []) {
                    $mail->line($heading.':')->line(implode("\n", array_map(fn (string $t) => '- '.$t, $s[$key])));
                }
            }
            foreach (['meeting', 'reassess', 'review'] as $key) {
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
        $counts = collect($this->sections)->map(fn (array $s) => $s['movement'].': '.collect([
            count($s['overdue']) > 0 ? count($s['overdue']).' overdue' : null,
            count($s['due_soon']) > 0 ? count($s['due_soon']).' due soon' : null,
            count($s['waiting']) > 0 ? count($s['waiting']).' to confirm' : null,
            $s['meeting'] !== null ? 'a follow-up meeting due' : null,
            $s['reassess'] !== null ? 'next assessment due' : null,
            ($s['review'] ?? null) !== null ? 'the ODP review to write' : null,
        ])->filter()->join(', '));

        return [
            'subject' => 'This week’s ODP follow-up',
            'body' => $counts->join('. ').'.',
            'link' => count($this->sections) === 1 ? $this->sections[0]['link'] : null,
            'tone' => 'action',
        ];
    }
}
