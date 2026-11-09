# Design system

How the OHA platform looks, and the shared pieces every page is built from. To see every
component in each variant, open **`/dev/components`** while working locally (signed in).
That page exists only when `APP_ENV=local`.

The rule: **build a page from these pieces.** If a piece is missing, add it here (component,
gallery entry, this document) and then use it. Don't hand-write a second copy on the page.

---

## 1. Tokens

All tokens live in `resources/css/app.css` (Tailwind 4 `@theme`). Use the utility classes
that the tokens produce (`bg-primary`, `text-on-surface-variant`, `p-md`, `rounded-lg`).
Never write raw hex values or pixel sizes in a view.

### Colour

| Job | Tokens |
|---|---|
| Brand | `brand-red`, `brand-red-ink`, `brand-red-wash`, `brand-grey`, `brand-grey-dark` |
| Actions, links | `primary`, `on-primary`, `primary-container`, `secondary`, `tertiary` |
| Errors | `error`, `on-error`, `error-container`, `on-error-container` |
| Surfaces (light to dark) | `background`, `surface`, `surface-container-lowest`, `-low`, (none), `-high`; text `on-surface`, `on-surface-variant` |
| Lines | `outline`, `outline-variant` (borders are `border-[1.5px] border-outline-variant`) |
| Tones (chips, callouts) | `{good,info,warning,serious,critical,neutral}-wash` (background) with `-ink` (text) |
| Health bands | `band-strong`, `band-developing`, `band-atrisk`, `band-critical`, `band-notstarted` |
| Charts | categorical `viz-1` to `viz-6` (fixed order, never cycled); heat ramp `heat-1` to `heat-5` |

**Tones** are the shared language of status: `good`, `info`, `warning`, `serious`,
`critical`, `neutral`. A colour always comes with a label or icon, never on its own.

### Spacing and shape

`xs` 4px · `sm` 8px · `md` 16px · `lg` 24px · `xl` 40px · `gutter` 24px. Use `gap-*`, `p-*`
and `m-*` with these names. Corners are `rounded` (controls), `rounded-lg` (cards and boxes),
and `rounded-full` (pills).

### Type

IBM Plex Sans. Body text is `text-[0.875rem]`, small print `text-[0.8125rem]`, card titles
`text-[1rem] font-bold text-primary`, and page titles come from the layout. Numbers in
tables use `tabular-nums`.

### Icons

Material Symbols Outlined: `<span class="material-symbols-outlined" aria-hidden="true">name</span>`.
The components take an `icon="name"` prop, so you rarely write the span yourself.

---

## 2. Components: use them, don't rebuild them

All of these are in `resources/views/components`.

### Layout and structure

| Component | Use it for | Key props |
|---|---|---|
| `x-layouts.app` | Every signed-in page | `title`, `subtitle`, slot `actions` |
| `x-layouts.guest` | Sign-in, invitation, errors | |
| `x-card` | A titled section of a page | `title`, `subtitle`, `padding` (`md` or `lg` for forms and settings), slot `actions` |
| `x-card.details` | A section that folds open | `title`, `subtitle`, `open` |
| `x-table` | Every data table | `caption`, `bare` (inside a card); slot `head` holds the `<tr><th>` row; cells get their spacing automatically |
| `x-pagination` | Under any paged list | `paginator`, `fragment` |
| `x-empty-state` | "Nothing here yet" | `icon` |
| `x-stat-tile` | A headline number | `label`, `value`, `unit`, slot `sub` |

### Actions and forms

| Component | Use it for | Key props |
|---|---|---|
| `x-button` | Every button, and every link styled as one | `variant` (`primary`, `secondary`, `outline`, `danger`, `link`, `link-danger`), `size` (`md` or `sm`), `icon`, `href` (renders a link) |
| `x-input` | Text, email, date, month, search, password (password gets a show/hide toggle) | `name`, `label`, `type`, `value`, `hint`, `size` (`sm` in filter bars), `wrap` (classes for the label) |
| `x-select` | Dropdowns | `name`, `label`, `options` (value to text) or `<option>`s in the slot, `placeholder` (adds "All" or "Choose…"), `value`, `size`, `wrap` |
| `x-textarea` | Long text | `name`, `label`, `value`, `rows`, `hint`, `wrap` |
| `x-field` | The label, hint and error around any other control (checkbox groups, custom controls) | `label`, `name` (for the error), `hint`, `size` |
| `x-file-drop` | File uploads: drop or choose, with the size limit shown and checked | `name`, `accept`, `prompt`, `label`, `required` |
| `x-confirm-form` | "Are you sure?" before delete, withdraw or discard | `action`, `method`, `confirm` |
| `x-segmented` and `x-segmented.link` | Filter tabs, and toggles such as "Cards / Table" | `label`; `variant` (`pills` or `joined`); link: `href`, `active`, `icon` |

All form controls share one look from `App\Support\Ui::control()`. A control that has to stay
raw uses the same classes: `@class(\App\Support\Ui::control($errors->has('x'), 'sm'))`.

### Status

| Component | Use it for | Key props |
|---|---|---|
| `x-chip` | Any small status label | `tone`, `icon` |
| `x-band-chip` | A health band | `band` (model), or `code`, `label`, `icon`; `under-way` (shows "Assessment started" in place of "Not started") |
| `x-state-chip` | A form, report or ODP state | `state` |
| `x-due-chip` | Days left or overdue | `days` |
| `x-validation-chip` | Board validation | `status` |
| `x-callout` | A coloured notice: frozen, a problem, a warning, a note | `tone`, `icon`, `title`, `alert` (for problems: adds a border and is read out at once) |
| `x-bar` | A value as a bar | `value`, `max`, `label`, `tone`, `wide` |
| `x-gate-stepper` | The four Stage 1 gates | `milestones`, `current` |

### Charts (`x-viz.*`)

`ring`, `pipeline` (Stage 1 across the region), `heatmap`, `stacked`, `diverging`,
`threshold-bars`, `checklist`, `adoption`. Their colours come from `App\Support\Viz` and the
enums below.

### Partials (whole blocks shared by several pages)

| Partial | Use |
|---|---|
| `assessments/tabs/_upload-version` | The upload card on the Form, Report and ODP tabs |
| `assessments/tabs/_submit` | Submit for approval (Report, ODP) |
| `assessments/tabs/_replace-guard` | The "someone else saved a newer version" warning and confirmation |
| `assessments/tabs/_versions`, `_decisions`, `_current-or-working` | Version list, approve and send back, and the approved/working switch |
| `partials/work-row` | One row in My Work and the dashboards |

---

## 3. One source for what is shown

Don't write these yourself:

| Need | Use |
|---|---|
| A figure: 12.5, 58 | `Viz::figure($v)`; `Viz::figure($v, thousands: '')` inside documents |
| Points: "58 / 84" | `Viz::points($achieved, $available)` |
| A percentage: "69.0%" | `Viz::pct($v)` |
| A date | `$date->asDay()` (9 Nov 2026), `->asStamp()` (9 Nov 2026, 14:05), `->asMonth()` (Nov 2026) |
| A band's tone or ring colour | `BandCode::toneOf($code)`, `BandCode::from($code)->stroke()` |
| Which band a score is in | `HealthBand::forPercentage($pct)` (with `rated: true` for a score that must have a real band) |
| A finding's tone, icon, order, label | `$finding->severity->tone()`, `->icon()`, `->rank()`, `->label()` |
| A movement's Stage 1 stage | `Stage::of($latestAssessment)`, then `->label()`, `->short()`, `->icon()`, `->tone()`, `->fill()` |
| A form, report or ODP state | `ArtefactState::display($state)` (through `x-state-chip`) |
| Category names | `Category::names()`, `Category::nameOf($code)` (read once per request) |
| An assessment's tab address | `Assessment::tabUrl($assessment, 'report')` |

---

## 4. Responsive rules

- Phone first. Lay out with `flex flex-wrap` and `grid` that becomes columns from `sm:` and `lg:`.
- No page scrolls sideways: tables scroll inside `x-table`. Give a wide table a `min-w-[…]` there.
- Text fields in filter bars use `size="sm"`. They grow on a phone (`wrap="grow sm:grow-0"`) and get a width from `sm:`.

---

## 5. Naming and file layout

- Components: `resources/views/components/<name>.blade.php` in kebab case; a family lives in a folder (`segmented/link`, `card/details`, `viz/*`).
- Page partials sit next to their page and start with an underscore (`movements/_profile`).
- Every new component gets an entry on `/dev/components` (`resources/views/dev/components.blade.php`) and in section 2 above.
