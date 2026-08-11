# Aculyze Lead Management System (superseded prototype)

> **This Next.js/Prisma app has been superseded.** Active development moved
> to a Laravel + Filament + MySQL application in [`laravel-app/`](laravel-app/README.md),
> which is now the real Aculyze-LM system — see that folder's README for
> setup, workflow, and current status. This prototype is kept only for
> reference and is no longer being developed.

---

An internal lead management web app for Aculyze Solutions LLP, replacing the
old Excel + VBA sheet. One Postgres database, queried live on every page —
no macros, no manual refresh, no file-based sync. Works the same on desktop,
laptop, and phone browsers since it's just a website.

## Tech stack

- **Next.js (App Router)** — full-stack React, one deployable app
- **Prisma ORM + Postgres** — see [Why Postgres, not SQLite](#why-postgres-not-sqlite) below
- **Tailwind CSS** — utility classes, no design system overhead
- Auth: a shared login per team member (name + password), stored in the
  database with hashed passwords and a signed cookie session — no OAuth/SSO

## Why Postgres, not SQLite?

The original spec called for SQLite for zero-config simplicity. The
practical problem: Vercel's serverless functions have an **ephemeral
filesystem** — a local SQLite file would reset (silently losing all data) on
every redeploy. The options were: (a) switch to a hosted Postgres, (b) use a
remote-hosted SQLite-compatible service like Turso, or (c) self-host on a
VPS with a persistent volume so a plain SQLite file works as originally
planned. Hosted Postgres (via [Supabase](https://supabase.com)'s free tier)
won out — it's the most battle-tested option, keeps Vercel's zero-ops
deploy model, and Prisma's schema/migration workflow is nearly identical
either way. If this ever needs to move off Supabase, only `DATABASE_URL`
and the `provider` in `prisma/schema.prisma` need to change.

## Local development

Prerequisites: Node 20+, a Postgres database (local Postgres, or a free
Supabase/Neon project).

```bash
npm install
cp .env.example .env   # then fill in DATABASE_URL and SESSION_SECRET
npm run db:migrate     # creates the database schema
npm run db:seed        # creates just the login account — no sample data
npm run dev
```

Open http://localhost:3000 and log in with the seeded account:

- **Name:** `Nithin`
- **Password:** `aculyze2026`

To add teammates or change this password, edit `prisma/seed.ts` (or use
`npm run db:studio` to edit the `User` table directly) and re-run
`npm run db:seed`. **Reseeding wipes every Lead and Activity row** (not just
Users) before recreating the login account, so only run it against a
database you're happy to start over — never against production data you
want to keep.

`SESSION_SECRET` is used to sign login session cookies — generate a real
one for any shared/deployed environment:

```bash
openssl rand -base64 32
```

## Deploying (Vercel + Supabase)

1. **Create a Supabase project** (or any hosted Postgres). Copy its
   connection string — Supabase's "Session pooler" connection string works
   well for a small team's traffic and keeps things simple.
2. **Push this repo to GitHub**, then import it into Vercel.
3. In the Vercel project's **Environment Variables**, set:
   - `DATABASE_URL` — the Supabase connection string
   - `SESSION_SECRET` — a random secret (see above)
4. Deploy. The build (`npm run build`) automatically runs
   `prisma migrate deploy` before building, so schema changes ship with the
   code — there's no separate manual migration step.
5. Seed the production database once, from your machine, pointed at the
   production `DATABASE_URL`:
   ```bash
   DATABASE_URL="<supabase-connection-string>" npm run db:seed
   ```
   This only creates the login account — no sample leads/activities.
   **Reseeding wipes every existing Lead and Activity row**, so only run
   this against production when you actually intend to clear it out (e.g.
   the very first time, or a deliberate reset).

## How the "live, no-refresh" behavior works

There is no caching layer and no background sync job. Every page is
server-rendered per request straight from Postgres via Prisma, so:

- Logging a new Activity updates the parent Lead's status/next
  action/follow-up date in the same database transaction
  (`src/lib/actions/activities.ts`) — the very next page load anywhere in
  the app reflects it.
- Fields like "latest call outcome," "latest remarks," and "last activity
  date" are never stored on the Lead — they're computed from the linked
  Activities at query time (`src/lib/leads.ts`, `src/lib/queries.ts`), so
  they can't drift out of sync.
- The 3-unanswered-calls → High priority escalation
  (`UNANSWERED_ESCALATION_THRESHOLD` in `src/lib/leads.ts`) actually writes
  `priority = High` to the Lead row — it's a real, permanent change, not
  just a visual flag, and it never auto-reverts (a person has to
  consciously lower the priority back down).
- Dashboard counters and every filtered view (Follow-Ups, Appointments,
  Unanswered, High-Priority, Data Quality) are computed fresh on every page
  load — nothing is a stored/cached number that could go stale.

## Product decisions baked into the code

A few judgment calls made while building this, worth knowing about if the
business rules ever need to change:

- **"Stale" lead** (`STALE_AFTER_DAYS` in `src/lib/leads.ts`, currently 7
  days): a lead counts as stale — and shows up in Today's Actions — once
  nobody has logged an activity on it in 7+ days *and* it has no explicit
  next action recorded.
- **Today's Actions vs. Follow-Ups**: Today's Actions (home page) only
  surfaces follow-ups that are due *today or overdue*; the Follow-Ups page
  lists every open lead with any pending follow-up date, including ones
  scheduled further out.
- **Won/Lost auto-archive**: leads drop out of Today's Actions, Follow-Ups,
  Unanswered, and High-Priority the moment their status is Won or Lost, but
  stay visible in the full Lead list and Data Quality view.
- **nextAction / followUpDate "whichever is more recent"**: these live as
  plain editable columns on the Lead. Logging a new Activity overwrites
  them with the Activity's own values (if it set any); editing the Lead
  directly overwrites them too. Whichever happened most recently wins,
  automatically, since both paths write to the same columns.

## Project structure

```
prisma/schema.prisma        Database schema (Lead, Activity, User + enums)
prisma/seed.ts               Creates the login account (wipes leads/activities)
src/lib/leads.ts             Pure business logic (no DB): derived fields,
                              staleness, escalation, data-quality checks
src/lib/queries.ts           Read-side Prisma queries
src/lib/actions/             Server Actions (mutations): create/edit Lead,
                              log Activity, login/logout
src/app/(app)/                Authenticated pages (dashboard + all views)
src/app/login/                Login page
src/components/               Shared UI (forms, tables, nav, badges)
```
