<?php

namespace App\Providers;

use App\Demo\DemoMail;
use App\Demo\DemoShowcaseInbox;
use App\Models\User;
use App\Support\Audit;
use App\Support\DateFormats;
use App\Support\Navigation;
use App\Support\SystemHealth;
use Carbon\Carbon;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSent;
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

        // Timelines (ODP tasks and assessment deadlines): AAYMCA staff, who assess.
        Gate::define('viewTimelines', fn (User $user) => $user->active && $user->isAssessor());

        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers());

        if (config('oha.demo')) {
            // Notices are handled at once, in the visitor's own private copy (a queued job
            // would run later, outside it).
            config(['queue.default' => 'sync']);
            // Real email only when switched on (config oha.demo_mail); otherwise to the log.
            if (! config('oha.demo_mail')) {
                config(['mail.default' => 'log']);
            }
        }
        // On the demo, email is marked "Demo", never sent to the demo people, and its links
        // open the sender's demo (it does nothing anywhere else).
        Event::listen(MessageSending::class, [DemoMail::class, 'handle']);
        // On the demo, approvals asked for in a visitor's demo also reach the showcase's Super Administrator.
        Event::listen(NotificationSent::class, [DemoShowcaseInbox::class, 'handle']);

        // $date->asDay(), ->asStamp(), ->asMonth(): the platform's date formats, in one place.
        Carbon::mixin(new DateFormats);

        // On a developer's machine: a relation loaded row by row (a query per row) is
        // logged, so it is found and loaded up front. Never on the live site.
        if ($this->app->isLocal()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(fn (Model $model, string $relation) => logger()->warning('Lazy loading '.$model::class.'::'.$relation.' (load it up front).'));
        }

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
