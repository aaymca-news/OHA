<?php

namespace App\Http\Middleware;

use App\Demo\Sandboxes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo site only: every request works in the visitor's own private copy (their
 * sandbox, named by their cookie), before the session, sign-in or any data is read. A
 * visitor without one sees the landing page and starts one there; anything else sends
 * them to it.
 */
class UseDemoSandbox
{
    /** Pages a visitor without a private copy may open. */
    private const OPEN = ['/', 'demo/start', 'demo/resume/*', 'up'];

    /** The session cookie of a visitor without a private copy. */
    private const GUEST_SESSION = 'oha_demo_guest';

    /** The usual session cookie's name, as configured. */
    private static ?string $sessionCookie = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (! Sandboxes::enabled()) {
            return $next($request);
        }

        // Start from the main schema and the usual session cookie, whatever an earlier
        // request in this process used.
        Sandboxes::leave();
        self::$sessionCookie ??= (string) config('session.cookie');
        config(['session.cookie' => self::$sessionCookie]);
        $key = $request->cookie(Sandboxes::COOKIE);
        if (is_string($key) && Sandboxes::use($key)) {
            return $next($request);
        }

        if ($request->is(...self::OPEN)) {
            // Without a private copy nobody is signed in: a session of its own (another
            // cookie), so an older sign-in, from before private copies, is never read.
            config(['session.cookie' => self::GUEST_SESSION]);

            return $next($request);
        }

        // No private copy (or it was deleted after two weeks unused): start one first.
        return redirect()->route('home')->withCookie(cookie()->forget(Sandboxes::COOKIE));
    }
}
