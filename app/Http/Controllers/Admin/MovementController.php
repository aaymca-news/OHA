<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Movements\SaveMovement;
use App\Http\Controllers\Controller;
use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\Zone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Adding a National Movement, and correcting one's details: the Administrators' screen.
 * The rules are in SaveMovement; the routes only let the Administrators in.
 */
class MovementController extends Controller
{
    public function create(): View
    {
        return view('admin.movements.form', ['movement' => null] + $this->choices());
    }

    public function store(Request $request, SaveMovement $save): RedirectResponse
    {
        $movement = $save->handle($request->user(), $this->validated($request));

        return redirect()->route('movements.show', $movement)
            ->with('status', "{$movement->name} has been added. Next, invite its Board Chairperson and National General Secretary in Users & Roles.");
    }

    public function edit(Movement $movement): View
    {
        return view('admin.movements.form', ['movement' => $movement] + $this->choices());
    }

    public function update(Request $request, Movement $movement, SaveMovement $save): RedirectResponse
    {
        $save->handle($request->user(), $this->validated($request, $movement), $movement);

        return redirect()->route('movements.show', $movement)->with('status', 'The movement’s details are saved.');
    }

    /**
     * @return array{name: string, country: string, city: string, zone_id: int, membership_status_id: int, planned_on?: string|null}
     */
    private function validated(Request $request, ?Movement $movement = null): array
    {
        /** @var array{name: string, country: string, city: string, zone_id: int|string, membership_status_id: int|string, planned_on?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('movements', 'name')->ignore($movement?->id)],
            'country' => ['required', 'string', 'max:60'],
            'city' => ['required', 'string', 'max:60'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'membership_status_id' => ['required', 'integer', 'exists:membership_statuses,id'],
            'planned_on' => ['nullable', 'date_format:Y-m'],
        ], ['name.unique' => 'There is already a National Movement with this name.']);

        return ['zone_id' => (int) $data['zone_id'], 'membership_status_id' => (int) $data['membership_status_id']] + $data;
    }

    /** @return array{zones: mixed, memberships: mixed} */
    private function choices(): array
    {
        return [
            'zones' => Zone::query()->orderBy('sort_order')->get(),
            'memberships' => MembershipStatus::query()->orderBy('sort_order')->get(),
        ];
    }
}
