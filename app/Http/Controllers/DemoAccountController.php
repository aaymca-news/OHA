<?php

namespace App\Http\Controllers;

use App\Demo\DemoVisitor;
use App\Demo\Sandboxes;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The demo site only: a visitor's account and the demo that is theirs. They register
 * (name, email, password; no email to confirm), or sign in on any computer, and land on
 * "Choose who to be" in their own demo. They can choose a new password by email (the
 * demo's only email), start their demo over, sign out, or delete it all. Anywhere else
 * (the live site) every page answers 404.
 */
class DemoAccountController extends Controller
{
    /** How long a browser stays signed in to the visitor's demo. */
    private const COOKIE_DAYS = 30;

    public function create(): View
    {
        $this->demoOnly();

        return view('demo.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(DemoVisitor::class, 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], ['email.unique' => 'That email already has a demo account: sign in instead.']);

        $visitor = DemoVisitor::query()->create($data);

        return $this->enter($visitor, 'Your demo is ready. Choose who to be.');
    }

    public function showSignIn(): View
    {
        $this->demoOnly();

        return view('demo.sign-in');
    }

    public function signIn(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $data = $request->validate(['email' => ['required', 'string'], 'password' => ['required', 'string']]);

        $visitor = DemoVisitor::query()->email($data['email'])->first();
        if ($visitor === null || ! Hash::check($data['password'], $visitor->getAuthPassword())) {
            return back()->withErrors(['email' => 'That email and password do not match a demo account.'])->onlyInput('email');
        }

        return $this->enter($visitor, 'Welcome back to your demo. Choose who to be.');
    }

    /** Signs out of the demo on this browser (and out of whoever they were being). */
    public function signOut(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $this->leaveRole($request);

        return redirect()->route('home')->withCookie(cookie()->forget(Sandboxes::COOKIE))
            ->with('status', 'You are signed out of your demo. Sign in again any time to carry on.');
    }

    /** Signs out of whoever they are being, back to "Choose who to be". */
    public function choose(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $this->leaveRole($request);

        return redirect()->route('login');
    }

    /** Throws the visitor's demo away and gives them a clean one, under the same account. */
    public function startOver(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $visitor = DemoVisitor::forSandbox(Sandboxes::current()) ?? abort(404);
        $this->leaveDemo($request);
        Sandboxes::destroy((string) $visitor->sandbox_key);
        $visitor->update(['sandbox_key' => null]);

        return $this->enter($visitor, 'A clean demo is ready: nothing done yet. Choose who to be.');
    }

    /** Deletes the visitor's demo and their account, at once. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $visitor = DemoVisitor::forSandbox(Sandboxes::current()) ?? abort(404);
        $this->leaveDemo($request);
        Sandboxes::destroy((string) $visitor->sandbox_key);
        $visitor->delete();

        return redirect()->route('home')->withCookie(cookie()->forget(Sandboxes::COOKIE))
            ->with('status', 'Your demo and your account are deleted.');
    }

    public function forgot(): View
    {
        $this->demoOnly();

        return view('demo.forgot-password');
    }

    /** Emails a "choose a new password" link to the address the visitor registered with. */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->broker()->sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        // The same answer either way: it never tells whether an email has an account.
        return back()->with('status', 'If that email has a demo account, a link to choose a new password is on its way. It works once, for 60 minutes.');
    }

    public function showReset(Request $request, string $token): View
    {
        $this->demoOnly();

        return view('demo.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->demoOnly();
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));

        $status = $this->broker()->reset($data, function (DemoVisitor $visitor, string $password): void {
            $visitor->update(['password' => $password]);
        });
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'That link has expired or was already used. Ask for a new one.'])->onlyInput('email');
        }

        $visitor = DemoVisitor::query()->email($data['email'])->firstOrFail();

        return $this->enter($visitor, 'Your new password is saved. Choose who to be.');
    }

    /** Into the visitor's own demo (a new one if theirs is gone), on "Choose who to be". */
    private function enter(DemoVisitor $visitor, string $status): RedirectResponse
    {
        if ($visitor->sandbox_key === null || ! Sandboxes::exists($visitor->sandbox_key)) {
            $visitor->sandbox_key = Sandboxes::create();
        }
        $visitor->touch();
        $visitor->save();

        return redirect()->route('login')->withCookie($this->cookie((string) $visitor->sandbox_key))->with('status', $status);
    }

    private function leaveRole(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Before the visitor's demo is deleted: out of whoever they were, and this request's
     * session is not saved at its end (it lived in the demo being deleted).
     */
    private function leaveDemo(Request $request): void
    {
        $this->leaveRole($request);
        $request->session()->setHandler(new NullSessionHandler);
    }

    private function cookie(string $key): Cookie
    {
        return cookie(Sandboxes::COOKIE, $key, self::COOKIE_DAYS * 24 * 60, secure: request()->isSecure(), httpOnly: true, sameSite: 'lax');
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker('demo_visitors');
    }

    private function demoOnly(): void
    {
        abort_unless(Sandboxes::enabled(), 404);
    }
}
