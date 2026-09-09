-- =====================================================================
-- PHASE 4A-3.1 — MANUAL SQL DEPLOYMENT COMPANION
-- =====================================================================
--
-- Reviewable companion to the eight Laravel migrations dated
-- 2026_09_09_090000 through 2026_09_09_090700. NOT executed as part of
-- this implementation slice — Hostinger production has no reliable SSH/
-- Artisan access, so this file exists to be reviewed and run by hand
-- (phpMyAdmin or an equivalent MariaDB client) during a later, separate
-- deployment step, exactly as every prior Phase migration to production
-- has been handled.
--
-- Target: MariaDB 11.8.8 (production) / 10.11.14 (local dev, where every
-- statement below has already been run via `php artisan migrate` and
-- verified — see the Phase 4A-3.1 implementation report). Every generated
-- column, FK, and index shown here is copied VERBATIM from
-- `SHOW CREATE TABLE` on the local database after migrating, not
-- hand-transcribed from the Blueprint definitions, so it is byte-for-byte
-- what MariaDB itself produced.
--
-- No data backfill anywhere in this file. Every new table starts empty.
-- The final `Won -> winning_version_id` CHECK constraint is deliberately
-- NOT included here — it remains 4A-3.5 work, only safe once the
-- uncontrolled legacy writers of `proposals.outcome` are removed
-- (PHASE4_OUTCOME_CUTOVER_GATE item 10).
--
-- =====================================================================
-- SECTION 0 — PREFLIGHT CHECKS (read-only; run first, change nothing)
-- =====================================================================

-- 0.1 Confirm no existing proposals.value already exceeds what
--     DECIMAL(12,2) could hold cleanly — widening to DECIMAL(18,2) is a
--     safe, non-destructive ALTER regardless, but this documents the real
--     production ceiling before touching it.
SELECT MAX(value) AS current_max_value, MIN(value) AS current_min_value
FROM proposals;

-- 0.2 Confirm proposals.proposal_number is uniformly NULL today (no
--     accidental pre-existing values that a fresh allocation could
--     collide with).
SELECT COUNT(*) AS non_null_proposal_numbers
FROM proposals
WHERE proposal_number IS NOT NULL;

-- 0.3 Confirm every proposals.current_version_id / winning_version_id
--     still resolves to a real, same-organization proposal_versions row
--     (referential sanity before adding more FKs into this same table
--     family — a genuine mismatch here would predate this migration set
--     entirely and must be investigated separately, not silently
--     tolerated).
SELECT p.id AS proposal_id, p.current_version_id, p.winning_version_id
FROM proposals p
LEFT JOIN proposal_versions cv ON cv.id = p.current_version_id
LEFT JOIN proposal_versions wv ON wv.id = p.winning_version_id
WHERE (p.current_version_id IS NOT NULL AND cv.id IS NULL)
   OR (p.winning_version_id IS NOT NULL AND wv.id IS NULL)
   OR (cv.id IS NOT NULL AND cv.organization_id != p.organization_id)
   OR (wv.id IS NOT NULL AND wv.organization_id != p.organization_id);

-- 0.4 Confirm the `organizations` table has at least the rows every new
--     FK below will reference (sanity check only — every environment
--     that has run Phase 1 already satisfies this).
SELECT COUNT(*) AS organization_count FROM organizations;

-- =====================================================================
-- SECTION 1 — 2026_09_09_090000_create_proposal_number_sequences_table
-- =====================================================================

CREATE TABLE `proposal_number_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `next_number` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_number_sequences_organization_id_year_unique` (`organization_id`,`year`),
  CONSTRAINT `proposal_number_sequences_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 2 — 2026_09_09_090100_create_proposal_pdf_artifacts_table
-- =====================================================================
-- Generated column note: `primary_lock_key` is STORED (materialized on
-- write, indexable) — confirmed supported and correct on both MariaDB
-- 10.11.14 (local) and the documented production 11.8.8 target, same
-- technique already live in production via
-- proposal_versions.draft_lock_key.

CREATE TABLE `proposal_pdf_artifacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_version_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `template_version` varchar(255) NOT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `storage_path` varchar(255) DEFAULT NULL,
  `byte_size` bigint(20) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `generated_by` bigint(20) unsigned NOT NULL,
  `failure_reason` text DEFAULT NULL,
  `correction_reason` text DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_artifact_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `primary_lock_key` bigint(20) unsigned GENERATED ALWAYS AS (case when `status` = 'success' and `superseded_at` is null then `proposal_version_id` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_pdf_artifacts_primary_lock_key_unique` (`primary_lock_key`),
  KEY `proposal_pdf_artifacts_proposal_version_id_foreign` (`proposal_version_id`),
  KEY `proposal_pdf_artifacts_generated_by_foreign` (`generated_by`),
  KEY `proposal_pdf_artifacts_superseded_by_artifact_id_foreign` (`superseded_by_artifact_id`),
  KEY `ppa_organization_id_version_id_index` (`organization_id`,`proposal_version_id`),
  CONSTRAINT `proposal_pdf_artifacts_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `proposal_pdf_artifacts_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_pdf_artifacts_proposal_version_id_foreign` FOREIGN KEY (`proposal_version_id`) REFERENCES `proposal_versions` (`id`),
  CONSTRAINT `proposal_pdf_artifacts_superseded_by_artifact_id_foreign` FOREIGN KEY (`superseded_by_artifact_id`) REFERENCES `proposal_pdf_artifacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 3 — 2026_09_09_090200_create_proposal_sends_table
-- =====================================================================
-- No `sent_lock_key` column of any kind (locked Decision 23) — multiple
-- successful sends per Version are allowed; `idempotency_key` is the only
-- uniqueness constraint, scoped per send OPERATION, not per Version.

CREATE TABLE `proposal_sends` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned NOT NULL,
  `proposal_version_id` bigint(20) unsigned NOT NULL,
  `pdf_artifact_id` bigint(20) unsigned NOT NULL,
  `method` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `to_recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`to_recipients`)),
  `cc_recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cc_recipients`)),
  `subject` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `attempted_at` timestamp NOT NULL,
  `attempted_by` bigint(20) unsigned NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `sent_by` bigint(20) unsigned DEFAULT NULL,
  `provider_reference` varchar(255) DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_sends_idempotency_key_unique` (`idempotency_key`),
  KEY `proposal_sends_proposal_id_foreign` (`proposal_id`),
  KEY `proposal_sends_proposal_version_id_foreign` (`proposal_version_id`),
  KEY `proposal_sends_pdf_artifact_id_foreign` (`pdf_artifact_id`),
  KEY `proposal_sends_attempted_by_foreign` (`attempted_by`),
  KEY `proposal_sends_sent_by_foreign` (`sent_by`),
  KEY `proposal_sends_organization_id_proposal_id_index` (`organization_id`,`proposal_id`),
  KEY `ps_organization_id_version_id_index` (`organization_id`,`proposal_version_id`),
  CONSTRAINT `proposal_sends_attempted_by_foreign` FOREIGN KEY (`attempted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `proposal_sends_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_sends_pdf_artifact_id_foreign` FOREIGN KEY (`pdf_artifact_id`) REFERENCES `proposal_pdf_artifacts` (`id`),
  CONSTRAINT `proposal_sends_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`),
  CONSTRAINT `proposal_sends_proposal_version_id_foreign` FOREIGN KEY (`proposal_version_id`) REFERENCES `proposal_versions` (`id`),
  CONSTRAINT `proposal_sends_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 4 — 2026_09_09_090300_create_proposal_send_attachments_table
-- =====================================================================
-- No UNIQUE on checksum_sha256 (alone or with proposal_send_id) — locked
-- Decision 24: manifest cardinality and byte-level dedup are different
-- concerns; two selected attachments may legitimately share a checksum.

CREATE TABLE `proposal_send_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_send_id` bigint(20) unsigned NOT NULL,
  `checksum_sha256` char(64) NOT NULL,
  `archived_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(255) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `proposal_send_attachments_proposal_send_id_foreign` (`proposal_send_id`),
  KEY `psa_organization_id_checksum_index` (`organization_id`,`checksum_sha256`),
  CONSTRAINT `proposal_send_attachments_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_send_attachments_proposal_send_id_foreign` FOREIGN KEY (`proposal_send_id`) REFERENCES `proposal_sends` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 5 — 2026_09_09_090400_create_proposal_client_responses_table
-- =====================================================================
-- `operative_accepted_lock_key` enforces "at most one OPERATIVE Accepted
-- per Proposal at a time", never "one Accepted ever" (locked Decision 13)
-- — a future Reopen sets superseded_at on the old Accepted row, freeing
-- this lock with no migration required.

CREATE TABLE `proposal_client_responses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned NOT NULL,
  `proposal_version_id` bigint(20) unsigned NOT NULL,
  `response_type` varchar(255) NOT NULL,
  `reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `next_action` varchar(255) DEFAULT NULL,
  `resulting_draft_version_id` bigint(20) unsigned DEFAULT NULL,
  `follow_up_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_by` bigint(20) unsigned NOT NULL,
  `recorded_at` timestamp NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_response_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `operative_accepted_lock_key` bigint(20) unsigned GENERATED ALWAYS AS (case when `response_type` = 'accepted' and `superseded_at` is null then `proposal_id` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_client_responses_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `proposal_client_responses_operative_accepted_lock_key_unique` (`operative_accepted_lock_key`),
  KEY `proposal_client_responses_proposal_id_foreign` (`proposal_id`),
  KEY `proposal_client_responses_proposal_version_id_foreign` (`proposal_version_id`),
  KEY `proposal_client_responses_resulting_draft_version_id_foreign` (`resulting_draft_version_id`),
  KEY `proposal_client_responses_follow_up_id_foreign` (`follow_up_id`),
  KEY `proposal_client_responses_recorded_by_foreign` (`recorded_by`),
  KEY `proposal_client_responses_superseded_by_response_id_foreign` (`superseded_by_response_id`),
  KEY `pcr_organization_id_proposal_id_index` (`organization_id`,`proposal_id`),
  KEY `pcr_organization_id_version_id_index` (`organization_id`,`proposal_version_id`),
  CONSTRAINT `proposal_client_responses_follow_up_id_foreign` FOREIGN KEY (`follow_up_id`) REFERENCES `follow_ups` (`id`),
  CONSTRAINT `proposal_client_responses_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_client_responses_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`),
  CONSTRAINT `proposal_client_responses_proposal_version_id_foreign` FOREIGN KEY (`proposal_version_id`) REFERENCES `proposal_versions` (`id`),
  CONSTRAINT `proposal_client_responses_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `proposal_client_responses_resulting_draft_version_id_foreign` FOREIGN KEY (`resulting_draft_version_id`) REFERENCES `proposal_versions` (`id`),
  CONSTRAINT `proposal_client_responses_superseded_by_response_id_foreign` FOREIGN KEY (`superseded_by_response_id`) REFERENCES `proposal_client_responses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 6 — 2026_09_09_090500_create_proposal_billing_handoffs_table
-- =====================================================================
-- Exactly-once backstop is UNIQUE(accepted_response_id), NOT
-- UNIQUE(proposal_id) — locked Decision 15: a future Reopen must remain
-- able to produce a second legitimate handoff for a second Accepted event
-- on the same Proposal.

CREATE TABLE `proposal_billing_handoffs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned NOT NULL,
  `winning_version_id` bigint(20) unsigned NOT NULL,
  `accepted_response_id` bigint(20) unsigned NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_billing_handoffs_accepted_response_id_unique` (`accepted_response_id`),
  UNIQUE KEY `proposal_billing_handoffs_idempotency_key_unique` (`idempotency_key`),
  KEY `proposal_billing_handoffs_proposal_id_foreign` (`proposal_id`),
  KEY `proposal_billing_handoffs_winning_version_id_foreign` (`winning_version_id`),
  KEY `pbh_organization_id_proposal_id_index` (`organization_id`,`proposal_id`),
  CONSTRAINT `proposal_billing_handoffs_accepted_response_id_foreign` FOREIGN KEY (`accepted_response_id`) REFERENCES `proposal_client_responses` (`id`),
  CONSTRAINT `proposal_billing_handoffs_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `proposal_billing_handoffs_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`),
  CONSTRAINT `proposal_billing_handoffs_winning_version_id_foreign` FOREIGN KEY (`winning_version_id`) REFERENCES `proposal_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 7 — 2026_09_09_090600_add_release_metadata_to_proposal_versions_table
-- =====================================================================
-- Release is metadata layered on top of the existing lifecycle, never a
-- new ProposalVersionLifecycle value. Release staleness is DERIVED (no
-- boolean column): stale exactly when released_pdf_artifact_id no longer
-- equals the Version's current primary artifact.

ALTER TABLE `proposal_versions`
  ADD COLUMN `released_at` timestamp NULL DEFAULT NULL AFTER `sent_at`,
  ADD COLUMN `released_by` bigint(20) unsigned DEFAULT NULL AFTER `released_at`,
  ADD COLUMN `released_pdf_artifact_id` bigint(20) unsigned DEFAULT NULL AFTER `released_by`,
  ADD COLUMN `release_comment` text DEFAULT NULL AFTER `released_pdf_artifact_id`;

ALTER TABLE `proposal_versions`
  ADD CONSTRAINT `proposal_versions_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `proposal_versions_released_pdf_artifact_id_foreign` FOREIGN KEY (`released_pdf_artifact_id`) REFERENCES `proposal_pdf_artifacts` (`id`);

-- =====================================================================
-- SECTION 8 — 2026_09_09_090700_widen_value_and_add_activity_cache_to_proposals_table
-- =====================================================================
-- Non-destructive widening only — every existing value fits unchanged
-- (confirmed by preflight check 0.1 above).

ALTER TABLE `proposals`
  MODIFY COLUMN `value` decimal(18,2) DEFAULT NULL;

ALTER TABLE `proposals`
  ADD COLUMN `last_client_activity_at` timestamp NULL DEFAULT NULL AFTER `stage_changed_at`;

-- =====================================================================
-- SECTION 9 — POST-DEPLOY VERIFICATION (read-only)
-- =====================================================================

-- 9.1 Confirm every new table exists and is empty (no fabricated history).
SELECT
  (SELECT COUNT(*) FROM proposal_number_sequences) AS proposal_number_sequences_count,
  (SELECT COUNT(*) FROM proposal_pdf_artifacts) AS proposal_pdf_artifacts_count,
  (SELECT COUNT(*) FROM proposal_sends) AS proposal_sends_count,
  (SELECT COUNT(*) FROM proposal_send_attachments) AS proposal_send_attachments_count,
  (SELECT COUNT(*) FROM proposal_client_responses) AS proposal_client_responses_count,
  (SELECT COUNT(*) FROM proposal_billing_handoffs) AS proposal_billing_handoffs_count;

-- 9.2 Confirm proposals.value widened correctly and proposal_number/
--     last_client_activity_at are untouched (still NULL everywhere).
SELECT COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposals'
  AND COLUMN_NAME IN ('value', 'proposal_number', 'last_client_activity_at');

SELECT COUNT(*) AS non_null_proposal_numbers_after_deploy,
       SUM(last_client_activity_at IS NOT NULL) AS non_null_last_client_activity_at_after_deploy
FROM proposals;

-- 9.3 Confirm the generated columns compute as expected on MariaDB
--     11.8.8 (mirrors the local verification already performed — see the
--     Phase 4A-3.1 implementation report).
SELECT COLUMN_NAME, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_pdf_artifacts'
  AND COLUMN_NAME = 'primary_lock_key';

SELECT COLUMN_NAME, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_client_responses'
  AND COLUMN_NAME = 'operative_accepted_lock_key';

-- =====================================================================
-- EXPLICITLY NOT INCLUDED IN THIS FILE
-- =====================================================================
-- - Any data backfill for the six new tables (none needed — none exists).
-- - The final `CHECK (outcome != 'won' OR winning_version_id IS NOT NULL)`
--   constraint — deferred to 4A-3.5, only safe once every uncontrolled
--   legacy writer of proposals.outcome is removed
--   (PHASE4_OUTCOME_CUTOVER_GATE item 10).
-- - Any PDF generation, Release, Send, or Client Response service code —
--   this file is schema-only, matching the 4A-3.1 implementation scope.
