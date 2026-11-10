<?php

use App\Demo\Sandboxes;
use App\Exceptions\WorkflowRuleBroken;
use App\Http\Middleware\UseDemoSandbox;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/dashboard');

        // The demo site only: each visitor's own private copy, chosen before the session,
        // sign-in or any data is read. Its key is read first, so it is not encrypted (it is
        // a random key, like a session id). Global, not in the "web" group: the router
        // builds a route's controller (and some, like Fortify's, open the session and the
        // sign-in) before the route's own middleware runs.
        $middleware->encryptCookies(except: [Sandboxes::COOKIE]);
        $middleware->append(UseDemoSandbox::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A refused workflow action is not an error page: send the person back with the reason.
        $exceptions->render(function (WorkflowRuleBroken $e, Request $request) {
            if (! $request->expectsJson()) {
                return back()->withInput()->withErrors(['action' => $e->getMessage()]);
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
