<?php

namespace App\Demo;

use App\Support\Navigation;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The demo site's record of every change to its data, and who made it: so the showcase's
 * Super Administrator can delete a visitor's account and undo all that visitor's work.
 *
 * A trigger on each table writes each inserted, changed or deleted row to demo_changes,
 * with the visitor whose request made it (the database setting "oha.visitor", set for
 * each request; empty for the Super Administrator and the platform itself). Undoing a
 * visitor reverses their changes newest first, with any later change built on them (a row
 * they touched, or a row pointing at one they added), whoever made it. Demo site only.
 */
final class DemoChanges
{
    /** Tables never recorded: the platform's machinery, sessions, and the visitors' accounts. */
    private const NOT_RECORDED = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'demo_sandboxes', 'demo_visitors', 'demo_visitor_password_resets', 'demo_changes',
    ];

    /** Sets up the recording on every table of the main schema (run again after new tables appear). */
    public static function install(): void
    {
        $db = self::db();
        $db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.demo_record_change() RETURNS trigger AS $$
            BEGIN
                IF current_setting('oha.undoing', true) = '1' THEN
                    RETURN NULL;
                END IF;
                INSERT INTO public.demo_changes (visitor_key, table_name, op, old_row, new_row, changed_at)
                VALUES (
                    NULLIF(current_setting('oha.visitor', true), ''),
                    TG_TABLE_NAME, left(TG_OP, 1),
                    CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END,
                    CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END,
                    clock_timestamp()
                );
                RETURN NULL;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (self::tables() as $table) {
            $db->statement("DROP TRIGGER IF EXISTS demo_record_change ON public.\"{$table}\"");
            $db->statement("CREATE TRIGGER demo_record_change AFTER INSERT OR UPDATE OR DELETE ON public.\"{$table}\" FOR EACH ROW EXECUTE PROCEDURE public.demo_record_change()");
        }

        // Links between tables may be checked at the end of an undo (as usual, at once, otherwise).
        foreach ($db->select("select c.relname as tbl, k.conname as name from pg_constraint k join pg_class c on c.oid = k.conrelid join pg_namespace n on n.oid = c.relnamespace where k.contype = 'f' and n.nspname = 'public' and not k.condeferrable") as $link) {
            $db->statement("alter table public.\"{$link->tbl}\" alter constraint \"{$link->name}\" deferrable initially immediate");
        }
    }

    /** Whose request this is, for the record: a visitor's key, or nobody (the showcase). */
    public static function actAs(?string $visitorKey): void
    {
        if (config('oha.demo')) {
            self::db()->statement("select set_config('oha.visitor', ?, false)", [$visitorKey ?? '']);
        }
    }

    /**
     * What undoing a visitor would reverse: their changes, and later ones built on them.
     *
     * @return array{ids: list<int>, own: int, others: array<string, int>, actions: list<string>}
     */
    public static function plan(string $visitorKey): array
    {
        $db = self::db();
        $first = $db->table('public.demo_changes')->where('visitor_key', $visitorKey)->min('id');
        if ($first === null) {
            return ['ids' => [], 'own' => 0, 'others' => [], 'actions' => []];
        }
        $changes = $db->table('public.demo_changes')->where('id', '>=', $first)->orderBy('id')->get();
        $links = collect($db->select(<<<'SQL'
            select c.relname as child, a.attname as col, p.relname as parent
            from pg_constraint k
            join pg_class c on c.oid = k.conrelid join pg_class p on p.oid = k.confrelid
            join pg_attribute a on a.attrelid = k.conrelid and a.attnum = k.conkey[1]
            join pg_namespace n on n.oid = c.relnamespace
            where k.contype = 'f' and n.nspname = 'public' and array_length(k.conkey, 1) = 1
        SQL))->groupBy('child');

        $in = [];
        $touched = [];
        $added = [];
        $take = function (object $c) use (&$in, &$touched, &$added): void {
            $in[$c->id] = $c;
            $touched[self::rowKey($c)] = true;
            if ($c->op === 'I') {
                $added[self::rowKey($c)] = true;
            }
        };
        foreach ($changes->where('visitor_key', $visitorKey) as $c) {
            $take($c);
        }
        // Later changes built on theirs: the same row, or a new row pointing at one they added.
        do {
            $grew = false;
            foreach ($changes as $c) {
                if (isset($in[$c->id])) {
                    continue;
                }
                $row = json_decode((string) ($c->new_row ?? $c->old_row), true) ?: [];
                $builtOn = isset($touched[self::rowKey($c)])
                    || ($c->op !== 'D' && collect($links->get($c->table_name, []))->contains(
                        fn ($l) => isset($row[$l->col]) && isset($added[$l->parent.'|'.json_encode([$row[$l->col]])])));
                if ($builtOn) {
                    $take($c);
                    $grew = true;
                }
            }
        } while ($grew);

        $names = DemoVisitor::query()->pluck('name', 'sandbox_key');
        $others = collect($in)->reject(fn ($c) => $c->visitor_key === $visitorKey)
            ->countBy(fn ($c) => $c->visitor_key === null ? 'the showcase (you)' : (string) ($names[$c->visitor_key] ?? 'another visitor'))->all();

        return [
            'ids' => array_map('intval', array_keys($in)),
            'own' => collect($in)->where('visitor_key', $visitorKey)->count(),
            'others' => $others,
            'actions' => collect($in)->where('table_name', 'audit_events')->where('op', 'I')
                ->map(fn ($c) => self::describe(json_decode((string) $c->new_row, true) ?: []))->values()->all(),
        ];
    }

    /** Undoes a visitor's work (and what was built on it), newest first, all at once. Returns how many changes. */
    public static function undo(string $visitorKey): int
    {
        $plan = self::plan($visitorKey);
        if ($plan['ids'] === []) {
            return 0;
        }
        $db = self::db();
        $changes = $db->table('public.demo_changes')->whereIn('id', $plan['ids'])->orderByDesc('id')->get();
        $tables = $changes->pluck('table_name')->unique()->values();

        $db->transaction(function () use ($db, $changes, $tables): void {
            $db->statement("select set_config('oha.undoing', '1', true)");
            $db->statement('set constraints all deferred');
            // The append-only and frozen guards (and the recording) stand aside for the undo only.
            foreach ($tables as $t) {
                $db->statement("alter table public.\"{$t}\" disable trigger user");
            }
            foreach ($changes as $c) {
                self::reverse($db, $c);
            }
            foreach ($tables as $t) {
                $db->statement("alter table public.\"{$t}\" enable trigger user");
            }
            $db->table('public.demo_changes')->whereIn('id', $changes->pluck('id'))->delete();
        });
        Navigation::forget();

        return $changes->count();
    }

    private static function reverse(Connection $db, object $c): void
    {
        $table = 'public."'.$c->table_name.'"';
        $from = "jsonb_populate_record(null::{$table}, ?::jsonb)";
        $pk = self::primaryKey($c->table_name);
        $match = fn (string $json) => $pk !== []
            ? ['('.self::cols($pk).') = (select '.self::cols($pk)." from {$from})", [$json]]
            : ["to_jsonb({$table}.*) = ?::jsonb", [$json]];

        if ($c->op === 'I') {
            [$where, $bind] = $match((string) $c->new_row);
            $db->delete("delete from {$table} where {$where}", $bind);
        } elseif ($c->op === 'U') {
            $cols = array_keys(json_decode((string) $c->old_row, true) ?: []);
            [$where, $bind] = $match((string) ($pk !== [] ? $c->old_row : $c->new_row));
            $db->update('update '.$table.' set ('.self::cols($cols).') = (select '.self::cols($cols)." from {$from}) where {$where}", [(string) $c->old_row, ...$bind]);
        } else {
            $cols = array_keys(json_decode((string) $c->old_row, true) ?: []);
            $db->insert("insert into {$table} (".self::cols($cols).') overriding system value select '.self::cols($cols)." from {$from}", [(string) $c->old_row]);
        }
    }

    /** A row's identity: its table and primary key values. */
    private static function rowKey(object $c): string
    {
        $row = json_decode((string) ($c->new_row ?? $c->old_row), true) ?: [];
        $pk = self::primaryKey((string) $c->table_name);

        return $c->table_name.'|'.json_encode($pk !== [] ? array_map(fn ($k) => $row[$k] ?? null, $pk) : $row);
    }

    /** @return list<string> */
    private static function primaryKey(string $table): array
    {
        static $keys = [];

        return $keys[$table] ??= array_map(fn ($r) => (string) $r->attname, self::db()->select(
            "select a.attname from pg_index i join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey) where i.indrelid = ('public.\"' || ? || '\"')::regclass and i.indisprimary order by a.attnum",
            [$table],
        ));
    }

    /** @param  array<string, mixed>  $event  an audit entry */
    private static function describe(array $event): string
    {
        $movement = isset($event['assessment_id'])
            ? self::db()->table('public.assessments as s')->join('public.movements as m', 'm.id', '=', 's.movement_id')->where('s.id', $event['assessment_id'])->value('m.name')
            : null;

        return trim(($movement ? $movement.': ' : '').ucfirst(str_replace(['.', '_'], ' ', (string) ($event['action'] ?? 'change'))));
    }

    /** @param  list<string>  $cols */
    private static function cols(array $cols): string
    {
        return implode(', ', array_map(fn ($c) => '"'.$c.'"', $cols));
    }

    /** @return list<string> */
    private static function tables(): array
    {
        return collect(self::db()->select("select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'"))
            ->pluck('table_name')->map(fn ($t) => (string) $t)->diff(self::NOT_RECORDED)->values()->all();
    }

    private static function db(): Connection
    {
        return DB::connection('pgsql');
    }
}
