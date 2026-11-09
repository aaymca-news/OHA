# Page starter

How to add a page or a feature to the OHA platform so it matches the rest: the same pieces,
the same rules, in the same places. Read [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) for the pieces.

## The rules

1. **Build from the shared pieces.** Every button, field, table, notice, tab bar and empty
   message is a component (`/dev/components` shows them all). If one is missing, add it once.
2. **Rules live in one place.**
   - Who may do something: a policy.
   - What happens: an action.
   - What a page shows: a query class.
   - Controllers only validate, call, and redirect.
3. **Every change is recorded and told.**
   - An action records it with `Audit::record`, which also refreshes everyone's sidebar badges.
   - It tells the people concerned with `Notify`.

---

## Where each kind of code goes

| Folder | Holds | Example |
|---|---|---|
| `app/Actions/Oha`, `Actions/Users`, `Actions/Evidence`, `Actions/Movements` | One thing a person does, as `handle(...)`: it checks the policy, writes in a transaction, records, and tells | `SubmitForApproval`, `InviteUser` |
| `app/Actions/Concerns` | Traits shared by actions | `EnforcesPolicy` (`$this->ensure($user, 'ability', $subject)`) |
| `app/Policies` | Who may: allow, or deny with a reason written for the person | `AssessmentPolicy::viewDrafts` |
| `app/Queries` | What a page or dashboard shows (read only; may memoise within a request) | `MyWork`, `AssessmentPage`, `MovementSnapshot` |
| `app/Models` | Tables and views, their relations, and small named queries (scopes) | `Assessment::underWay()`, `Movement::activeAssessors()` |
| `app/Enums` | Fixed sets, with their labels, tones and icons | `Stage`, `BandCode`, `FindingSeverity` |
| `app/Oha` | Reading, scoring, checking and writing the OHA documents | `Interpreter`, `Report/ReportChecker` |
| `app/Support` | Small shared helpers with no rules of their own | `Viz`, `UploadLimit`, `Audit`, `Notify` |
| `app/Rules` | Validation rules | `GoogleDriveLink` |
| `app/Http/Controllers` (`Concerns`) | Thin controllers; shared controller helpers | `BacksToAssessment` |
| `config/oha.php` | Every number someone may want to change: limits, windows, page sizes | `per_page`, `due_soon_days` |

---

## Outline 1: a list page

**Controller**

```php
public function index(Request $request): View
{
    Gate::authorize('viewAny', Thing::class);                    // the policy decides

    return view('things.index', [
        'things' => Thing::query()
            ->with(['what', 'the', 'rows.show'])                 // load up front: no query per row
            ->when($request->query('zone'), fn ($q, $zone) => $q->where('zone', $zone))
            ->latest('id')
            ->paginate(config('oha.per_page'))->withQueryString(), // a list that grows is paged
        'filters' => $request->only('zone'),
    ]);
}
```

**View**

```blade
<x-layouts.app title="Things" subtitle="What this page is for, in one sentence.">
    <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Filter things">
        <x-select name="zone" label="Zone" size="sm" placeholder="All" :options="$zones" :value="$filters['zone'] ?? ''" />
        <x-button variant="secondary">Filter</x-button>
    </form>

    <x-table caption="Every thing">
        <x-slot:head><tr><th>Name</th><th>Status</th></tr></x-slot:head>
        @forelse ($things as $thing)
            <tr class="border-t border-surface-container">
                <td><a href="{{ route('things.show', $thing) }}" class="font-semibold text-primary underline">{{ $thing->name }}</a></td>
                <td><x-chip :tone="$thing->status->tone()">{{ $thing->status->label() }}</x-chip></td>
            </tr>
        @empty
            <tr><td colspan="2"><x-empty-state>Nothing yet.</x-empty-state></td></tr>
        @endforelse
    </x-table>
    <x-pagination :paginator="$things" />
</x-layouts.app>
```

## Outline 2: a form that changes something

**View**

```blade
<x-card title="Send it back" padding="lg">
    <form method="POST" action="{{ route('things.send-back', $thing) }}" class="flex flex-col gap-md">
        @csrf
        <x-textarea name="reason" label="Why" rows="3" required />
        <x-callout tone="warning">The assessors are told by email.</x-callout>
        <x-button icon="undo">Send back</x-button>
    </form>
</x-card>
```

For something that can't be undone, use `<x-confirm-form :action method="DELETE" confirm="Delete this? It cannot be undone.">`.

**Controller**

```php
public function sendBack(Request $request, Thing $thing, SendBack $action): RedirectResponse
{
    $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
    $action->handle($thing, $request->user(), $data['reason']);

    return back()->with('status', 'Sent back. The assessors have been told.');
}
```

**Action**

```php
final class SendBack
{
    use EnforcesPolicy;

    public function handle(Thing $thing, User $by, string $reason): Thing
    {
        $this->ensure($by, 'sendBack', $thing);                 // refusal → WorkflowRuleBroken → shown on the page

        return DB::transaction(function () use ($thing, $by, $reason) {
            $thing->update(['state' => State::Returned]);
            Audit::record($by, 'thing.sent_back', $thing, payload: ['reason' => $reason]);
            Notify::send(Notify::assessorsOf($thing->assessment), new WorkflowNotice(/* … */));

            return $thing;
        });
    }
}
```

**Policy**

```php
public function sendBack(User $user, Thing $thing): Response
{
    return $user->oversees()
        ? Response::allow()
        : Response::deny('Only the Administrators send work back.');   // said to the person
}
```

---

## Things that get flagged in review

- A hand-written button, field, table header, notice box, tab bar or "nothing here" paragraph, where a component exists.
- `rtrim(rtrim(number_format(...)))`, `->format('j M Y')`, or a band or tone map written inline (see section 3 of the design system).
- `abort_unless($user->isAdmin())` or another role check in a controller or view. Use a policy, or a named ability.
- A database query in a Blade view, or a relation loaded inside a loop. Load it in the controller or query class. Locally, lazy loading is logged in `storage/logs`.
- A list that can grow without paging.
- A state change without `Audit::record`, or without telling the people it concerns.
- A number someone may want to change, written in code instead of `config/oha.php`.
- Text compared where an enum exists (`'odp'`, `'missing'`): use `ArtefactKind::Odp`, `FindingSeverity::Missing`.
- A new component without an entry on `/dev/components` and in the design system.
- Tests: add or extend a test for the feature (see `tests/Support/Journey.php` to reach any stage of an assessment). Run that feature's tests, Pint and PHPStan; the whole suite runs before a release.
