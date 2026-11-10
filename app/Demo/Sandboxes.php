<?php

namespace App\Demo;

use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The demo site's private copies: each visitor works in their own "sandbox", the
 * platform's tables in a PostgreSQL schema of their own (demo_{key}) and their files in a
 * folder of their own. It is a copy of the showcase, taken when they register or start
 * over (DemoVisitor). What one visitor does, no other visitor sees.
 *
 * The showcase is the main schema and folder: the demo's Super Administrator works there
 * (from demo/showcase), and every new copy starts from it, with the work done so far.
 *
 * A request uses a sandbox (use()) through the connection "sandbox", made the default,
 * so every query, model, session and cache entry stays in that visitor's schema. The list
 * of sandboxes stays in the main schema (connection "pgsql"). Only on the demo site.
 */
final class Sandboxes
{
    public const COOKIE = 'oha_demo';

    /** The cookie's value for the showcase (the Super Administrator's own work). */
    public const SHOWCASE = 'showcase';

    /** What a copy leaves out of the showcase: its machinery, sessions and the visitors' accounts. */
    private const NOT_COPIED = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'demo_sandboxes', 'demo_visitors', 'demo_visitor_password_resets',
    ];

    /** The sandbox this request uses, if any. */
    private static ?string $current = null;

    /** Whether this request is in the showcase. */
    private static bool $showcase = false;

    public static function enabled(): bool
    {
        return (bool) config('oha.demo') && (bool) config('oha.demo_sandboxes');
    }

    public static function current(): ?string
    {
        return self::$current;
    }

    /** A key looks like one (only then is it used to build a schema name). */
    public static function valid(?string $key): bool
    {
        return is_string($key) && preg_match('/^[a-z0-9]{24}$/', $key) === 1;
    }

    public static function exists(string $key): bool
    {
        return self::valid($key) && self::registry()->where('key', $key)->exists();
    }

    /**
     * Works in this sandbox for the rest of the request (or command). False if it does not
     * exist (any more).
     */
    public static function use(string $key): bool
    {
        if (! self::exists($key)) {
            return false;
        }
        config(['database.connections.sandbox' => array_merge((array) config('database.connections.pgsql'), ['search_path' => self::schema($key)])]);
        DB::purge('sandbox');
        DB::setDefaultConnection('sandbox');
        config([
            'filesystems.disks.'.config('oha.disk').'.root' => self::folder($key),
            // Rate limits and the sidebar's cache in the visitor's own tables, like the rest.
            'cache.stores.database.connection' => 'sandbox',
            'cache.stores.database.lock_connection' => 'sandbox',
        ]);
        Storage::forgetDisk((string) config('oha.disk'));
        self::$current = $key;

        // Remember it is in use (at most every ten minutes), so unused ones can be deleted,
        // and its visitor's account with it.
        if (self::registry()->where('key', $key)->where('last_used_at', '<', now()->subMinutes(10))->update(['last_used_at' => now()]) > 0) {
            self::visitors()->where('sandbox_key', $key)->update(['updated_at' => now()]);
        }

        return true;
    }

    /** Back to the main schema and folder (for commands that go through several). */
    public static function leave(): void
    {
        DB::setDefaultConnection('pgsql');
        config(['filesystems.disks.'.config('oha.disk').'.root' => self::showcaseFolder()]);
        Storage::forgetDisk((string) config('oha.disk'));
        self::$current = null;
        self::$showcase = false;
    }

    /** Works in the showcase (the main schema and folder) for the rest of the request. */
    public static function useShowcase(): void
    {
        self::leave();
        self::$showcase = true;
    }

    public static function showcase(): bool
    {
        return self::$showcase;
    }

    /** Whether the showcase has new work since this sandbox was copied from it. */
    public static function showcaseChangedSince(string $key): bool
    {
        $copied = self::registry()->where('key', $key)->value('created_at');
        $latest = DB::connection('pgsql')->table('public.audit_events')->max('occurred_at');

        return $copied !== null && $latest !== null && Carbon::parse((string) $latest)->gt(Carbon::parse((string) $copied));
    }

    /**
     * Makes a new sandbox, ready to use: its schema and every table, and a copy of the
     * showcase (its people, named after the visitor by name(), and the work done in it, with
     * its files). When the most allowed exist, the least recently used is deleted first.
     */
    public static function create(): string
    {
        if (! self::enabled()) {
            throw new RuntimeException('Private demo copies exist only on the demo site.');
        }
        $max = max(1, (int) config('oha.demo_sandbox_max'));
        foreach (self::registry()->orderBy('last_used_at')->limit(max(0, self::registry()->count() - $max + 1))->pluck('key') as $oldest) {
            self::destroy((string) $oldest);
        }

        $key = Str::lower(Str::random(24));
        while (! self::valid($key) || self::registry()->where('key', $key)->exists()) {
            $key = Str::lower(Str::random(24));
        }
        DB::connection('pgsql')->statement('CREATE SCHEMA '.self::schema($key));
        self::registry()->insert(['key' => $key, 'created_at' => now(), 'last_used_at' => now()]);
        File::ensureDirectoryExists(self::folder($key));

        try {
            self::use($key);
            Artisan::call('migrate', ['--database' => 'sandbox', '--force' => true]);
            if (DB::connection('pgsql')->table('public.movements')->exists()) {
                self::copyShowcase($key);
            } else {
                // No showcase yet (a new demo site): the movements and the demo people.
                Artisan::call('db:seed', ['--database' => 'sandbox', '--class' => DemoSeeder::class, '--force' => true]);
            }
        } catch (\Throwable $e) {
            self::leave();
            self::destroy($key);
            throw $e;
        }

        return $key;
    }

    /**
     * Names the demo people in a sandbox $name: the visitor's own name, on every role they
     * can be (the Super Administrator keeps theirs).
     */
    public static function name(string $key, string $name): void
    {
        if (self::exists($key) && trim($name) !== '') {
            DB::connection('pgsql')->table(self::schema($key).'.users')->where('email', 'like', '%@example.org')->update(['name' => trim($name)]);
        }
    }

    /**
     * Copies the showcase into a new sandbox, whose tables the migrations just made: every
     * table's rows, the id counters, and the stored files. Its links between tables (some
     * point at each other in a loop, so no order would do) are checked once, at the end.
     */
    private static function copyShowcase(string $key): void
    {
        $db = DB::connection('pgsql');
        $to = self::schema($key);
        $tables = collect($db->select("select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'"))
            ->pluck('table_name')->map(fn ($t) => (string) $t)->diff(self::NOT_COPIED)->values()->all();
        $links = $db->select(<<<'SQL'
            select c.relname as tbl, k.conname as name
            from pg_constraint k
            join pg_class c on c.oid = k.conrelid
            join pg_namespace n on n.oid = c.relnamespace
            where k.contype = 'f' and n.nspname = ?
        SQL, [$to]);

        $db->transaction(function () use ($db, $tables, $links, $to): void {
            // In this copy, the links may be checked at the end of the transaction (as usual,
            // at once, everywhere else); here, they are.
            foreach ($links as $link) {
                $db->statement("alter table {$to}.\"{$link->tbl}\" alter constraint \"{$link->name}\" deferrable initially immediate");
            }
            $db->statement('set constraints all deferred');

            // The migrations already wrote the reference rows (categories, bands...): the
            // showcase's rows replace them. (The audit log, append-only, is still empty.)
            foreach ($tables as $table) {
                if ($db->table("{$to}.{$table}")->exists()) {
                    $db->table("{$to}.{$table}")->delete();
                }
            }
            foreach ($tables as $table) {
                $columns = collect($db->select("select column_name, column_default, is_identity, is_generated from information_schema.columns where table_schema = 'public' and table_name = ?", [$table]))
                    ->reject(fn ($c) => $c->is_generated === 'ALWAYS');
                $list = $columns->map(fn ($c) => '"'.$c->column_name.'"')->implode(', ');
                $db->statement("insert into {$to}.\"{$table}\" ({$list}) overriding system value select {$list} from public.\"{$table}\"");

                // The id counters carry on after the copied rows.
                foreach ($columns->filter(fn ($c) => $c->is_identity === 'YES' || str_starts_with((string) $c->column_default, 'nextval(')) as $c) {
                    $db->statement("select setval(pg_get_serial_sequence('{$to}.\"{$table}\"', '{$c->column_name}'), coalesce((select max(\"{$c->column_name}\") from {$to}.\"{$table}\"), 0) + 1, false)");
                }
            }
        });

        if (File::isDirectory(self::showcaseFolder())) {
            File::copyDirectory(self::showcaseFolder(), self::folder($key));
        }
    }

    /** The showcase's files: the platform's usual folder (another in tests). */
    private static function showcaseFolder(): string
    {
        return (string) (config('oha.demo_showcase_folder') ?? storage_path('app/private/oha'));
    }

    /** Deletes a sandbox: its schema (with every table) and its files. */
    public static function destroy(string $key): void
    {
        if (! self::valid($key)) {
            return;
        }
        if (self::$current === $key) {
            self::leave();
        }
        DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS '.self::schema($key).' CASCADE');
        self::registry()->where('key', $key)->delete();
        File::deleteDirectory(self::folder($key));
    }

    /**
     * Deletes the sandboxes unused for config oha.demo_sandbox_days, with their visitors'
     * accounts, and the accounts unused that long whose demo is already gone.
     *
     * @return int how many sandboxes were deleted
     */
    public static function prune(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('oha.demo_sandbox_days')));
        $old = self::registry()->where('last_used_at', '<', $cutoff)->pluck('key');
        foreach ($old as $key) {
            self::destroy((string) $key);
            self::visitors()->where('sandbox_key', $key)->delete();
        }
        self::visitors()->where('updated_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('sandbox_key')->orWhereNotIn('sandbox_key', self::registry()->select('key')))
            ->delete();

        return $old->count();
    }

    /** @return list<string> every sandbox's key */
    public static function all(): array
    {
        return self::registry()->pluck('key')->map(fn ($k) => (string) $k)->all();
    }

    private static function schema(string $key): string
    {
        return 'demo_'.$key;
    }

    private static function folder(string $key): string
    {
        return storage_path('app/private/oha-sandboxes/'.$key);
    }

    /** @return Builder */
    private static function registry()
    {
        return DB::connection('pgsql')->table('demo_sandboxes');
    }

    /** @return Builder the visitors' accounts (DemoVisitor), in the main schema */
    private static function visitors()
    {
        return DB::connection('pgsql')->table('demo_visitors');
    }
}
