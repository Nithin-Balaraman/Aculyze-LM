# Aculyze-LM (Lead Management System)

A Laravel + Filament + MySQL application for Aculyze Solutions LLP to manage
outbound sales activity: a master **Database** of prospects, **Call
Records** (the activity log), **Follow-Ups**, **Appointments**, **Leads**,
and **Proposals**, with role-based dashboards and stale-record alerts.

This is the current, actively-developed application. The repository also
contains an earlier Next.js/Prisma prototype at the repo root (`src/`,
`prisma/`, etc.) — that has been superseded by this app and is kept only
for reference; see the root `README.md` for its own docs. All new work
happens in this `laravel-app/` folder.

---

## 1. Local development setup

Written for someone comfortable with the command line but not a full-time
PHP/Laravel developer. Follow these in order.

### 1.1 Required software

| Tool | Version | Check with |
|---|---|---|
| PHP | 8.2+ (built and tested on 8.4) | `php -v` |
| Composer | 2.x | `composer -V` |
| MySQL or MariaDB | 8.x / 10.x+ | `mysql --version` |
| Node.js + npm | 18+ | `node -v` |

Required PHP extensions (usually bundled): `pdo_mysql`, `mbstring`, `xml`,
`curl`, `zip`. If `composer install` complains about a missing extension,
install it via your OS package manager (e.g. `sudo apt install php8.4-mysql`).

### 1.2 Install dependencies

```bash
cd laravel-app
composer install
npm install
```

### 1.3 Configure the `.env` file

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env` and set your database connection:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aculyze_lm
DB_USERNAME=aculyze
DB_PASSWORD=your_local_password
```

Create the database and a user for it (run once, via `mysql -u root -p`):

```sql
CREATE DATABASE aculyze_lm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aculyze'@'localhost' IDENTIFIED BY 'your_local_password';
GRANT ALL PRIVILEGES ON aculyze_lm.* TO 'aculyze'@'localhost';
FLUSH PRIVILEGES;
```

`APP_TIMEZONE` is already set to `Asia/Kolkata` in `.env.example` — leave it
as-is (AGENTS.md section 54).

### 1.4 Run migrations and seed sample data

```bash
php artisan migrate --seed
```

This creates all tables and seeds:

- 1 Admin (**Saji**) and 3 Employees (**Nithin**, **Kural**, **Ilaya
  Bharathi**)
- 8 sample prospects covering every stage of the workflow: plain
  follow-ups, an appointment, a Hot/Warm/Cold Lead, an in-progress
  Proposal, a stale Lead, and a stale Proposal

**These are development-only accounts with a placeholder password — never
reuse this password anywhere real.** Login credentials:

| Role | Email | Password |
|---|---|---|
| Admin | `saji@aculyze.test` | `password` |
| Employee | `nithin@aculyze.test` | `password` |
| Employee | `kural@aculyze.test` | `password` |
| Employee | `ilaya@aculyze.test` | `password` |

### 1.5 Build the front-end assets

The panel's custom theme (fonts, brand colors, the Pipeline Pulse widget's
styling) is compiled by Vite and isn't checked into the repo:

```bash
npm run build
```

Re-run this any time you change something under `resources/css/` or
`resources/views/filament/`. For active front-end work, `npm run dev` runs
Vite in watch mode instead.

### 1.6 Start the app

```bash
php artisan serve
```

Open **http://127.0.0.1:8000/admin** and log in with one of the accounts
above.

### 1.7 Common first-run problems

- **`SQLSTATE[HY000] [2002] Connection refused`** — MySQL isn't running, or
  the host/port in `.env` is wrong. Start MySQL (`sudo service mysql
  start` / `brew services start mysql`) and re-check `DB_HOST`/`DB_PORT`.
- **`Unknown database 'aculyze_lm'`** — you skipped the `CREATE DATABASE`
  step above.
- **Blank/500 page with no details** — set `APP_DEBUG=true` in `.env` (it
  already is by default locally) to see the real error.
- **Styles look broken / raw HTML** — run `php artisan optimize:clear` to
  drop any stale view/config cache from a previous run.
- **"No application encryption key has been specified"** — you skipped
  `php artisan key:generate`.

### 1.8 Running the tests

```bash
cp .env.testing.example .env.testing   # if you don't already have one
```

`.env.testing` should point at a **separate** database (e.g.
`aculyze_lm_test`) so tests never touch your dev data:

```sql
CREATE DATABASE aculyze_lm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON aculyze_lm_test.* TO 'aculyze'@'localhost';
```

Then run:

```bash
php artisan test
```

---

## 2. How authentication & roles work

- Login is handled entirely by Filament's built-in panel auth
  (`/admin/login`) — no custom auth code to maintain.
- There are exactly two roles, stored on `users.role` as a PHP enum
  (`App\Enums\UserRole`): `Admin` and `Employee`.
- **Admin is a superset of Employee** — Saji can do everything an Employee
  can (log his own calls, own leads, etc.) *plus* organization-wide
  management. Admin is never a read-only role.
- Every authorization rule is enforced in **policies**
  (`app/Policies/*.php`) and in each Filament Resource's
  `getEloquentQuery()`, not just hidden in the UI. Changing a URL/record ID
  cannot expose another employee's data — a non-owner gets a 404 (the
  record simply isn't in their scoped query), and admin-only pages/actions
  return 403 for anyone else, checked in the page's own `mount()`.
- New employees are created via **Employees** (Admin-only, in the
  Administration nav group). The moment Admin saves the form, that person
  can log in and their dashboard exists — there's no separate "set up
  dashboard" step, because dashboards are computed dynamically from
  whatever records belong to that user (see §5).

---

## 3. The Database → Proposal workflow

```
Database (Prospect) → Call Record → Follow-Up / Appointment / Lead → Proposal → Won/Hold/Lost
```

### 3.1 Database (`Prospect` model)

The master list of companies/contacts. Every `Prospect` tracks both
`assigned_to` (who is currently responsible) and `created_by` (who
originally added it) — these can diverge once Admin reassigns a record.

### 3.2 Call Records = the Activity Log

**There is only one activity concept in this app: the Call Record.** The
"Activity Log" nav item and the "Call Records" resource are the same table
(`call_records`) — see `App\Filament\Resources\CallRecordResource`. Every
call, regardless of outcome, creates one row here. Nothing is ever logged
only-on-success.

### 3.3 Call routing (the important part)

`App\Enums\CallOutcome` defines 7 stable internal outcome values (never
match on the display label). Saving a Call Record fires
`App\Observers\CallRecordObserver::created()`, which calls
`App\Services\CallRoutingService::route()` — the **one, centralized** place
that decides what happens next:

| Outcome | Creates |
|---|---|
| No Answer | Follow-Up |
| Switched Off | Follow-Up |
| Not Reachable | Follow-Up |
| Callback Requested | Follow-Up |
| Appointment Set | Appointment |
| No Current Requirement / Future Opportunity | Appointment |
| Requirement Identified | **Appointment AND Lead** (both) |

Routing is wrapped in a DB transaction and is **idempotent** — it checks
`call_records.processed_at` before doing anything, and each downstream
table has a unique constraint on `call_record_id`, so retries, double
submits, or re-processing the same call can never create duplicate
Follow-Ups/Appointments/Leads. Editing an existing Call Record never
re-triggers routing (only the `created` event is observed).

### 3.4 Appointments & Leads

Independent lifecycles, each with a `stage` (PHP enum) and a dedicated
`stage_changed_at` timestamp that **only** moves when `stage` itself
changes — editing notes or other fields never resets it. This is what
powers accurate stale-detection.

- **Appointment stages** (`App\Enums\AppointmentStage`, provisional —
  not yet stakeholder-confirmed): Appointment Made → Visit/Meeting
  Conducted → Discussion Completed → Succeeded / Not Succeeded.
- **Lead stages** (`App\Enums\LeadStage`, provisional): Requirement
  Collection → Demo Scheduled/Done → Validated. Leads also carry a
  **temperature** (`App\Enums\LeadTemperature`: Hot/Warm/Cold).

All of these are PHP backed enums with a stable `->value` used by logic
and a separate `->getLabel()` for display — renaming a label never breaks
routing or stale-detection.

### 3.5 Proposals

Once a Lead reaches **Validated**, its detail page shows a "Create
Proposal" action. One Proposal per Lead for this version (`leads.id` has a
unique FK on `proposals.lead_id`) — see open question §7 below.
`App\Enums\ProposalStage` (provisional) tracks internal progress; the
separate `App\Enums\ProposalOutcome` (Won/Hold/Lost) records the final
decision.

---

## 4. Assignment & reassignment

Admin can assign/reassign **Prospects, Appointments, and Leads** via the
"Reassign" row action on each list (`app/Filament/Resources/*Resource.php`
→ `assign` action, gated by each model's Policy `assign()` method,
admin-only). Reassigning only updates `assigned_to` — `created_by` and all
historical Call Records keep their original values, so "who called" and
"who created it" never silently change (AGENTS.md §29, verified by
`tests/Feature/ReassignmentTest.php`).

Employees cannot assign records to each other — only Admin sees the
Reassign action (§61 Question 8, deliberately left this way).

---

## 5. Dashboards

Three Filament pages, all built from the same reusable stat/table widgets
in `app/Filament/Widgets/`:

- **My Dashboard** (`app/Filament/Pages/MyDashboard.php`, the panel's home
  page `/admin`) — every logged-in user's own metrics: calls made,
  appointments, total/Hot/Warm/Cold leads, proposals, plus their own stale
  Lead/Proposal alerts. Admin sees their own personal numbers here too,
  since Admin is also an active salesperson.
- **Main Dashboard** (`app/Filament/Pages/MainDashboard.php`, Admin-only,
  `/admin/main-dashboard`) — the same metrics aggregated across every
  employee, plus a Leads-by-Temperature chart and a company-wide activity
  trend chart.
- **Employee Dashboard** (`app/Filament/Pages/EmployeeDashboard.php`,
  `/admin/employee-dashboard/{employee}`) — **one dynamic route** that
  shows any employee's dashboard by ID, reached via the "Dashboard" row
  action on Employees. Admin can open anyone's; an Employee can only open
  their own (enforced in `mount()`, not just hidden from nav). New
  employees automatically get a working dashboard the moment they're
  created — nothing is hard-coded per person.

All three support a simple period filter (Today / This Week / This Month /
Custom Range / All Time) via Filament's `HasFiltersForm`, centralized in
`App\Support\DashboardPeriod`.

**"Company growth" on the Main Dashboard is an open business question**
(§8 below) — rather than invent a revenue formula, it currently shows a
clearly-labeled Leads/Proposals *activity* trend, not a financial metric.

---

## 6. Visual design system

Aculyze-LM intentionally doesn't look like a stock Filament install. The
whole system lives in `resources/css/filament/admin/theme.css` (compiled
via `->viteTheme()` in `AdminPanelProvider`) plus
`resources/css/filament/admin/tailwind.config.js`:

- **Colors** — brand blue (`#4174B9`), deep navy (`#0E1131`), and cyan
  (`#2DC4ED`) registered as the panel's `primary`/`navy`/`info` colors in
  `AdminPanelProvider`. A fourth accent, **coral** (`#F0653C`), is
  deliberately restrained: it appears *only* for Hot leads and stale-record
  alerts (`App\Enums\LeadTemperature`, the `is_stale` icon column on Leads/
  Proposals, and the Pipeline Pulse widget below) — never as general UI
  chrome — so it keeps its meaning as "this needs attention." Warm/Cold
  lead badges get their own `gold`/`slateblue` colors rather than
  Filament's generic warning/info palette.
- **Fonts** — Space Grotesk for headings/brand, IBM Plex Sans for body
  text, IBM Plex Mono specifically for KPI numbers (every stat card value
  across the dashboards). All three are bundled locally via the
  `@fontsource/*` npm packages and `@import`-ed at the top of `theme.css`
  — no Google Fonts CDN dependency, so the app never depends on an
  external font request succeeding.
- **Pipeline Pulse** (`app/Filament/Widgets/PipelinePulse.php` +
  `resources/views/filament/widgets/pipeline-pulse.blade.php`) — the Main
  Dashboard's opening widget: a live, six-stage flow visualization
  (Database → Call Record → Follow-Up/Appointment → Lead → Proposal → Won)
  where each node's count and each connector's thickness come from real
  queries, not placeholder data. A node glows coral when it contains a Hot
  lead or a stale record. The connecting "flow" animation is CSS-only
  (an animated repeating gradient) and respects
  `prefers-reduced-motion: reduce`. It's the one deliberately animated
  element in the app — everywhere else uses standard, brief transitions.
- **Avatars** — `app/Filament/AvatarProviders/InitialsAvatarProvider.php`
  renders a navy initials avatar as an inline SVG data URI instead of
  Filament's default (a request to ui-avatars.com), so the topbar has no
  external image dependency either.
- **Empty states** — each resource table has a short, direct, in-voice
  empty-state message (e.g. Follow-Ups: "Nothing waiting on you.") set via
  `->emptyStateHeading()`/`->emptyStateDescription()` in that resource's
  `table()` method, instead of Filament's generic "No records found."

---

## 7. Stale-record alerts

- **Lead**: stale at **30+ days** with no `stage` movement
  (`Lead::isStale()` / `Lead::scopeStale()`), except Leads already in a
  terminal stage (currently just `Validated`).
- **Proposal**: stale at **20+ days** with no `stage`/`outcome` movement
  (`Proposal::isStale()` / `Proposal::scopeStale()`), except Won/Lost.
  Whether **Hold** should also stop the stale clock is unresolved — see
  `config('aculyze.hold_is_terminal_for_staleness')` in
  `config/aculyze.php` (defaults to `false`, i.e. Hold keeps aging).

Both thresholds are configurable via `.env`
(`ACULYZE_LEAD_STALE_DAYS`, `ACULYZE_PROPOSAL_STALE_DAYS`) rather than
hard-coded, and both alerts appear on every relevant Employee Dashboard and
on the Main Dashboard.

---

## 8. Open business TODOs (need stakeholder confirmation)

These are intentionally left flexible rather than guessed at — see
`AGENTS.md` §61 for the original brief:

1. **Appointment/Lead/Proposal stage names & order** — currently
   provisional drafts, centralized in `app/Enums/*.php` so they can be
   renamed/reordered without touching any other file.
2. **Assignment notifications** — not built. No email/SMS/push
   infrastructure exists yet; the assign/reassign code path is a single
   `$record->update(['assigned_to' => ...])` call, an easy place to hook a
   notification later.
3. **"Company growth" KPI definition** — see §5 above.
4. **Proposal Hold behavior** — see §6 above.
5. **Multiple Proposals per Lead** — currently hard-restricted to one via a
   unique DB constraint. Revisit `proposals.lead_id`'s unique index in
   `database/migrations/..._create_proposals_table.php` if this changes.
6. **Employee-to-employee reassignment** — not allowed; only Admin
   reassigns records today.

---

## 9. Project structure

```
app/Enums/                  Centralized workflow values (CallOutcome, stages, etc.)
app/Models/                 Eloquent models + scopeVisibleTo()/isStale() logic
app/Policies/                Authorization rules (one per model)
app/Services/CallRoutingService.php   The one place call routing happens
app/Observers/CallRecordObserver.php  Wires routing into the create event
app/Filament/Resources/     CRUD screens (Database, Activity Log, Follow-Ups,
                              Appointments, Leads, Proposals, Employees)
app/Filament/Pages/          MyDashboard, MainDashboard, EmployeeDashboard
app/Filament/Widgets/        Reusable stat/table/chart widgets, incl. PipelinePulse.php
app/Filament/AvatarProviders/ Local (no external request) initials avatar
app/Support/DashboardPeriod.php   Shared date-range filter logic
config/aculyze.php           Stale thresholds + the Hold config flag
resources/css/filament/admin/theme.css   Fonts, brand colors, Pipeline Pulse animation
resources/views/filament/widgets/pipeline-pulse.blade.php   Pipeline Pulse markup
database/migrations/         Schema
database/seeders/DatabaseSeeder.php   Dev users + sample workflow data
tests/Feature/               Authorization, routing, staleness, dashboard tests
```

---

## 10. Testing

`tests/Feature/` covers the behaviors called out as critical in the
original brief:

- `AuthorizationTest` — an Employee cannot view/edit another Employee's
  Prospect/Call Record/Lead (by direct URL), cannot manage Users, cannot
  open the Main Dashboard or another employee's dashboard; Admin can do
  all of the above.
- `CallRoutingTest` — every outcome creates a Call Record and routes to
  the correct downstream record(s); Requirement Identified creates both an
  Appointment and a Lead; routing is idempotent (no duplicates on
  retry/re-save).
- `StageTimingTest` — editing unrelated fields never resets
  `stage_changed_at`; changing `stage`/`outcome` always does.
- `StaleLeadTest` / `StaleProposalTest` — the 30-day/20-day boundaries
  (29 vs 30, 19 vs 20) and terminal-stage/outcome exclusions.
- `ReassignmentTest` — reassigning preserves the creator and historical
  call ownership.
- `DashboardScopingTest` — an Employee's dashboard only counts their own
  activity; the Main Dashboard aggregates everyone's.

Run everything with `php artisan test` (42 tests at the time of writing).
