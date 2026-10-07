<?php

namespace App\Http\Controllers;

use App\Actions\Users\SignOutOtherSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A user's own security settings: their password, and the browsers and devices
 * they are signed in on. (Their photo and details are on My profile.)
 */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        $sessions = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(fn (object $s) => [
                'current' => $s->id === $request->session()->getId(),
                'ip' => $s->ip_address,
                'agent' => (string) $s->user_agent,
                'last_active' => Carbon::createFromTimestamp($s->last_activity),
            ]);

        return view('security.show', ['user' => $user, 'sessions' => $sessions]);
    }

    public function signOutOtherSessions(Request $request, SignOutOtherSessions $action): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $count = $action->handle($request->user(), (string) $request->input('password'), $request->session()->getId());

        return back()->with('status', $count === 1 ? 'Signed out of 1 other session.' : "Signed out of {$count} other sessions.");
    }
}
