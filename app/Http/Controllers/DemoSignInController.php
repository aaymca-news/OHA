<?php

namespace App\Http\Controllers;

use App\Demo\DemoPeople;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The demo site only: one click signs in as the demo account for a role. Anywhere config
 * oha.demo is off (the live site), it answers 404 and signs nobody in.
 */
class DemoSignInController extends Controller
{
    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(config('oha.demo'), 404);
        $email = DemoPeople::ACCOUNTS[$role]['email'] ?? abort(404);
        $user = User::query()->where('email', $email)->where('active', true)->first()
            ?? abort(503, 'The demo accounts are being prepared. Try again in a minute.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
