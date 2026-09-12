-- =====================================================================
-- PHASE 4A-3 (4A-3.1 THROUGH 4A-3.5) — CONSOLIDATED PRODUCTION
-- SQL / PREFLIGHT COMPANION
-- =====================================================================
--
-- Phase 4A-3.6 deliverable. This is the SINGLE authoritative, correctly
-- ORDERED schema-change reference for everything Phase 4A-3 added to the
-- database. It supersedes scattering deployment SQL across isolated
-- per-slice notes: it INCORPORATES the two existing companions verbatim
-- by reference (never re-copied, to avoid drift between two versions of
-- the same statement) and adds the ONE piece of schema that had no
-- existing companion at all — the true 4A-3.1 foundational tables
-- (ProposalVersion/Lines/TaxComponents + the Proposal/Prospect columns
-- that came with them), migrated 2026-09-05/06, before the 4A-3.3/3.4
-- feature-table batch on 2026-09-09.
--
-- NOT executed against production as part of any 4A-3 implementation
-- slice. Hostinger production has no reliable SSH/Composer/Artisan
-- workflow — every statement below is reviewed and run by hand
-- (phpMyAdmin or an equivalent MariaDB client) during the later,
-- separate, controlled deployment, exactly as every prior Phase
-- migration to production has been handled (see
-- docs/PHASE4A3_1_MANUAL_SQL_COMPANION.sql and
-- docs/PHASE4A3_5_MANUAL_SQL_COMPANION.sql, both of which state the same
-- constraint).
--
-- Target: MariaDB 11.8.8 (production) / 10.11.14 (local dev, where every
-- statement in all three companions has been run via `php artisan
-- migrate` and verified against real `SHOW CREATE TABLE` output).
--
-- ORDER OF OPERATIONS (must run in exactly this sequence):
--   STAGE 1 — 4A-3.1 foundation (this file, Section 1)   — 2026-09-05/06
--   STAGE 2 — 4A-3.3/3.4 feature tables (see companion)  — 2026-09-09
--   STAGE 3 — 4A-3.5 Won CHECK (see companion)           — 2026-09-12
-- Running Stage 2 or 3 before Stage 1 will fail outright (foreign keys
-- into proposal_versions/proposal_version_lines would have no table to
-- reference). Running Stage 3 before Stage 2 is schema-safe (the CHECK
-- only touches `proposals`) but is out of the locked historical order and
-- must not be done — Stage 3's own docblock explains why the CHECK was
-- only safe to add once 4A-3.5 removed the legacy uncontrolled writers,
-- which is a code-level (not schema-level) dependency on Stage 2 having
-- already shipped.
--
-- =====================================================================
-- MARK KEY
-- =====================================================================
-- [PREFLIGHT]  — read-only; run first; change nothing
-- [APPLY]      — the actual DDL
-- [VERIFY]     — read-only; run after APPLY to confirm it worked
-- [ROLLBACK]   — emergency reference only; do not run unless reverting
-- =====================================================================


-- #####################################################################
-- STAGE 1 — 4A-3.1 FOUNDATION (2026-09-05 / 2026-09-06)
-- No prior companion exists for this stage — generated fresh for this
-- consolidation, verified against local MariaDB SHOW CREATE TABLE output.
-- #####################################################################

-- =====================================================================
-- STAGE 1 — [PREFLIGHT]
-- =====================================================================

-- 1.1 Confirm `proposal_versions`, `proposal_version_lines`,
--     `proposal_version_line_tax_components` do not already exist
--     (this stage must be the FIRST proposal-commercial-schema change
--     ever run against this database).
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('proposal_versions', 'proposal_version_lines', 'proposal_version_line_tax_components');
-- Expect: zero rows. Any row returned means Stage 1 has already run —
-- STOP and reconcile against migrate:status before proceeding.

-- 1.2 Confirm `proposals` does not yet have proposal_number/
--     current_version_id/winning_version_id, and `prospects` does not yet
--     have gstin/billing_address/billing_state.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'proposals' AND COLUMN_NAME IN ('proposal_number', 'current_version_id', 'winning_version_id'))
    OR (TABLE_NAME = 'prospects' AND COLUMN_NAME IN ('gstin', 'billing_address', 'billing_state')));
-- Expect: zero rows for a database that has never run any 4A-3 migration.

-- 1.3 Row counts on tables about to be altered (sanity/scale check only).
SELECT
  (SELECT COUNT(*) FROM proposals) AS proposals_count,
  (SELECT COUNT(*) FROM prospects) AS prospects_count;

-- =====================================================================
-- STAGE 1 — [APPLY]
-- =====================================================================

-- 1.A — 2026_09_05_090000_create_proposal_versions_table
-- (base shape only — submitted_by/submitted_at added in 1.F below,
-- released_* columns added in Stage 2's own ALTER, exactly matching real
-- migration order; the live SHOW CREATE TABLE therefore differs from this
-- CREATE TABLE alone until 1.F and Stage 2 have both also run).
CREATE TABLE `proposal_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `lifecycle_status` varchar(255) NOT NULL,
  `is_legacy_backfill` tinyint(1) NOT NULL DEFAULT 0,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_version_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name_snapshot` varchar(255) DEFAULT NULL,
  `customer_gstin_snapshot` varchar(255) DEFAULT NULL,
  `billing_address_snapshot` text DEFAULT NULL,
  `billing_state_snapshot` varchar(255) DEFAULT NULL,
  `place_of_supply_snapshot` varchar(255) DEFAULT NULL,
  `payment_terms` text DEFAULT NULL,
  `validity_terms` text DEFAULT NULL,
  `scope_notes` text DEFAULT NULL,
  `subtotal` decimal(18,2) DEFAULT NULL,
  `total_discount` decimal(18,2) DEFAULT NULL,
  `tax_total` decimal(18,2) DEFAULT NULL,
  `grand_total` decimal(18,2) DEFAULT NULL,
  `currency_code` varchar(3) NOT NULL DEFAULT 'INR',
  `manager_reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `manager_reviewed_at` timestamp NULL DEFAULT NULL,
  `manager_review_comment` text DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_comment` text DEFAULT NULL,
  `returned_by` bigint(20) unsigned DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `return_reason` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `draft_lock_key` bigint(20) unsigned GENERATED ALWAYS AS (case when `lifecycle_status` = 'draft' then `proposal_id` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_versions_proposal_id_version_number_unique` (`proposal_id`,`version_number`),
  UNIQUE KEY `proposal_versions_draft_lock_key_unique` (`draft_lock_key`),
  KEY `proposal_versions_superseded_by_version_id_foreign` (`superseded_by_version_id`),
  KEY `proposal_versions_manager_reviewed_by_foreign` (`manager_reviewed_by`),
  KEY `proposal_versions_approved_by_foreign` (`approved_by`),
  KEY `proposal_versions_returned_by_foreign` (`returned_by`),
  KEY `proposal_versions_organization_id_proposal_id_index` (`organization_id`,`proposal_id`),
  KEY `proposal_versions_organization_id_lifecycle_status_index` (`organization_id`,`lifecycle_status`),
  CONSTRAINT `proposal_versions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `proposal_versions_manager_reviewed_by_foreign` FOREIGN KEY (`manager_reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `proposal_versions_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_versions_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`),
  CONSTRAINT `proposal_versions_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `proposal_versions_superseded_by_version_id_foreign` FOREIGN KEY (`superseded_by_version_id`) REFERENCES `proposal_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.B — 2026_09_05_090100_add_version_pointers_to_proposals_table
ALTER TABLE `proposals`
  ADD COLUMN `proposal_number` varchar(255) DEFAULT NULL AFTER `id`,
  ADD COLUMN `current_version_id` bigint(20) unsigned DEFAULT NULL AFTER `outcome`,
  ADD COLUMN `winning_version_id` bigint(20) unsigned DEFAULT NULL AFTER `current_version_id`;

ALTER TABLE `proposals`
  ADD CONSTRAINT `proposals_current_version_id_foreign` FOREIGN KEY (`current_version_id`) REFERENCES `proposal_versions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `proposals_winning_version_id_foreign` FOREIGN KEY (`winning_version_id`) REFERENCES `proposal_versions` (`id`) ON DELETE SET NULL;
-- Note: Phase 4A-3.5 later tightens winning_version_id's FK from
-- ON DELETE SET NULL to plain RESTRICT — see Stage 3's own [APPLY],
-- which drops and re-adds this exact constraint. Do not skip Stage 3
-- after this; the two are designed to run in this order.

ALTER TABLE `proposals`
  ADD UNIQUE KEY `proposals_organization_id_proposal_number_unique` (`organization_id`, `proposal_number`);

-- 1.C — 2026_09_05_090200_create_proposal_version_lines_table
CREATE TABLE `proposal_version_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_version_id` bigint(20) unsigned NOT NULL,
  `line_number` int(10) unsigned NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `hsn_sac` varchar(255) DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit` varchar(255) DEFAULT NULL,
  `unit_price` decimal(18,2) NOT NULL,
  `discount_type` varchar(255) DEFAULT NULL,
  `discount_value` decimal(9,4) DEFAULT NULL,
  `discount_amount` decimal(18,2) DEFAULT NULL,
  `gross_amount` decimal(18,2) DEFAULT NULL,
  `taxable_amount` decimal(18,2) DEFAULT NULL,
  `tax_amount` decimal(18,2) DEFAULT NULL,
  `line_total` decimal(18,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_version_lines_proposal_version_id_line_number_unique` (`proposal_version_id`,`line_number`),
  KEY `proposal_version_lines_organization_id_proposal_version_id_index` (`organization_id`,`proposal_version_id`),
  CONSTRAINT `proposal_version_lines_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_version_lines_proposal_version_id_foreign` FOREIGN KEY (`proposal_version_id`) REFERENCES `proposal_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.D — 2026_09_05_090300_create_proposal_version_line_tax_components_table
CREATE TABLE `proposal_version_line_tax_components` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_version_line_id` bigint(20) unsigned NOT NULL,
  `component_type` varchar(255) NOT NULL,
  `rate` decimal(9,4) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pvltc_proposal_version_line_id_foreign` (`proposal_version_line_id`),
  KEY `pvltc_organization_id_line_id_index` (`organization_id`,`proposal_version_line_id`),
  CONSTRAINT `proposal_version_line_tax_components_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `pvltc_proposal_version_line_id_foreign` FOREIGN KEY (`proposal_version_line_id`) REFERENCES `proposal_version_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.E — 2026_09_06_090000_add_billing_fields_to_prospects_table
-- Every existing Prospect starts NULL on all three — never backfilled
-- from `address`/`state` (unproven to be the formal GST billing values),
-- never a fabricated GSTIN.
ALTER TABLE `prospects`
  ADD COLUMN `gstin` varchar(255) DEFAULT NULL AFTER `pincode`,
  ADD COLUMN `billing_address` varchar(255) DEFAULT NULL AFTER `gstin`,
  ADD COLUMN `billing_state` varchar(255) DEFAULT NULL AFTER `billing_address`;

-- 1.F — 2026_09_06_090100_add_submitted_evidence_to_proposal_versions_table
ALTER TABLE `proposal_versions`
  ADD COLUMN `submitted_by` bigint(20) unsigned DEFAULT NULL AFTER `currency_code`,
  ADD COLUMN `submitted_at` timestamp NULL DEFAULT NULL AFTER `submitted_by`;

ALTER TABLE `proposal_versions`
  ADD CONSTRAINT `proposal_versions_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`);

-- =====================================================================
-- STAGE 1 — [VERIFY]
-- =====================================================================

-- 1.V1 Confirm all three new tables exist and are empty.
SELECT
  (SELECT COUNT(*) FROM proposal_versions) AS proposal_versions_count,
  (SELECT COUNT(*) FROM proposal_version_lines) AS proposal_version_lines_count,
  (SELECT COUNT(*) FROM proposal_version_line_tax_components) AS proposal_version_line_tax_components_count;
-- Expect: all zero — this stage creates schema only, no data.

-- 1.V2 Confirm the draft_lock_key generated column computes as MariaDB
-- expects (mirrors the same verification technique used for
-- proposal_client_responses/proposal_pdf_artifacts in the 4A-3.1 [feature
-- batch] companion).
SELECT COLUMN_NAME, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_versions'
  AND COLUMN_NAME = 'draft_lock_key';

-- 1.V3 Confirm proposals.proposal_number is uniformly NULL (no unexpected
-- pre-existing values that a future numbering scheme could collide with).
SELECT COUNT(*) AS non_null_proposal_numbers FROM proposals WHERE proposal_number IS NOT NULL;

-- 1.V4 Confirm prospects' three new billing columns are uniformly NULL.
SELECT
  SUM(gstin IS NOT NULL) AS non_null_gstin,
  SUM(billing_address IS NOT NULL) AS non_null_billing_address,
  SUM(billing_state IS NOT NULL) AS non_null_billing_state
FROM prospects;

-- =====================================================================
-- STAGE 1 — [ROLLBACK] (emergency reference only)
-- =====================================================================
-- Reverse order of [APPLY]. Only safe while proposal_versions/lines/tax
-- components remain empty (no dependent Stage 2/3 data exists yet).

-- ALTER TABLE `proposal_versions` DROP FOREIGN KEY `proposal_versions_submitted_by_foreign`;
-- ALTER TABLE `proposal_versions` DROP COLUMN `submitted_at`, DROP COLUMN `submitted_by`;
-- ALTER TABLE `prospects` DROP COLUMN `billing_state`, DROP COLUMN `billing_address`, DROP COLUMN `gstin`;
-- DROP TABLE `proposal_version_line_tax_components`;
-- DROP TABLE `proposal_version_lines`;
-- ALTER TABLE `proposals` DROP INDEX `proposals_organization_id_proposal_number_unique`;
-- ALTER TABLE `proposals` DROP FOREIGN KEY `proposals_winning_version_id_foreign`, DROP FOREIGN KEY `proposals_current_version_id_foreign`;
-- ALTER TABLE `proposals` DROP COLUMN `winning_version_id`, DROP COLUMN `current_version_id`, DROP COLUMN `proposal_number`;
-- DROP TABLE `proposal_versions`;


-- #####################################################################
-- STAGE 2 — 4A-3.3/3.4 FEATURE TABLES (2026-09-09)
-- Fully documented in the EXISTING companion — incorporated by
-- reference, not re-copied, to avoid the two ever drifting apart.
-- #####################################################################
--
-- Run docs/PHASE4A3_1_MANUAL_SQL_COMPANION.sql in full at this point:
--   Section 0  — its own [PREFLIGHT]
--   Sections 1-8 — proposal_number_sequences, proposal_pdf_artifacts,
--                  proposal_sends, proposal_send_attachments,
--                  proposal_client_responses, proposal_billing_handoffs,
--                  proposal_versions release-metadata ALTER,
--                  proposals value-widening + last_client_activity_at ALTER
--   Section 9  — its own [VERIFY]
--
-- (That file's own name is historical — despite being labeled
-- "PHASE4A3_1", it documents the 2026_09_09_* migration batch, i.e. what
-- this consolidated companion calls Stage 2. This mismatch is called out
-- explicitly here rather than silently — see this file's own
-- documentation-finalization note in the Phase 4A-3.6 report.)


-- #####################################################################
-- STAGE 3 — 4A-3.5 WON -> WINNING_VERSION_ID CHECK (2026-09-12)
-- Fully documented in the EXISTING companion — incorporated by
-- reference, not re-copied.
-- #####################################################################
--
-- Run docs/PHASE4A3_5_MANUAL_SQL_COMPANION.sql in full at this point:
--   Section 0 — its own mandatory [PREFLIGHT] (STOP conditions apply —
--               see that file's own Section 0.1/0.2)
--   Section 1 — the winning_version_id FK tightening + the CHECK itself
--   Section 2 — its own [VERIFY]


-- #####################################################################
-- ADDITIONAL PHASE 4A-3.6 PRE-DEPLOYMENT PREFLIGHT
-- (Section H of the Phase 4A-3.6 kickoff — run against PRODUCTION,
-- BEFORE any stage above, as the final go/no-go gate. All read-only.)
-- #####################################################################

-- H.1 DB/app backup required — not a query; a procedural gate. Confirm a
--     verified, restorable backup of the full database AND the
--     application file tree exists and is dated after the last real
--     production write, before touching anything below.

-- H.2 Current migration/schema version (what has production actually run
--     so far, if a `migrations` table already exists there from a prior
--     deploy).
SELECT migration, batch FROM migrations ORDER BY id;
-- If this table doesn't exist at all, production predates Laravel's own
-- migration tracking for this app — treat the ENTIRE schema as absent
-- and confirm with the team before assuming Stage 1 is safe to run fresh.

-- H.3 Current proposals.value precision — confirm no existing value would
--     be truncated by the DECIMAL(18,2) widening in Stage 2.
SELECT MAX(value) AS current_max_value, MIN(value) AS current_min_value FROM proposals;

-- H.4 proposal_number null/existing distribution.
SELECT COUNT(*) AS total, SUM(proposal_number IS NOT NULL) AS non_null FROM proposals;

-- H.5 ProposalVersion backfill expectations — every pre-4A-3 Proposal
--     needs exactly one legacy V1 after running
--     `php artisan aculyze:backfill-proposal-versions` (a one-time
--     Artisan command, NOT SQL — flagged here as a required step in the
--     deployment sequence, not something this file executes).
SELECT COUNT(*) AS proposals_needing_backfill FROM proposals WHERE current_version_id IS NULL;

-- H.6 Won-without-winning_version_id query — THE hard stop condition for
--     Stage 3. If this returns any row, STOP and resolve before Stage 3;
--     never fabricate a winning Version to force compliance.
SELECT id, organization_id, outcome, winning_version_id, stage, notes
FROM proposals
WHERE outcome = 'won' AND winning_version_id IS NULL;

-- H.7 winning_version_id same-Proposal join validation (MariaDB cannot
--     express this as a CHECK — this manual query is the substitute).
SELECT p.id AS proposal_id, p.winning_version_id, wv.proposal_id AS winning_version_actual_proposal_id
FROM proposals p
JOIN proposal_versions wv ON wv.id = p.winning_version_id
WHERE p.winning_version_id IS NOT NULL AND wv.proposal_id != p.id;
-- Expect: zero rows (this table won't exist until Stage 1 has run; run
-- this check only after Stage 1, before/alongside Stage 3).

-- H.8 Proposal PDF artifact constraints — every primary_lock_key resolves
--     to exactly one row (the UNIQUE index already guarantees this at
--     the DB level; this just surfaces the count for visibility).
SELECT COUNT(*) AS current_primary_pdf_artifacts FROM proposal_pdf_artifacts WHERE primary_lock_key IS NOT NULL;

-- H.9 send/client-response/billing-handoff table integrity — every
--     accepted_response_id on a billing handoff resolves to a real,
--     genuinely Accepted response.
SELECT bh.id AS handoff_id, bh.accepted_response_id
FROM proposal_billing_handoffs bh
LEFT JOIN proposal_client_responses cr ON cr.id = bh.accepted_response_id AND cr.response_type = 'accepted'
WHERE cr.id IS NULL;
-- Expect: zero rows.

-- H.10 Generated-column support in production MariaDB 11.8.8 — not a
--      query against THIS database; confirm directly on the production
--      server before Stage 1/Stage 2 by running, on a disposable scratch
--      table there:
--        CREATE TEMPORARY TABLE _gc_probe (a INT, b INT GENERATED ALWAYS AS (a + 1) STORED);
--        INSERT INTO _gc_probe (a) VALUES (1);
--        SELECT * FROM _gc_probe;
--        DROP TEMPORARY TABLE _gc_probe;
--      Already confirmed working on local MariaDB 10.11.14 for both
--      draft_lock_key and operative_accepted_lock_key/primary_lock_key —
--      this step re-confirms the SAME feature on the actual target
--      server/version before relying on it there.

-- H.11 Current proposal outcome distribution.
SELECT outcome, COUNT(*) AS proposal_count FROM proposals GROUP BY outcome;

-- H.12 Current ProposalVersion lifecycle distribution.
SELECT lifecycle_status, COUNT(*) AS version_count FROM proposal_versions GROUP BY lifecycle_status;

-- H.13 Current test/demo production data identification — list any
--      Prospect/Proposal whose name/email pattern suggests seeded test
--      data rather than real customers, so it can be excluded from
--      go-live reporting (adjust the LIKE patterns to this
--      installation's actual known seed/demo naming convention before
--      running against production).
SELECT id, company_name, email
FROM prospects
WHERE company_name LIKE '%test%' OR company_name LIKE '%demo%' OR email LIKE '%example.com%';
