<?php

namespace App\Console\Commands;

use App\Demo\DemoMovements;
use App\Demo\DemoSite;
use App\Demo\DemoVisitor;
use App\Demo\Sandboxes;
use App\Models\Movement;
use App\Support\Navigation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The demo site only: a fresh start under the sample names. The movements become "Sample
 * YMCA 1" to "Sample YMCA 23" (their country, city, zone and membership as they were),
 * and everything fed into the demo is cleared: every assessment, with its forms, reports,
 * ODPs, scores, signatures, tasks, follow-ups, evidence and files, and the history and
 * notifications that named the movements. The people stay: the demo's own, the visitors'
 * accounts and the Super Administrator (with their photos). Unlike oha:demo-reset, which
 * starts the whole demo again, accounts included.
 */
#[Signature('oha:demo-fresh-start {--force : Run without asking}')]
#[Description('Demo site only: rename the movements Sample YMCA 1–23 and clear every assessment and uploaded file, keeping the accounts')]
class DemoFreshStart extends Command
{
    /** What was fed into the demo, cleared in full (the accounts, movements and settings are not here). */
    public const CLEARED = [
        'artefact_comments', 'artefacts', 'assessments', 'audit_events', 'board_signatures', 'category_scores',
        'documents', 'drive_sync_runs', 'evidence', 'evidence_odp_tasks', 'evidence_questions', 'form_findings',
        'form_uploads', 'notifications', 'odp_follow_up_tasks', 'odp_follow_ups', 'odp_reviews', 'odp_tasks',
        'report_drafts', 'sent_reminders', 'timeline_items', 'demo_changes',
    ];

    public function handle(): int
    {
        if (($refusal = DemoSite::refusal()) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }
        $this->line('This renames the movements Sample YMCA 1 to 23 and clears every assessment, uploaded file, notification and history entry in the demo.');
        $this->line('It keeps the demo people, the visitors’ accounts and the Super Administrator.');
        if (! $this->option('force') && ! $this->confirm('Go ahead?')) {
            return self::SUCCESS;
        }

        // Private copies (when the demo is not one shared demo) are made again from the fresh showcase.
        if (Sandboxes::enabled() && ! Sandboxes::shared()) {
            foreach (Sandboxes::all() as $key) {
                Sandboxes::destroy($key);
            }
            DemoVisitor::query()->update(['sandbox_key' => null]);
        }

        $renamed = DemoMovements::anonymise();
        $assessments = DB::table('assessments')->count();

        DB::transaction(function (): void {
            // A movement's follow-up pause belonged to its old assessment.
            Movement::query()->update(['follow_up_paused_until' => null, 'follow_up_pause_reason' => null, 'follow_up_paused_by' => null]);
            // The audit trail is append-only, and guarded against being emptied: the guard
            // stands aside for this one clearing, as for a visitor's undo.
            DB::statement('alter table audit_events disable trigger user');
            DB::statement('truncate table '.collect(self::CLEARED)->map(fn ($t) => '"'.$t.'"')->join(', ').' restart identity');
            DB::statement('alter table audit_events enable trigger user');
        });

        // The uploaded files go too; the profile photos (avatars/) stay with their accounts.
        $disk = Storage::disk((string) config('oha.disk'));
        foreach ($disk->directories() as $directory) {
            if ($directory !== 'avatars') {
                $disk->deleteDirectory($directory);
            }
        }
        foreach ($disk->files() as $file) {
            $disk->delete($file);
        }
        // The menus' counts were worked out from what is now gone.
        Navigation::forget();

        $this->info("Done: {$renamed} movements renamed, {$assessments} assessments cleared with their files. The accounts are as they were.");

        return self::SUCCESS;
    }
}
