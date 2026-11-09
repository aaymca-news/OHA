<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeUserRole;
use App\Actions\Users\InviteUser;
use App\Actions\Users\SetUserActive;
use App\Actions\Users\UpdateUser;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Movement;
use App\Models\User;
use App\Support\SystemHealth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Users & Roles: the Administrators' screen. Every change goes through the same
 * actions (and so the same rules) as anywhere else; the routes only let
 * Administrators in, and only a Super Administrator manages the Administrators.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('show', 'secretariat');

        $users = User::query()
            ->with(['assignedMovements:id,name', 'movement:id,name'])
            // "boards": each National Movement's own users, its Board Chairperson and National General Secretary.
            ->when($filter === 'boards', fn ($q) => $q->whereIn('role', [Role::Board, Role::Ngs]), fn ($q) => $q->whereNotIn('role', [Role::Board, Role::Ngs]))
            ->orderByDesc('active')->orderBy('name')->orderBy('id')
            ->get();

        return view('admin.users.index', [
            'users' => $users,
            'filter' => $filter,
            'health' => SystemHealth::check(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.create', ['movements' => $this->movements(), 'roles' => $this->grantable($request->user())]);
    }

    public function store(InviteUserRequest $request, InviteUser $invite): RedirectResponse
    {
        $data = $request->validated();

        $user = $invite->handle(
            $request->user(),
            $data['name'],
            $data['email'],
            Role::from($data['role']),
            $data['title'] ?? null,
            [],
            isset($data['board_movement_id']) ? Movement::query()->findOrFail($data['board_movement_id']) : null,
            ($data['chair_mode'] ?? null) === 'replace' || (bool) ($data['confirm_replace'] ?? false),
        );

        return redirect()->route('admin.users.edit', $user)->with('status', "Invitation sent to {$user->email}.");
    }

    public function edit(Request $request, User $user): View
    {
        return view('admin.users.edit', [
            'target' => $user->load('assignedMovements:id,name,slug', 'movement'),
            'movements' => $this->movements(),
            'roles' => $this->grantable($request->user()),
            'canAdminister' => Gate::forUser($request->user())->allows('administer', $user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $update): RedirectResponse
    {
        $data = $request->validated();

        // Who assesses what is handed out on the Assessments page, not here.
        $update->handle($request->user(), $user, $data['name'], $data['email'], $data['title'] ?? null, null);

        return back()->with('status', 'Details saved.');
    }

    public function updateRole(Request $request, User $user, ChangeUserRole $change): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'board_movement_id' => ['nullable', 'required_if:role,board,ngs', 'integer', 'exists:movements,id'],
            'confirm_replace' => ['boolean'],
        ]);

        $change->handle($request->user(), $user, Role::from($data['role']),
            isset($data['board_movement_id']) ? Movement::query()->findOrFail($data['board_movement_id']) : null,
            (bool) ($data['confirm_replace'] ?? false));

        return back()->with('status', "{$user->name} is now {$user->refresh()->role->label()}.");
    }

    public function deactivate(Request $request, User $user, SetUserActive $setActive): RedirectResponse
    {
        $setActive->handle($request->user(), $user, false);

        return back()->with('status', "{$user->name} is deactivated and has been signed out.");
    }

    public function reactivate(Request $request, User $user, SetUserActive $setActive): RedirectResponse
    {
        $setActive->handle($request->user(), $user, true);

        return back()->with('status', "{$user->name} is active again.");
    }

    public function resendInvitation(Request $request, User $user, InviteUser $invite): RedirectResponse
    {
        abort_unless($request->user()->can('administer', $user), 403);

        if (! $user->isInvitationPending()) {
            return back()->withErrors(['action' => "{$user->name} has already accepted their invitation."]);
        }

        $invite->sendInvitation($user, $request->user());

        return back()->with('status', "A new invitation has been sent to {$user->email}.");
    }

    /**
     * The roles this person may give: everything for a Super Administrator, and
     * Staff and the two National Movement roles for an Administrator.
     *
     * @return list<Role>
     */
    private function grantable(User $actor): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $role) => Gate::forUser($actor)->allows('grantRole', [User::class, $role])));
    }

    /**
     * @return Collection<int, Movement>
     */
    private function movements()
    {
        return Movement::query()
            ->with(['zone:id,name', 'chair:id,name,movement_id,email_verified_at', 'generalSecretary:id,name,movement_id,email_verified_at'])
            ->orderBy('name')->get(['id', 'name', 'zone_id']);
    }
}
