<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Enums\ArtefactKind;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\ReportDraft;
use App\Models\User;
use App\Oha\Report\DraftReport;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The report the platform drafts from the OHA form, corrected on screen by the movement's
 * assessors and the Administrators (whoever may upload the report): started (or started
 * again), each section saved, rewritten from the form, added or removed, its facts brought
 * up to date after a new form upload, or discarded.
 *
 * Each section remembers who saved it last and when. A section saved by someone else after
 * the page was opened is not overwritten: the save stops and says who, so two assessors on
 * different sections never get in each other's way. Every step is in the audit trail.
 */
final class EditReportDraft
{
    use EnforcesPolicy;

    /** How a section's text may be changed, by its kind; the score table and headings are fixed. */
    private const EDITABLE = ['title', 'text', 'list', 'category', 'custom'];

    public function __construct(private readonly DraftReport $drafter) {}

    /** Drafts the report from the OHA form; $again replaces a draft already started. */
    public function start(Artefact $report, User $user, bool $again = false): ReportDraft
    {
        if ($report->kind !== ArtefactKind::Report) {
            throw new WorkflowRuleBroken('Only the report is drafted from the form.');
        }
        $this->ensure($user, 'upload', $report);

        $existing = $report->draft()->with('updater')->first();
        if ($existing !== null && ! $again) {
            throw new WorkflowRuleBroken('A draft was already started, last saved by '.$existing->updater->name.'. Open it, or start again from the form.');
        }

        $draft = $this->drafter->for($report->assessment);

        return DB::transaction(function () use ($report, $user, $draft, $existing): ReportDraft {
            $saved = ReportDraft::query()->updateOrCreate(['artefact_id' => $report->id], [
                'form_upload_id' => $draft['form_upload_id'],
                'sections' => $draft['sections'],
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $assessment = $report->assessment;
            Audit::record($user, $existing !== null ? 'report.draft_restarted' : 'report.draft_started', $saved, $assessment);
            Notify::colleagues($assessment, $user, "Report draft: {$assessment->movement->name}",
                "{$user->name} ".($existing !== null ? 'started the draft report again from the OHA form' : 'drafted the report from the OHA form').'. It can be corrected on screen, section by section.');

            return $saved;
        });
    }

    /**
     * Saves one section as corrected. $seen is when the page showed it last saved (null if
     * never), so a colleague's newer save is not overwritten.
     *
     * @param  array{title?: string|null, text?: string|null, items?: string|null, lines?: list<string|null>}  $input
     */
    public function save(ReportDraft $draft, string $key, array $input, ?string $seen, User $user): ReportDraft
    {
        return $this->change($draft, $user, $key, function (array $section) use ($input, $seen): array {
            if (! in_array($section['kind'], self::EDITABLE, true)) {
                throw new WorkflowRuleBroken('This part comes from the OHA form and cannot be changed here.');
            }
            if (($section['saved_at'] ?? null) !== ($seen !== null && $seen !== '' ? $seen : null)) {
                throw new WorkflowRuleBroken('“'.$section['title'].'” was saved by '.($section['saved_by_name'] ?? 'someone').' at '
                    .Carbon::parse((string) $section['saved_at'])->format('H:i').', after you opened the page. Reload to see their change before saving yours.');
            }

            return match ($section['kind']) {
                'title' => ['lines' => array_map(fn ($l) => trim((string) $l), array_pad(array_slice($input['lines'] ?? [], 0, 3), 3, ''))] + $section,
                'list' => ['items' => self::points($input['items'] ?? '')] + $section,
                'category' => ['text' => self::text($input['text'] ?? ''), 'items' => self::points($input['items'] ?? '')] + $section,
                'custom' => ['title' => self::heading($input['title'] ?? '', $section['title']), 'text' => self::text($input['text'] ?? '')] + $section,
                default => ['text' => self::text($input['text'] ?? '')] + $section,
            };
        }, 'report.draft_section_saved');
    }

    /** Writes one section again from the OHA form, in place of what it says now. */
    public function rewrite(ReportDraft $draft, string $key, User $user): ReportDraft
    {
        $fresh = collect($this->drafter->for($draft->artefact->assessment)['sections'])->firstWhere('key', $key)
            ?? throw new WorkflowRuleBroken('This section is not one the platform writes from the form.');

        return $this->change($draft, $user, $key, fn (array $section): array => $fresh, 'report.draft_section_rewritten');
    }

    /** Adds a section of the assessor's own (Programmes and Projects, Recommendations…) after $after, or at the end. */
    public function add(ReportDraft $draft, string $title, ?string $after, User $user): ReportDraft
    {
        $title = trim($title);
        if (mb_strlen($title) < 2) {
            throw new WorkflowRuleBroken('Give the new section a title.');
        }

        return $this->locked($draft, $user, function (ReportDraft $draft) use ($title, $after, $user): string {
            $sections = $draft->sections;
            $position = $after !== null ? collect($sections)->search(fn ($s) => $s['key'] === $after) : false;
            $new = ['key' => 'custom:'.Str::lower(Str::random(8)), 'title' => $title, 'kind' => 'custom', 'locked' => false, 'text' => '', 'sources' => []]
                + $this->stamp($user);
            array_splice($sections, $position === false ? count($sections) : $position + 1, 0, [$new]);
            $draft->sections = $sections;

            return $title;
        }, 'report.draft_section_added');
    }

    /** Removes a section the assessors added; the template's own sections stay. */
    public function remove(ReportDraft $draft, string $key, User $user): ReportDraft
    {
        return $this->locked($draft, $user, function (ReportDraft $draft) use ($key): string {
            $section = $draft->section($key) ?? throw new WorkflowRuleBroken('That section is no longer in the draft.');
            if ($section['kind'] !== 'custom') {
                throw new WorkflowRuleBroken('Sections of the report template stay; correct or shorten them instead.');
            }
            $draft->sections = array_values(array_filter($draft->sections, fn ($s) => $s['key'] !== $key));

            return $section['title'];
        }, 'report.draft_section_removed');
    }

    /**
     * After a new OHA form upload: the score table, and the scores written in the overall
     * sentence and at the start of each category, brought up to date. The rest of the
     * corrected text stays as it is.
     */
    public function updateFacts(ReportDraft $draft, User $user): ReportDraft
    {
        $fresh = $this->drafter->for($draft->artefact->assessment);
        $sections = collect($fresh['sections'])->keyBy('key');

        return $this->locked($draft, $user, function (ReportDraft $draft) use ($fresh, $sections, $user): string {
            $draft->sections = array_map(function (array $s) use ($sections, $user): array {
                $new = $sections[$s['key']] ?? null;
                if ($new === null) {
                    return $s;
                }

                return match (true) {
                    $s['kind'] === 'scores' => $new,
                    $s['key'] === 'overall' => ['text' => self::refresh($s['text'], $new['text'], '/attained an overall score of [^.]+?points\)\. This reflects [^.]+\./u')] + $this->stamp($user) + $s,
                    $s['kind'] === 'category' && $s['key'] !== 'category:property' => ['text' => self::refresh($s['text'], $new['text'], '/scored [\d.,]+ of \d+ points \([\d.]+%\) in [^,]+, which is [^.]+\./u')] + $this->stamp($user) + $s,
                    default => $s,
                };
            }, $draft->sections);
            $draft->form_upload_id = $fresh['form_upload_id'];

            return 'facts';
        }, 'report.draft_facts_updated');
    }

    public function discard(ReportDraft $draft, User $user): void
    {
        $this->ensure($user, 'upload', $draft->artefact);

        DB::transaction(function () use ($draft, $user): void {
            Audit::record($user, 'report.draft_discarded', $draft->artefact, $draft->artefact->assessment);
            $draft->delete();
        });
    }

    /**
     * Whether the OHA form changed after the draft was made: its scores and figures may be out of date.
     */
    public function formChanged(ReportDraft $draft): bool
    {
        return $this->drafter->sourceUpload($draft->artefact->assessment)?->id !== $draft->form_upload_id;
    }

    /**
     * Changes one section under a lock, stamping who saved it.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $edit
     */
    private function change(ReportDraft $draft, User $user, string $key, callable $edit, string $action): ReportDraft
    {
        return $this->locked($draft, $user, function (ReportDraft $draft) use ($key, $edit, $user): string {
            $found = false;
            $draft->sections = array_map(function (array $s) use ($key, $edit, $user, &$found): array {
                if ($s['key'] !== $key) {
                    return $s;
                }
                $found = true;

                return $this->stamp($user) + $edit($s);
            }, $draft->sections);
            if (! $found) {
                throw new WorkflowRuleBroken('That section is no longer in the draft. Reload the page.');
            }

            return (string) ($draft->section($key)['title'] ?? $key);
        }, $action);
    }

    /**
     * Runs a change on the draft as it is in the database now (locked), saves it, and records it.
     *
     * @param  callable(ReportDraft): string  $change  returns the section's title, for the audit trail
     */
    private function locked(ReportDraft $draft, User $user, callable $change, string $action): ReportDraft
    {
        $this->ensure($user, 'upload', $draft->artefact);

        return DB::transaction(function () use ($draft, $user, $change, $action): ReportDraft {
            $current = ReportDraft::query()->lockForUpdate()->find($draft->id)
                ?? throw new WorkflowRuleBroken('This draft has been discarded. Reload the page.');
            $section = $change($current);
            $current->updated_by = $user->id;
            $current->save();
            Audit::record($user, $action, $current, $current->artefact->assessment, payload: ['section' => $section]);

            return $current;
        });
    }

    /**
     * @return array{saved_by: int, saved_by_name: string, saved_at: string}
     */
    private function stamp(User $user): array
    {
        return ['saved_by' => $user->id, 'saved_by_name' => $user->name, 'saved_at' => now()->toIso8601String()];
    }

    /** The new figures in place of the old, where the sentence that holds them is still there. */
    private static function refresh(string $current, string $fresh, string $pattern): string
    {
        return preg_match($pattern, $fresh, $new) === 1 ? (string) preg_replace($pattern, $new[0], $current, 1) : $current;
    }

    /** Paragraphs as typed, at most 20,000 characters. */
    private static function text(?string $text): string
    {
        return mb_substr(trim(str_replace("\r\n", "\n", (string) $text)), 0, 20000);
    }

    /**
     * One point per line, bullets and blank lines dropped.
     *
     * @return list<string>
     */
    private static function points(?string $lines): array
    {
        $points = array_map(fn ($l) => trim((string) preg_replace('/^\s*[•\-*]\s*/u', '', $l)), explode("\n", str_replace("\r\n", "\n", (string) $lines)));

        return array_slice(array_values(array_filter($points, fn ($p) => $p !== '')), 0, 30);
    }

    private static function heading(?string $title, string $was): string
    {
        $title = trim((string) $title);

        return $title !== '' ? mb_substr($title, 0, 200) : $was;
    }
}
