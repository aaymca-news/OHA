<?php

namespace App\Http\Controllers;

use App\Demo\DemoChanges;
use App\Demo\DemoVisitor;
use App\Demo\Sandboxes;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * The demo site only: the showcase's Super Administrator goes into a visitor's demo, as
 * themselves, to act on what the visitor sent for approval there, and comes back.
 *
 * From the showcase, open() leaves a one-minute signed link and this browser's cookie set
 * to the visitor's demo; the link, opened in that demo, signs them in there (enter()).
 * Nobody else can make such a link, so nobody else gets in as the Super Administrator.
 */
class DemoShowcaseController extends Controller
{
    /** In the showcase: into a visitor's demo. */
    public function open(Request $request, DemoVisitor $visitor): RedirectResponse
    {
        abort_unless(Sandboxes::enabled() && ! Sandboxes::shared() && Sandboxes::showcase() && $request->user()?->isSuperAdmin(), 404);
        if ($visitor->sandbox_key === null || ! Sandboxes::exists($visitor->sandbox_key)) {
            return back()->withErrors(['action' => "{$visitor->name} has no demo at the moment (it was unused and deleted). They get a new one when they sign in."]);
        }

        $link = URL::temporarySignedRoute('demo.showcase.enter', now()->addMinute(), ['visitor' => $visitor, 'as' => $request->user()->email]);

        return redirect()->to($link)->withCookie(Sandboxes::cookie($visitor->sandbox_key));
    }

    /** In the visitor's demo, from the signed link: signed in there as the Super Administrator. */
    public function enter(Request $request, DemoVisitor $visitor): RedirectResponse
    {
        abort_unless(Sandboxes::enabled() && $request->hasValidSignature() && Sandboxes::current() !== null && Sandboxes::current() === $visitor->sandbox_key, 404);
        $email = (string) $request->query('as');

        // Copies made before the Super Administrator's account existed get it now.
        $me = User::query()->where('email', $email)->where('role', Role::SuperAdmin)->first();
        if ($me === null) {
            $showcase = DB::connection('pgsql')->table('public.users')->where('email', $email)->where('role', Role::SuperAdmin->value)->first();
            abort_if($showcase === null, 404);
            $me = new User;
            $me->forceFill([
                'name' => $showcase->name, 'email' => $showcase->email, 'password' => $showcase->password,
                'role' => Role::SuperAdmin, 'active' => true, 'email_verified_at' => now(),
            ])->save();
        }

        Auth::login($me);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', "You are in {$visitor->name}’s demo, as yourself. “Back to the showcase” in the banner when you are done.");
    }

    /** In the showcase: what deleting a visitor's account would undo, to confirm. */
    public function confirmDelete(Request $request, DemoVisitor $visitor): View
    {
        $this->superAdminInShowcase($request);

        return view('demo.delete-visitor', [
            'visitor' => $visitor,
            'plan' => $visitor->sandbox_key !== null ? DemoChanges::plan($visitor->sandbox_key) : ['ids' => [], 'own' => 0, 'others' => [], 'actions' => []],
        ]);
    }

    /** In the showcase: deletes a visitor's account, undoing all their work (and what was built on it). */
    public function deleteVisitor(Request $request, DemoVisitor $visitor): RedirectResponse
    {
        $this->superAdminInShowcase($request);
        $undone = $visitor->sandbox_key !== null ? DemoChanges::undo($visitor->sandbox_key) : 0;
        if ($visitor->sandbox_key !== null) {
            Sandboxes::destroy($visitor->sandbox_key);
        }
        $visitor->delete();

        return redirect()->route('admin.users.index', ['show' => 'visitors'])
            ->with('status', "{$visitor->name}’s account is deleted, and their work undone ({$undone} ".($undone === 1 ? 'change' : 'changes').').');
    }

    private function superAdminInShowcase(Request $request): void
    {
        abort_unless(Sandboxes::enabled() && Sandboxes::showcase() && $request->user()?->isSuperAdmin(), 404);
    }

    /** From a visitor's demo, back to the showcase. */
    public function back(Request $request): RedirectResponse
    {
        abort_unless(Sandboxes::enabled(), 404);
        Auth::guard('web')->logout();
        $request->session()->invalidate();

        return redirect()->route('login')->withCookie(Sandboxes::cookie(Sandboxes::SHOWCASE));
    }
}
