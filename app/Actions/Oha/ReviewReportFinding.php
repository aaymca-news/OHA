<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Marks something the report check flagged as reviewed, with a note on what was checked
 * (it names another YMCA in passing; it is short by design). It stays marked in later
 * versions for as long as the same thing is flagged. Nothing in the report changes.
 */
final class ReviewReportFinding
{
    use EnforcesPolicy, LocksArtefact;

    public function mark(Artefact $report, User $user, string $key, string $message, string $note): void
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new WorkflowRuleBroken('Say what you checked.');
        }

        $this->change($report, $user, 'report.finding_reviewed', $key, fn (array $reviewed) => $reviewed + [$key => [
            'note' => trim($note), 'message' => $message, 'by' => $user->id, 'by_name' => $user->name, 'at' => now()->toIso8601String(),
        ]], ['message' => $message, 'note' => trim($note)]);
    }

    public function unmark(Artefact $report, User $user, string $key): void
    {
        $this->change($report, $user, 'report.finding_reopened', $key, function (array $reviewed) use ($key) {
            unset($reviewed[$key]);

            return $reviewed;
        }, ['message' => $report->reviewed_findings[$key]['message'] ?? null]);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @param  array<string, mixed>  $payload
     */
    private function change(Artefact $report, User $user, string $action, string $key, callable $change, array $payload): void
    {
        DB::transaction(function () use ($report, $user, $action, $change, $payload): void {
            $report = $this->lock($report);
            $this->ensure($user, 'fix', $report);

            $reviewed = $change($report->reviewed_findings ?? []);
            $report->update(['reviewed_findings' => $reviewed === [] ? null : $reviewed]);

            Audit::record($user, $action, $report, $report->assessment, payload: $payload);
        });
    }
}
