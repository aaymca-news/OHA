<?php

namespace App\Http\Controllers;

use App\Actions\Users\AcceptInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Where an invited person lands from their email to set a password.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        return view('auth.invitation', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function store(Request $request, AcceptInvitation $accept): RedirectResponse
    {
        $user = $accept->handle($request->only('token', 'email', 'password', 'password_confirmation'));

        if (! $user->active) {
            return redirect()->route('login')->withErrors(['email' => 'Your password is set, but this account is not active. Contact an Administrator.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome. Your password is set.');
    }
}
