<?php

namespace App\Demo;

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The demo site: what is waiting for an Administrator's approval in the visitors' demos,
 * read straight from each one, so the showcase's Super Administrator sees it all (however
 * long ago it was sent) and can go in and act on it.
 */
final class DemoApprovals
{
    /**
     * What waits for approval in one visitor's demo.
     *
     * @return list<string> e.g. "Ghana YMCA: OHA form"
     */
    public static function waiting(string $key): array
    {
        if (! Sandboxes::exists($key)) {
            return [];
        }
        $schema = 'demo_'.$key;

        return DB::connection('pgsql')->table($schema.'.artefacts as a')
            ->join($schema.'.assessments as s', 's.id', '=', 'a.assessment_id')
            ->join($schema.'.movements as m', 'm.id', '=', 's.movement_id')
            ->where('a.state', ArtefactState::PendingApproval->value)
            ->orderBy('m.name')
            ->get(['m.name as movement', 'a.kind'])
            ->map(fn ($row) => $row->movement.': '.self::label((string) $row->kind))
            ->values()->all();
    }

    /**
     * Every visitor with something waiting for approval in their demo, and what.
     *
     * @return list<array{visitor: DemoVisitor, items: list<string>}>
     */
    public static function byVisitor(): array
    {
        $live = Sandboxes::all();

        return DemoVisitor::query()->whereIn('sandbox_key', $live)->orderBy('name')->get()
            ->map(fn (DemoVisitor $v) => ['visitor' => $v, 'items' => self::waiting((string) $v->sandbox_key)])
            ->filter(fn (array $row) => $row['items'] !== [])
            ->values()->all();
    }

    /** How many items wait for approval across every visitor's demo (counted at most once a minute). */
    public static function count(): int
    {
        return (int) Cache::remember('demo:approvals-waiting', 60, fn () => collect(Sandboxes::all())->sum(fn (string $key) => count(self::waiting($key))));
    }

    private static function label(string $kind): string
    {
        return match (ArtefactKind::tryFrom($kind)) {
            ArtefactKind::Form => 'OHA form',
            ArtefactKind::Report => 'Health assessment report',
            ArtefactKind::Odp => 'ODP',
            default => $kind,
        };
    }
}
