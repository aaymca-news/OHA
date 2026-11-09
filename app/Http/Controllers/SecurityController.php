<?php

namespace App\Http\Controllers;

use App\Actions\Users\SignOutOtherSessions;
use App\Support\SignedInDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A user's own security settings: their password, and the browsers and devices
 * they are signed in on (also shown on My profile).
 */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        return view('security.show', [
            'user' => $request->user(),
            'sessions' => SignedInDevices::of($request->user(), $request->session()->getId()),
        ]);
    }

    /** Signs out one other device (device=its key), or every other one. */
    public function signOutOtherSessions(Request $request, SignOutOtherSessions $action): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'device' => ['nullable', 'string', 'size:32']],
            ['password.required' => 'Type your password to sign out another device.']);

        $count = $action->handle($request->user(), $data['password'], $request->session()->getId(), $data['device'] ?? null);

        return back()->with('status', match (true) {
            isset($data['device']) => 'That device is signed out. It can sign in again with your password.',
            $count === 1 => 'Signed out of 1 other device.',
            default => "Signed out of {$count} other devices.",
        });
    }
}
