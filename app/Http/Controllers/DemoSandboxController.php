<?php

namespace App\Http\Controllers;

use App\Demo\Sandboxes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The demo site only: a visitor starts their own private copy, comes back to it with
 * its private link (on another computer or browser), or starts over with a clean one.
 */
class DemoSandboxController extends Controller
{
    /** How long the browser remembers the visitor's private copy (it is deleted after 14 days unused anyway). */
    private const COOKIE_MINUTES = 60 * 24 * 60;

    public function start(Request $request): RedirectResponse
    {
        abort_unless(Sandboxes::enabled(), 404);
        $key = Sandboxes::current() ?? Sandboxes::create();

        return redirect()->route('login')->withCookie($this->cookie($key))
            ->with('status', 'Your private demo is ready. Choose who to be.');
    }

    /**
     * Opens the visitor's own copy from its private link; from a demo email, straight on
     * to the page the email points to ("next", a path on this site).
     */
    public function resume(Request $request, string $key): RedirectResponse
    {
        abort_unless(Sandboxes::enabled(), 404);
        if (! Sandboxes::exists($key)) {
            return redirect()->route('home')->with('status', 'That demo no longer exists (a demo unused for two weeks is deleted). Start a new one.');
        }

        $next = $request->query('next');
        // Only a path on this site (never "//elsewhere" or "/\elsewhere").
        $onSite = is_string($next) && preg_match('#^/(?![/\\\\])#', $next) === 1;

        return ($onSite ? redirect()->to($next) : redirect()->route('login'))->withCookie($this->cookie($key));
    }

    /** Throws the visitor's copy away and gives them a clean one. */
    public function startOver(Request $request): RedirectResponse
    {
        abort_unless(Sandboxes::enabled(), 404);
        Auth::guard('web')->logout();
        $old = Sandboxes::current();
        if ($old !== null) {
            Sandboxes::destroy($old);
        }
        $key = Sandboxes::create();

        return redirect()->route('login')->withCookie($this->cookie($key))
            ->with('status', 'A clean demo is ready: nothing done yet. Choose who to be.');
    }

    private function cookie(string $key): Cookie
    {
        return cookie(Sandboxes::COOKIE, $key, self::COOKIE_MINUTES, secure: request()->isSecure(), httpOnly: true, sameSite: 'lax');
    }
}
