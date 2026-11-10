<?php

namespace App\Demo;

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
 * folder of their own, made from scratch (the 23 movements and the demo people) when they
 * register (DemoVisitor). What one visitor does, no other visitor sees.
 *
 * A request uses a sandbox (use()) through the connection "sandbox", made the default,
 * so every query, model, session and cache entry stays in that visitor's schema. The list
 * of sandboxes stays in the main schema (connection "pgsql"). Only on the demo site.
 */
final class Sandboxes
{
    public const COOKIE = 'oha_demo';

    /** The sandbox this request uses, if any. */
    private static ?string $current = null;

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
        config(['filesystems.disks.'.config('oha.disk').'.root' => storage_path('app/private/oha')]);
        Storage::forgetDisk((string) config('oha.disk'));
        self::$current = null;
    }

    /**
     * Makes a new sandbox, ready to use: its schema, every table, the 23 movements and the
     * demo people. When the most allowed exist, the least recently used is deleted first.
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
            Artisan::call('db:seed', ['--database' => 'sandbox', '--class' => DemoSeeder::class, '--force' => true]);
        } catch (\Throwable $e) {
            self::leave();
            self::destroy($key);
            throw $e;
        }

        return $key;
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
