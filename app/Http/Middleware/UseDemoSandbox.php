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

    public function handle(Request $request, Closure $next): Response
    {
        if (! Sandboxes::enabled()) {
            return $next($request);
        }

        // Start from the main schema, whatever an earlier request in this process used.
        Sandboxes::leave();
        $key = $request->cookie(Sandboxes::COOKIE);
        if (is_string($key) && Sandboxes::use($key)) {
            return $next($request);
        }

        if ($request->is(...self::OPEN)) {
            return $next($request);
        }

        // No private copy (or it was deleted after two weeks unused): start one first.
        return redirect()->route('home')->withCookie(cookie()->forget(Sandboxes::COOKIE));
    }
}
