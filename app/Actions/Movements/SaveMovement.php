<?php

namespace App\Actions\Movements;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * An Administrator adds a National Movement, or corrects one's details. The web address
 * of a movement (its slug) is made from the country when it is added, and never changes
 * after, so links stay valid. The country is what an uploaded OHA form is checked against.
 * Each change is recorded on the movement.
 */
final class SaveMovement
{
    /**
     * @param  array{name: string, country: string, city: string, zone_id: int, membership_status_id: int, planned_on?: string|null}  $details
     */
    public function handle(User $by, array $details, ?Movement $movement = null): Movement
    {
        $response = $movement === null
            ? Gate::forUser($by)->inspect('create', Movement::class)
            : Gate::forUser($by)->inspect('update', $movement);
        if ($response->denied()) {
            throw new WorkflowRuleBroken((string) $response->message());
        }

        // A planned first assessment, as a month: "2027-03" is March 2027.
        $planned = ! empty($details['planned_on']) ? Carbon::createFromFormat('!Y-m', $details['planned_on']) : null;
        $values = [
            'name' => trim($details['name']),
            'country' => trim($details['country']),
            'city' => trim($details['city']),
            'zone_id' => $details['zone_id'],
            'membership_status_id' => $details['membership_status_id'],
            'planned_assessment_on' => $planned?->toDateString(),
            'planned_assessment_label' => $planned?->format('M Y'),
        ];

        $clash = Movement::query()->whereRaw('lower(name) = ?', [mb_strtolower($values['name'])])
            ->when($movement, fn ($q, $m) => $q->whereKeyNot($m->id))->exists();
        if ($clash) {
            throw new WorkflowRuleBroken("There is already a National Movement called {$values['name']}.");
        }

        return DB::transaction(function () use ($by, $values, $movement): Movement {
            if ($movement === null) {
                $movement = Movement::query()->create($values + ['slug' => $this->slug($values['country'])]);
                Audit::record($by, 'movement.created', $movement, payload: ['name' => $movement->name, 'country' => $movement->country]);

                return $movement;
            }

            $before = $movement->only(array_keys($values));
            $movement->update($values);
            $changed = array_keys($movement->getChanges());
            if (array_diff($changed, ['updated_at']) !== []) {
                Audit::record($by, 'movement.updated', $movement, payload: [
                    'from' => array_intersect_key($before, array_flip($changed)),
                    'to' => array_intersect_key($movement->only(array_keys($values)), array_flip($changed)),
                ]);
            }

            return $movement;
        });
    }

    /** "south-sudan", or "south-sudan-2" if that is taken. */
    private function slug(string $country): string
    {
        $base = Str::limit(Str::slug($country), 36, '') ?: 'movement';
        $slug = $base;
        for ($n = 2; Movement::query()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
