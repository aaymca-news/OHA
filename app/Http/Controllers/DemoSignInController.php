<?php

namespace App\Http\Controllers;

use App\Demo\DemoPeople;
use App\Demo\Sandboxes;
use App\Models\Movement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The demo site only: "Choose who to be". One click signs in as one of the demo's people:
 * the Administrator, the AAYMCA assessor, or a movement's Board Chairperson or National
 * General Secretary. Anywhere config oha.demo is off (the live site), it answers 404.
 */
class DemoSignInController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(config('oha.demo'), 404);
        // Only in a visitor's own demo: never in the showcase, where only the Super
        // Administrator signs in.
        abort_if(Sandboxes::enabled() && Sandboxes::current() === null, 404);
        $data = $request->validate([
            'role' => ['required', Rule::in(DemoPeople::keys())],
            'movement' => [Rule::requiredIf(isset(DemoPeople::MOVEMENT[$request->input('role')])), 'nullable', 'string'],
        ]);
        $movement = isset($data['movement']) ? Movement::query()->where('slug', $data['movement'])->first() : null;

        $user = DemoPeople::find($data['role'], $movement)
            ?? abort(404, 'That person is not in this demo.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
