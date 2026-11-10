<?php

namespace App\Http\Middleware;

use App\Demo\DemoChanges;
use App\Demo\Sandboxes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo site only (global middleware): every request works in the visitor’s own private copy (their
 * sandbox, named by their cookie, set when they register or sign in to their demo), before
 * the session, sign-in or any data is read. A visitor without one may open only the front
 * page and the register, sign-in and password pages; anything else sends them to the front page.
 */
class UseDemoSandbox
{
    /** Pages a visitor without a private copy may open. */
    private const OPEN = [
        '/', 'up', 'demo/showcase',
        'demo/account/register', 'demo/account/sign-in',
        'demo/account/forgot-password', 'demo/account/reset-password', 'demo/account/reset-password/*',
    ];

    /** The session cookie of a visitor without a private copy. */
    private const GUEST_SESSION = 'oha_demo_guest';

    /** The session cookie in the showcase. */
    private const SHOWCASE_SESSION = 'oha_showcase_session';

    /** The session cookie of a visitor account. */
    public static function visitorSession(string $key): string
    {
        return 'oha_demo_s_'.substr($key, 0, 12);
    }

    /** The usual session cookie's name, as configured. */
    private static ?string $sessionCookie = null;

    /** The session's cookie for this request (also on a session already made, as in tests). */
    private static function nameSession(string $name): void
    {
        config(['session.cookie' => $name]);
        foreach (app('session')->getDrivers() as $store) {
            $store->setName($name);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Sandboxes::enabled()) {
            return $next($request);
        }

        // Start from the main schema and the usual session cookie, whatever an earlier
        // request in this process used.
        Sandboxes::leave();
        self::$sessionCookie ??= (string) config('session.cookie');
        self::nameSession(self::$sessionCookie);
        $key = $request->cookie(Sandboxes::COOKIE);
        // Whose changes these are, for the record (DemoChanges): nobody's until a visitor is known.
        DemoChanges::actAs(null);
        if (is_string($key) && Sandboxes::use($key)) {
            // A session of each visitor account's own: a new or different account (or one
            // registered again after being deleted) never finds someone else signed in.
            self::nameSession(self::visitorSession($key));
            if (Sandboxes::shared()) {
                DemoChanges::actAs($key);
            }
            // On "Choose who to be", the visitor's copy catches up with the showcase's new
            // work by itself, as long as they have done nothing of their own in it.
            if ($request->isMethod('GET') && $request->is('login') && ($fresh = Sandboxes::refresh($key)) !== null) {
                $response = $next($request);
                $response->headers->setCookie(Sandboxes::cookie($fresh));

                return $response;
            }

            return $next($request);
        }
        // The showcase: the main schema, where only the Super Administrator signs in (with
        // their email and password; nobody is one click away there).
        if ($key === Sandboxes::SHOWCASE) {
            Sandboxes::useShowcase();
            // A session of its own, kept while the Super Administrator visits a visitor's demo.
            self::nameSession(self::SHOWCASE_SESSION);

            return $next($request);
        }

        if ($request->is(...self::OPEN)) {
            // Without a private copy nobody is signed in: a session of its own (another
            // cookie), so an older sign-in, from before private copies, is never read.
            self::nameSession(self::GUEST_SESSION);
            // Nor an old "remember me" sign-in.
            foreach ($request->cookies->keys() as $name) {
                if (str_starts_with((string) $name, 'remember_')) {
                    $request->cookies->remove($name);
                }
            }

            return $next($request);
        }

        // Not signed in to a demo (or it was deleted): register or sign in first.
        return redirect()->route('home')->withCookie(cookie()->forget(Sandboxes::COOKIE));
    }
}
