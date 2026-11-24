<?php

namespace App\Queries;

use App\Enums\Stage;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\User;
use App\Oha\CategoryRows;
use App\Support\MovementCycle;
use Illuminate\Support\Facades\Gate;

/**
 * One National Movement's page (decided 23 Nov 2026), the same for everyone who may see it:
 * its own Board Chairperson and National General Secretary reach it as their dashboard, and
 * AAYMCA staff from the National Movements. Everyone sees the movement's dashboard (analytics
 * only); AAYMCA staff also see the Manage section at its foot, with the assessments, the
 * assessors, the ODP follow-up and the cycle, each still behind its own permission.
 */
final class MovementPage
{
    public function __construct(
        private readonly DashboardAnalytics $analytics,
        private readonly Journeys $journeys,
    ) {}

    /**
     * Everything the page shows, for this person.
     *
     * @return array<string, mixed>
     */
    public function for(User $user, Movement $movement): array
    {
        $gate = Gate::forUser($user);
        $status = MovementStatus::query()->with('band')->findOrFail($movement->id);
        $snapshot = MovementSnapshot::of($status);
        $underWay = $movement->assessmentUnderWay();
        $lastDone = $movement->lastAssessmentDone();
        // The assessment its cycle follows: the one under way, else the last one done.
        $cycleOf = $underWay ?? $lastDone;
        $followsUp = $lastDone !== null && $gate->allows('viewFollowUp', $lastDone);

        $assessments = $movement->assessments()->with(['score.band', 'artefacts.status', 'workItem'])->latest('assessed_on')->latest('id')->get()
            ->filter(fn (Assessment $a) => $gate->allows('view', $a));

        return [
            'movement' => $movement->load('zone', 'membershipStatus'),
            'status' => $status,
            // The movement's own Board Chairperson or National General Secretary: their dashboard.
            'own' => $user->isOfMovement($movement),
            'manage' => $user->isSecretariat(),
            'data' => $this->analytics->movement($status),
            'latest' => $snapshot->latest,
            'profile' => $snapshot->profile,
            'assessments' => $assessments,
            // Each scored assessment's category scores, oldest first (the last five), to compare.
            'history' => $assessments->filter(fn (Assessment $a) => $a->score !== null)->take(5)->reverse()->values()
                ->map(fn (Assessment $a) => ['assessment' => $a, 'rows' => collect(CategoryRows::for($a))->keyBy('code')]),
            'assessors' => $movement->activeAssessors()->get(),
            'chair' => $movement->chair()->first(),
            'generalSecretary' => $movement->generalSecretary()->first(),
            'underWay' => $underWay,
            // Stage 1 done (closed): its follow-ups are under way until the cycle is closed.
            'lastDone' => $lastDone,
            // In follow-up: closed, its cycle not closed yet (the next assessment waits for it).
            'followingUp' => $lastDone !== null && ! $lastDone->isFollowUpClosed(),
            // Its cycle's journey, baseline by baseline, for those who follow it up.
            'journey' => $followsUp ? $this->journeys->of($lastDone) : [],
            'stage' => Stage::of($cycleOf ?? $snapshot->latest),
        ] + ($user->isSecretariat() ? $this->manage($user, $movement, $status, $lastDone, $cycleOf, $followsUp) : []);
    }

    /**
     * The Manage section, for AAYMCA staff only.
     *
     * @return array<string, mixed>
     */
    private function manage(User $user, Movement $movement, MovementStatus $status, ?Assessment $lastDone, ?Assessment $cycleOf, bool $followsUp): array
    {
        $gate = Gate::forUser($user);

        // Every assignment change (and hand-back), newest first: who was assessing, and since when.
        $assignmentHistory = AuditEvent::query()->with('actor')->where('action', 'movement.assessors_changed')
            ->where('subject_type', $movement->getMorphClass())->where('subject_id', $movement->id)
            ->latest('id')->get();
        $assignedSince = [];
        foreach ($assignmentHistory->reverse() as $event) {
            foreach ($event->payload['added'] ?? [] as $id) {
                $assignedSince[$id] = $event->occurred_at;
            }
        }

        return [
            'assignmentHistory' => $assignmentHistory,
            'assignedSince' => $assignedSince,
            'evidenceCount' => $movement->evidence()->count(),
            // Stage 2: how its ODP is being carried out, for those who follow it up.
            'followUpProgress' => $followsUp ? OdpProgress::of($lastDone) : null,
            'canOpen' => $gate->allows('openAssessment', $movement),
            'canAssign' => $gate->allows('assignAssessors', $movement),
            'cycle' => $cycleOf !== null ? MovementCycle::steps($cycleOf, $status->next_assessment_on, 'its') : null,
            'cycleOf' => $cycleOf,
        ];
    }
}
