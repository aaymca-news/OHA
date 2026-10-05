<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Navigation;
use App\Support\SystemHealth;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alliance-wide statistics: every AAYMCA user, not the Board Chairpersons.
        Gate::define('viewAllianceStats', fn (User $user) => $user->active && $user->isSecretariat());

        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers());

        $this->recordSignInEvents();

        // Every signed-in page: the missing-roles banner, the sidebar and the unread count.
        View::composer('components.layouts.app', function (\Illuminate\View\View $view): void {
            $user = auth()->user();
            $view->with([
                'health' => SystemHealth::check(),
                'navigation' => $user instanceof User ? app(Navigation::class)->for($user) : [],
                'unread' => $user instanceof User ? $user->unreadNotifications()->count() : 0,
            ]);
        });
    }

    /**
     * Sign-ins, sign-outs, failed attempts, lockouts and password resets go in the
     * audit trail. People are told this on the sign-in page.
     */
    private function recordSignInEvents(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                Audit::record($event->user, 'auth.signed_in', $event->user, payload: ['remember' => $event->remember]);
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                Audit::record($event->user, 'auth.signed_out', $event->user);
            }
        });

        // Fortify reports a failed sign-in without the user, so find them by the email typed.
        Event::listen(Failed::class, function (Failed $event): void {
            $user = $event->user instanceof User ? $event->user
                : User::query()->where('email', strtolower((string) ($event->credentials['email'] ?? '')))->first();
            if ($user !== null) {
                Audit::record(null, 'auth.sign_in_failed', $user);
            }
        });

        Event::listen(Lockout::class, function (Lockout $event): void {
            $user = User::query()->where('email', strtolower((string) $event->request->input('email')))->first();
            if ($user !== null) {
                Audit::record(null, 'auth.locked_out', $user);
            }
        });

        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                Audit::record($event->user, 'auth.password_reset', $event->user);
            }
        });
    }
}
