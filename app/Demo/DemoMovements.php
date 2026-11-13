<?php

namespace App\Demo;

use App\Models\Movement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The demo site's National Movements go by made-up names, "Sample YMCA 1" to "Sample YMCA
 * 23", so no real YMCA is shown with invented scores. The numbers do not follow the
 * alphabet (or any order of the real names), so a number does not give a movement away.
 * Their country and city go the same way, "Country 1" and "City 1", as a real country or
 * capital would give the movement away; their zone and membership stay as loaded. The
 * demo's own people for each movement (at example.org) take the new name in their title
 * and email.
 * Demo site only (config oha.demo, and oha.demo_sample_names); never on the live site.
 */
final class DemoMovements
{
    /** The real movements (by their original slug), in the order of their sample number. */
    public const ORDER = [
        'malawi', 'senegal', 'ethiopia', 'namibia', 'ghana', 'burundi', 'cameroon', 'liberia',
        'tanzania', 'zimbabwe', 'niger', 'south-sudan', 'gambia', 'madagascar', 'benin', 'zambia',
        'sierra-leone', 'kenya', 'guinea-bissau', 'south-africa', 'togo', 'nigeria', 'guinea-conakry',
    ];

    public static function name(int $number): string
    {
        return 'Sample YMCA '.$number;
    }

    public static function slug(int $number): string
    {
        return 'sample-ymca-'.$number;
    }

    /** The sample slug a real movement's slug becomes, e.g. 'zambia' => 'sample-ymca-16'. */
    public static function slugFor(string $realSlug): ?string
    {
        $index = array_search($realSlug, self::ORDER, true);

        return $index === false ? null : self::slug($index + 1);
    }

    /** The original slug behind a sample one ('sample-ymca-16' => 'zambia'); any other slug as it is. */
    public static function realSlug(string $slug): string
    {
        return preg_match('/^sample-ymca-(\d+)$/', $slug, $m) === 1 ? (self::ORDER[(int) $m[1] - 1] ?? $slug) : $slug;
    }

    /** A movement by its original slug, under its real or its sample name. */
    public static function find(string $realSlug): ?Movement
    {
        return Movement::query()->whereIn('slug', array_filter([$realSlug, self::slugFor($realSlug)]))->first();
    }

    public static function country(int $number): string
    {
        return 'Country '.$number;
    }

    public static function city(int $number): string
    {
        return 'City '.$number;
    }

    /**
     * Gives the movements their sample name, country and city, and their demo people's
     * titles and emails the new name. Safe to run again: what is already made up is left
     * alone (a movement renamed before its country and city were, gets those now).
     *
     * @return int how many movements were changed
     */
    public static function anonymise(): int
    {
        if (! config('oha.demo') || ! config('oha.demo_sample_names')) {
            return 0;
        }

        return DB::transaction(function (): int {
            $changed = 0;
            foreach (self::ORDER as $i => $realSlug) {
                $number = $i + 1;
                $movement = Movement::query()->whereIn('slug', [$realSlug, self::slug($number)])->first();
                if ($movement === null) {
                    continue;
                }
                $place = ['country' => self::country($number), 'city' => self::city($number)];
                if ($movement->slug !== $realSlug) {
                    if ($movement->only(['country', 'city']) !== $place) {
                        $movement->update($place);
                        $changed++;
                    }

                    continue;
                }
                $old = ['slug' => $movement->slug, 'name' => $movement->name];
                $movement->update(['name' => self::name($number), 'slug' => self::slug($number)] + $place);

                // The demo's own people for this movement: "…, Zambia YMCA (demo)" and
                // "name.zambia@example.org" become the sample name and slug.
                User::query()->where('movement_id', $movement->id)->where('email', 'like', '%@example.org')->get()
                    ->each(fn (User $person) => $person->update([
                        'title' => $person->title !== null ? str_replace($old['name'], $movement->name, $person->title) : null,
                        'email' => str_replace('.'.$old['slug'].'@example.org', '.'.$movement->slug.'@example.org', $person->email),
                    ]));
                $changed++;
            }

            return $changed;
        });
    }
}
