# Phase 4A-3 Production Deployment Runbook

**Status: PREPARATION ONLY — not executed.** This runbook is the single
authoritative deployment plan for shipping Phase 4A-3.1 through 4A-3.6 to
Hostinger production. No step in this document has been run against
production. Written during Phase 4A-3.6; execution requires a separate,
explicit deployment-authorization step.

Covers: the full commercial Version/PDF/Release/Send/Client-Response/
Billing-handoff workflow, the Phase 4A-3.5 cutover (PipelineBoard Proposal
mutation disabled, generic stage/outcome/value editing removed, the Won
DB CHECK), and the Phase 4A-3.6 `Proposal.sent_at` legacy classification.

Production currently runs the pre-4A-3 codebase — none of the schema in
`docs/PHASE4A3_CONSOLIDATED_SQL_COMPANION.sql` exists there yet, and dompdf
has never been deployed there.

---

## 0. Vendor/dompdf deployment conclusion: **BLOCKED**

Before anything else: this deployment introduces dompdf (`barryvdh/laravel-dompdf`,
`dompdf/dompdf`, `dompdf/php-font-lib`, `dompdf/php-svg-lib`,
`sabberworm/php-css-parser`, `thecodingmachine/safe`) for the first time.
Investigation (Phase 4A-3.6, Section I) determined the *correct* method but
could **not** verify it end-to-end against the real production server from
this environment. Marked **BLOCKED** — see Section 6 below for exactly what
must be confirmed before this runbook can proceed past the vendor-upload
step. Everything else in this runbook is ready to execute once that is
resolved.

---

## 1. Team write-freeze / notify users

- [ ] Announce a maintenance window to all Aculyze-LM users (Employees,
      Managers, Senior Managers, Admins) — no new Proposals, commercial
      Version edits, Sends, or Client Responses should be entered during
      the window.
- [ ] Confirm no long-running background job/queue worker is mid-write
      against `proposals`, `proposal_versions`, or related tables at the
      moment the window starts (this app has no queue workers processing
      Proposal state today, per the existing codebase — confirm this
      remains true before deploying).
- [ ] Record the exact window start time — needed for the backup
      timestamp cross-check in Section 2.

## 2. Backups

- [ ] **Database**: full `mysqldump`/Hostinger-panel export of the
      production database, taken AFTER the write-freeze begins. Store it
      outside the web root. Note the exact filename/timestamp.
- [ ] **Application files**: full copy of the current production file
      tree (at minimum `app/`, `config/`, `database/`, `resources/`,
      `routes/`, `vendor/`, `composer.json`, `composer.lock`,
      `bootstrap/cache/`, `.env`) before any file is overwritten.
- [ ] Confirm both backups are restorable in principle (verify the DB dump
      is non-empty and the file archive extracts cleanly) before
      proceeding — a backup that can't be restored is not a backup.

## 3. Preflight SQL

- [ ] Run every `[PREFLIGHT]`-marked query in
      `docs/PHASE4A3_CONSOLIDATED_SQL_COMPANION.sql` (Stage 1's own
      Section, plus the "ADDITIONAL PHASE 4A-3.6 PRE-DEPLOYMENT PREFLIGHT"
      block, items H.1-H.13) against the PRODUCTION database — read-only,
      changes nothing.
- [ ] Record every result. Item H.6 (Won-without-winning_version_id) is
      the hard gate — see Section 4.

## 4. STOP / GO criteria

Do **not** proceed past this point unless ALL of the following hold:

- [ ] H.6's query (Won proposals with a NULL `winning_version_id`) returns
      **zero rows**. If it returns any row: STOP. Do not fabricate a
      winning Version. Escalate to the team for a resolution decision
      before continuing — this is the exact scenario
      `docs/PHASE4A3_5_MANUAL_SQL_COMPANION.sql` and the Consolidated
      companion both call out as a hard stop.
- [ ] H.7's query (winning_version_id belonging to a different Proposal)
      returns zero rows.
- [ ] H.10's generated-column probe succeeds on the actual production
      MariaDB server (confirms `STORED` generated columns work there —
      required for `draft_lock_key`, `operative_accepted_lock_key`, and
      `primary_lock_key`).
- [ ] Section 6's dompdf BLOCKED items are all resolved (see below) —
      otherwise deploy the schema/application-code changes WITHOUT
      enabling PDF generation, or defer the entire deployment.
- [ ] Both backups (Section 2) are confirmed complete and restorable.
- [ ] The write-freeze (Section 1) is confirmed in effect.

If any box above is unchecked: **STOP. Do not proceed to Section 5.**

## 5. Application files to upload

Every PHP/Blade file changed across Phase 4A-3.1 through 4A-3.6. Generate
the exact list at deployment time via:

```
git diff --name-only <last-deployed-production-commit>..HEAD -- app/ resources/ routes/ config/ database/
```

At minimum, expect changes under:
- `app/Models/` (Proposal, ProposalVersion, ProposalVersionLine,
  ProposalVersionLineTaxComponent, ProposalPdfArtifact, ProposalSend,
  ProposalSendAttachment, ProposalClientResponse, ProposalBillingHandoff,
  Prospect, FollowUp, User)
- `app/Services/` (ProposalCreationService, ProposalVersionDraftService,
  ProposalVersionWorkflowService, ProposalPdfArtifactService,
  ProposalReleaseService, ProposalSendService,
  ProposalSendAttachmentArchiver, ProposalClientResponseService,
  ProposalBillingHandoffService, WorkflowTransitionService,
  EmployeeDeletionService)
- `app/Policies/` (ProposalVersionPolicy, ProposalPdfArtifactPolicy,
  ProposalReleasePolicy, ProposalSendPolicy, ProposalClientResponsePolicy)
- `app/Filament/Resources/ProposalResource.php` and everything under
  `app/Filament/Resources/ProposalResource/Pages/`
- `app/Filament/Pages/PipelineBoard.php`
- `app/Support/Exports/ProposalExporter.php`
- `app/Console/Commands/BackfillProposalVersions.php`
- `resources/views/pdf/proposal-v1.blade.php` (the dompdf template)
- `resources/views/filament/**` partials touched by the above pages

Upload via the established Hostinger pattern (`DEPLOYMENT.md`'s File
Manager/FileZilla approach) — no SSH/Composer required for these plain
PHP/Blade files.

## 6. Composer/vendor files to upload — dompdf (BLOCKED pending verification)

Findings (Phase 4A-3.6 investigation, exact citations in the final
report):

- Six packages are new: `barryvdh/laravel-dompdf` (v3.1.2),
  `dompdf/dompdf` (v3.1.6), `dompdf/php-font-lib` (1.0.2),
  `dompdf/php-svg-lib` (1.0.2), `sabberworm/php-css-parser` (v9.4.0),
  `thecodingmachine/safe` (v3.4.0). Combined `vendor/` footprint: ~59 MB,
  dominated by `dompdf/dompdf`'s bundled font files (32 MB).
- **Uploading only these six `vendor/<package>` directories is NOT
  sufficient.** `vendor/composer/autoload_static.php`,
  `autoload_psr4.php`, `autoload_classmap.php`, `installed.php`, and
  `installed.json` all reference dompdf classes — the Composer autoloader
  will not know the new classes exist unless these five metadata files
  are also replaced.
- **`bootstrap/cache/packages.php` and `bootstrap/cache/services.php`**
  (Laravel's compiled package/service-provider manifest) must also be
  replaced or regenerated. `Barryvdh\DomPDF\ServiceProvider` registers via
  Laravel package auto-discovery only — there is no manual fallback
  registration in `config/app.php`. If production's existing compiled
  caches predate this change and are left in place, the provider never
  registers and the `dompdf.wrapper` binding `ProposalPdfArtifactService`
  depends on will not exist — a fatal error the first time a PDF is
  generated. If production runs `config:cache`/`route:cache` as part of
  its normal operation, treat the same concern as applying there too.
- **`storage/fonts` must exist and be writable** by the webserver process
  in production (dompdf's configured `font_dir`/`font_cache` path,
  confirmed via `vendor/barryvdh/laravel-dompdf/config/dompdf.php`'s
  defaults — no override is published in this repo's own `config/`). This
  directory does not exist in the local dev checkout either; it is
  created on first PDF-generation use if the parent is writable, but
  production's private-storage writability for a NEW directory must be
  confirmed, not assumed.
- The one PDF template in use (`resources/views/pdf/proposal-v1.blade.php`)
  only references `DejaVu Sans`, a font bundled with dompdf itself under
  `vendor/dompdf/dompdf/lib/fonts/` — no additional font files need to be
  sourced or uploaded.
- PHP compatibility: dompdf requires PHP `^7.1 || ^8.0`;
  `barryvdh/laravel-dompdf` requires PHP `^8.1`; this repo's own
  `composer.json` requires `^8.2`. Local dev runs PHP 8.4.19 — no
  conflict here. **Production's actual PHP version has not been verified
  from this environment and must be checked (Hostinger hPanel → PHP
  Configuration, or a `<?php phpinfo();`-style probe file) before
  upload — if it is below 8.1, this entire feature cannot run as-is.**

### What remains BLOCKED (must be resolved before this section can be marked GO)

1. **Confirm production PHP version is ≥ 8.1** (ideally ≥ 8.2 to match
   this repo's own floor).
2. **Confirm production's current `vendor/composer/installed.json`
   state** — this deployment's `vendor/composer/*` files must be a
   superset merge (every package production already has, PLUS the six
   new dompdf packages), never a blind overwrite from local dev, since
   local dev's own `vendor/` may not be byte-identical to production's in
   unrelated packages. The safest concrete method: on a Hostinger
   environment with even temporary SSH/Composer access (or a one-time
   support-ticket-assisted session), run `composer install
   --no-dev --optimize-autoloader` directly on production against the
   uploaded `composer.json`/`composer.lock`, letting Composer itself
   regenerate `vendor/composer/*` and `bootstrap/cache/*` correctly in
   place — this is unambiguously safer than manually splicing metadata
   files by hand. If genuinely no Composer access exists at all (per the
   established "Hostinger has no reliable SSH/Composer" constraint), the
   fallback is: upload the SIX new package directories AND all of
   `vendor/composer/*` AND both `bootstrap/cache/*.php` files wholesale
   from a clean local `composer install --no-dev` run — but this has NOT
   been rehearsed against a production-shaped target in this
   investigation and carries real risk of silently breaking an unrelated
   already-installed package if local dev's lock state has ever diverged
   from production's.
3. **Confirm `storage/fonts` (or dompdf's actual configured font-cache
   path) can be created and is writable** by whatever user/group the
   Hostinger PHP-FPM process runs as.
4. **Rehearse the exact upload+cache-clear sequence on a disposable
   staging copy if one can be provisioned and Hostinger's plan supports it** —
   this has not been done; only the local dev environment (a different,
   fully Composer-managed shell) has verified the code itself works.

Until 1-3 above are answered with real production access (something this
sandboxed session cannot do), this section stays **BLOCKED**. Do not
attempt the vendor upload from a guess.

## 7. Config files

- [ ] Confirm `.env` on production does not need new keys for this phase
      (checked: no new `config('aculyze.*')` keys were introduced by
      4A-3.5/4A-3.6; 4A-3.2's PDF work uses `config('aculyze.organization_identity')`,
      already required since that phase — confirm it is already set in
      production's `.env`/config if 4A-3.2 itself hasn't shipped yet
      either).
- [ ] If `config/dompdf.php` is ever published (`php artisan vendor:publish
      --tag=dompdf-config`) for a production-specific font path, upload it
      too — not currently published in this repo, so the package defaults
      apply as documented in Section 6.

## 8. Private storage requirements

- [ ] `storage/app/private` (or wherever `Storage::disk('local')` resolves
      in production) must exist and be writable — this is where Proposal
      attachments, PDF artifacts, and the content-addressed Send-attachment
      archive are stored. Confirm current production writability if this
      is the first phase using it for these purposes.
- [ ] `storage/fonts` — see Section 6.
- [ ] Confirm `storage/logs` remains writable (standard Laravel
      requirement, unrelated to this phase but worth a quick check during
      the same maintenance window).

## 9. Migrations / manual SQL ordering

Run `docs/PHASE4A3_CONSOLIDATED_SQL_COMPANION.sql` in full, in the exact
order it specifies:

1. Its own Stage 1 `[PREFLIGHT]`, then Stage 1 `[APPLY]` (the true 4A-3.1
   foundation — ProposalVersion/Lines/TaxComponents, Proposal
   number/version pointers, Prospect billing fields, submitted evidence).
2. `docs/PHASE4A3_1_MANUAL_SQL_COMPANION.sql` in full (Stage 2 — the
   2026-09-09 feature-table batch).
3. `docs/PHASE4A3_5_MANUAL_SQL_COMPANION.sql` in full (Stage 3 — the Won
   CHECK, including its own mandatory preflight STOP condition).
4. Run `php artisan aculyze:backfill-proposal-versions` (a one-time
   Artisan command — requires at least a one-off Artisan invocation even
   under Hostinger's constraints; if genuinely impossible, this must be
   run through whatever mechanism Hostinger offers for a single
   command — cron-once, a temporary web-triggered script, or a support
   ticket) so every pre-4A-3 Proposal gets its legacy V1.
5. Verify with `docs/PHASE4A3_CONSOLIDATED_SQL_COMPANION.sql`'s Stage 1
   `[VERIFY]` block, the existing companions' own `[VERIFY]`/Section 9
   blocks, and `docs/PHASE4A3_5_MANUAL_SQL_COMPANION.sql`'s Section 2.

## 10. Cache clear

- [ ] Laravel: `php artisan config:clear`, `route:clear`, `view:clear`,
      `cache:clear` (or the equivalent if run via a one-off script rather
      than Artisan directly).
- [ ] `php artisan package:discover` (or a fresh
      `bootstrap/cache/packages.php`/`services.php` upload — see Section 6)
      so the dompdf provider registers.
- [ ] **Purge the Hostinger LiteSpeed cache** (hPanel → Cache Manager) —
      per the existing lesson in `DEPLOYMENT.md`: "It will keep serving the
      previous page/assets after a deploy… until this is purged. Skipping
      this step is indistinguishable from the deploy having failed."
- [ ] Reload a page in a private/incognito window to confirm the deploy is
      actually live before proceeding to smoke tests.

## 11. Post-schema verification

- [ ] Run every `[VERIFY]` query from all three SQL companions (see
      Section 9) and confirm expected results.
- [ ] `SHOW CREATE TABLE proposals\G` — confirm
      `proposals_won_requires_winning_version` CHECK is present and the
      `winning_version_id` FK carries no `ON DELETE` clause (RESTRICT).

## 12. Read-only smoke tests

- [ ] Load the Proposals list (`ListProposals`) as an Employee, a Manager,
      and a Senior Manager — confirm no error, correct row scoping.
- [ ] Open an existing Proposal's View/Edit page — confirm `stage`/
      `outcome`/`value` render as read-only badges/placeholders, not
      editable inputs.
- [ ] Open the Commercial Version page (`ManageCommercialVersion`) for an
      existing Proposal — confirm it loads without error.

## 13. Manager/Senior/Employee role smoke tests

- [ ] As a Manager: create a Draft, add a line item, Save Draft, Submit.
- [ ] As a Senior Manager: Approve the Submitted Version.
- [ ] As the assigned Employee: confirm no Release/Send action is visible
      until the Manager releases it.
- [ ] Confirm PipelineBoard still displays the Proposal card and that
      dragging it is refused with the expected message.

## 14. PDF render smoke

- [ ] As a Manager, on an Approved Version, click "Generate Final PDF".
- [ ] Confirm a Success artifact is created and downloadable.
- [ ] **This is the step gated by Section 6's BLOCKED items** — if dompdf
      is not correctly deployed, this is where it will visibly fail
      (either a fatal "class not found" error if the provider never
      registered, or a font-related warning/error if `storage/fonts`
      isn't writable). Do not consider the deployment complete until this
      succeeds for real.

## 15. Release smoke

- [ ] As the Manager, click "Release for Client Sending" on the
      PDF-bearing Version.
- [ ] Confirm Release Status shows "Released — Valid" and the assigned
      Employee gains access to Download/Record Manual Send.

## 16. Manual Send smoke

- [ ] As the assigned Employee, Record Manual Send with a real recipient.
- [ ] Confirm the Proposal's Stage moves to Sent, the Version's
      `sent_at` is set, and Send History shows the real recipient (not
      just static text).

## 17. Client Response smoke

- [ ] As the Manager or assigned Employee, Record Client Response
      (Accepted) against the Sent Version.
- [ ] Confirm Client Response History shows the real response row.

## 18. Accepted/Won + handoff smoke

- [ ] Confirm the Proposal's Outcome shows Won, Winning Version and Value
      are correct, and exactly one row exists in
      `proposal_billing_handoffs` with `status = 'pending'`.
- [ ] Confirm attempting a second Record Client Response on the same
      terminal Proposal is refused.

## 19. Rollback decision points

- [ ] If Section 3/4's preflight fails: STOP before any `[APPLY]`
      statement runs — nothing to roll back.
- [ ] If schema `[APPLY]` partially fails mid-way: restore the DB backup
      from Section 2 rather than attempting to hand-reconcile a
      half-applied migration set.
- [ ] If application code is live but PDF generation fails (Section 6/14):
      the schema and non-PDF workflow (Draft/Submit/Approve/Release
      without a PDF/Send/Client-Response) can still function — decide
      whether to proceed without PDF generation temporarily or roll back
      the application files to the pre-deployment backup while the
      vendor issue is resolved. Do NOT roll back the database once real
      user data has been written against the new schema; roll back
      application files only, and re-attempt the vendor deployment
      separately.
- [ ] If a genuine data-integrity issue appears (e.g., the Won CHECK
      unexpectedly rejects a legitimate write): STOP, do not weaken the
      CHECK in production ad hoc — investigate against the exact
      preflight queries first.

## 20. Final sign-off

- [ ] All Section 12-18 smoke tests pass.
- [ ] No unexpected error in production logs during the smoke-test window.
- [ ] LiteSpeed cache purge confirmed effective (Section 10).
- [ ] Team notified the write-freeze is lifted.
- [ ] This runbook's completed checklist is archived (dated, with the
      operator's name) alongside the DB/file backups from Section 2.

## 21. User/team rollout note

Once live, notify users that:
- Proposal Stage/Outcome/Value are now read-only on the Proposal record —
  Stage changes only via a real Send; Outcome only via Record Client
  Response.
- PipelineBoard Proposal cards can no longer be dragged to change stage
  or outcome — open the Proposal directly instead.
- A new "Record Client Response" action exists on the Commercial Version
  page for recording what a customer said about a Sent proposal.
- The old "Sent At" date on the Proposal record is now labeled legacy and
  read-only; the real send record lives on the Commercial Version page's
  Send History.
