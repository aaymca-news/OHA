<?php

namespace App\Demo;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The demo site: what needs an Administrator's action in a visitor's demo (a form, report
 * or ODP sent for approval) also reaches the showcase's Super Administrator, in their
 * notifications there, with a link to open that visitor's demo and act on it.
 */
final class DemoShowcaseInbox
{
    /**
     * The notices already passed on in this request (one notice goes to several Administrators).
     *
     * @var array<string, true>
     */
    private static array $passed = [];

    public function handle(NotificationSent $event): void
    {
        $notice = $event->notification;
        $key = Sandboxes::current();
        // In one shared demo, the Super Administrator is told directly, like every Administrator.
        if ($key === null || Sandboxes::shared() || $event->channel !== 'database' || ! $notice instanceof WorkflowNotice || $notice->tone !== 'action'
            || ! $event->notifiable instanceof User || ! $event->notifiable->isAdmin()) {
            return;
        }
        // Each Administrator gets the notice under an id of their own: it is recognised by what it says.
        $same = sha1($key.'|'.$notice->subject.'|'.$notice->body);
        if (isset(self::$passed[$same])) {
            return;
        }
        self::$passed[$same] = true;

        $visitor = DemoVisitor::forSandbox($key);
        $superAdmin = DB::connection('pgsql')->table('public.users')->where('role', Role::SuperAdmin->value)->where('active', true)->value('id');
        if ($visitor === null || $superAdmin === null) {
            return;
        }

        DB::connection('pgsql')->table('public.notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => WorkflowNotice::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $superAdmin,
            'data' => json_encode([
                'subject' => "{$visitor->name}’s demo: {$notice->subject}",
                'body' => "In {$visitor->name}’s demo ({$visitor->email}): {$notice->body} Open their demo to act on it.",
                'link' => route('demo.showcase.open', $visitor, absolute: false),
                'tone' => 'action',
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
