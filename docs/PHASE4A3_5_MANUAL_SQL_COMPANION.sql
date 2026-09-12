-- =====================================================================
-- PHASE 4A-3.5 — MANUAL SQL DEPLOYMENT COMPANION
-- =====================================================================
--
-- Reviewable companion to the single Laravel migration dated
-- 2026_09_12_090000 (add_won_requires_winning_version_check_to_proposals_
-- table). NOT executed against production as part of this implementation
-- slice — 4A-3.5 closes the application-level gate LOCALLY / IN CODE only
-- (see the kickoff's explicit "P. NO PRODUCTION DEPLOYMENT" instruction).
-- Hostinger production has no reliable SSH/Composer/Artisan workflow, so
-- this file exists to be reviewed and run by hand (phpMyAdmin or an
-- equivalent MariaDB client) during the later, separate, controlled
-- deployment (4A-3.6), exactly as every prior Phase migration to
-- production has been handled.
--
-- Target: MariaDB 11.8.8 (production) / 10.11.14 (local dev, where every
-- statement below has already been run via `php artisan migrate` and
-- verified — see the Phase 4A-3.5 implementation report). The FK/CHECK
-- shown here is copied VERBATIM from `SHOW CREATE TABLE` on the local
-- database after migrating, not hand-transcribed from the Blueprint
-- definitions.
--
-- =====================================================================
-- SECTION 0 — MANDATORY PRE-FLIGHT CHECKS (read-only; run first)
-- =====================================================================
--
-- RULE: if 0.1 or 0.2 below return any row, STOP. Do not proceed with the
-- ALTER statements. Do NOT fabricate a winning Version to force a row into
-- compliance — investigate the exact row(s) and resolve deliberately
-- (a separate, explicitly-approved backfill, never invented here).

-- 0.1 Every Proposal currently Won without a winning_version_id — this
--     migration's CHECK constraint would reject any further UPDATE to
--     such a row (though the ALTER TABLE itself does not validate
--     existing data server-side on this MariaDB version; still, any row
--     returned here represents a real state that must be resolved before
--     relying on the CHECK for future writes).
SELECT id, organization_id, outcome, winning_version_id, stage, notes
FROM proposals
WHERE outcome = 'won' AND winning_version_id IS NULL;

-- 0.2 Every winning_version_id that does not belong to the same Proposal
--     it's attached to (the CHECK only proves "not null", never "belongs
--     to this Proposal" — that remains service/domain-enforced, per the
--     kickoff's explicit "same-parent consistency stays service/domain
--     enforced" instruction; this preflight surfaces it manually instead).
SELECT p.id AS proposal_id, p.winning_version_id, wv.proposal_id AS winning_version_actual_proposal_id
FROM proposals p
JOIN proposal_versions wv ON wv.id = p.winning_version_id
WHERE p.winning_version_id IS NOT NULL AND wv.proposal_id != p.id;

-- 0.3 Useful context (not a blocker either way): current outcome
--     distribution, so a reviewer can sanity-check the counts against
--     0.1's result before proceeding.
SELECT outcome, COUNT(*) AS proposal_count
FROM proposals
GROUP BY outcome;

-- 0.4 Useful context: current ProposalVersion lifecycle distribution.
SELECT lifecycle_status, COUNT(*) AS version_count
FROM proposal_versions
GROUP BY lifecycle_status;

-- 0.5 Confirm every proposals.current_version_id still resolves to a real
--     row (referential sanity, mirrors the 4A-3.1 preflight's same check —
--     this migration does not touch current_version_id's FK, but this is
--     a cheap re-confirmation before altering the table at all).
SELECT p.id AS proposal_id, p.current_version_id
FROM proposals p
LEFT JOIN proposal_versions cv ON cv.id = p.current_version_id
WHERE p.current_version_id IS NOT NULL AND cv.id IS NULL;

-- 0.6 Confirm no ProposalVersion row that is currently referenced as
--     someone's winning_version_id has any characteristic suggesting a
--     delete was ever attempted against it (there is no soft-delete flag
--     on proposal_versions, so this is purely a referential presence
--     check — a genuinely missing row would already have shown up in 0.2's
--     JOIN failing to match, which INNER JOIN would simply omit; this
--     confirms the row count lines up).
SELECT COUNT(*) AS distinct_winning_versions_referenced,
       (SELECT COUNT(DISTINCT winning_version_id) FROM proposals WHERE winning_version_id IS NOT NULL) AS expected
FROM proposal_versions
WHERE id IN (SELECT winning_version_id FROM proposals WHERE winning_version_id IS NOT NULL);

-- =====================================================================
-- SECTION 1 — 2026_09_12_090000_add_won_requires_winning_version_check
-- =====================================================================
--
-- Prerequisite FK tightening (empirically required — see the migration's
-- own docblock and the Phase 4A-3.5 implementation report): MariaDB
-- refuses ANY CHECK constraint on a column carrying an ON DELETE SET NULL
-- foreign key action. winning_version_id is tightened from nullOnDelete to
-- restrictOnDelete first. No ProposalVersion row is ever deleted by any
-- runtime path in this codebase (confirmed by inventory), so this is a
-- pure tightening with no behavioral impact on any existing flow.

ALTER TABLE `proposals`
  DROP FOREIGN KEY `proposals_winning_version_id_foreign`;

ALTER TABLE `proposals`
  ADD CONSTRAINT `proposals_winning_version_id_foreign`
    FOREIGN KEY (`winning_version_id`) REFERENCES `proposal_versions` (`id`);

-- The final locked DB invariant: Won requires a valid winning_version_id.
-- NULL outcome, or a non-Won outcome with any winning_version_id value
-- (including NULL), both satisfy this via SQL three-valued logic.

ALTER TABLE `proposals`
  ADD CONSTRAINT `proposals_won_requires_winning_version`
    CHECK (`outcome` <> 'won' OR `winning_version_id` IS NOT NULL);

-- =====================================================================
-- SECTION 2 — POST-DEPLOY VERIFICATION (read-only)
-- =====================================================================

-- 2.1 Confirm the FK's ON DELETE action changed and the CHECK now exists,
--     verbatim from SHOW CREATE TABLE.
SHOW CREATE TABLE proposals;

-- 2.2 Confirm the CHECK actually rejects a direct violation (run inside a
--     transaction you intend to ROLLBACK — never COMMIT this probe).
-- START TRANSACTION;
-- UPDATE proposals SET outcome = 'won', winning_version_id = NULL WHERE id = <a real, non-Won id>;
-- -- Expect: ERROR 4025 (23000): CONSTRAINT `proposals_won_requires_winning_version` failed
-- ROLLBACK;

-- 2.3 Re-run 0.1 above — must still return zero rows after deployment
--     (nothing this migration does silently fixes or hides a violation;
--     it only prevents new ones).

-- =====================================================================
-- EXPLICITLY NOT INCLUDED IN THIS FILE
-- =====================================================================
-- - Any data backfill or fabricated winning_version_id for historical
--   Won-without-winner rows. If Section 0.1 above returns ANY row against
--   production, deployment of this migration must STOP and that data must
--   be investigated and resolved through a separate, explicitly-approved
--   effort — never invented here.
-- - Any change to current_version_id's FK (nullOnDelete unchanged — no
--   invariant in this phase requires touching it).
-- - A cross-table "winning_version_id belongs to this same Proposal" DB
--   CHECK — MariaDB cannot express this as a table CHECK constraint at
--   all (no subqueries allowed); it remains service/domain-enforced
--   (Section 0.2 above is the manual preflight substitute).
-- - Any PipelineBoard/ProposalResource application-code change — this
--   file is schema-only; the Phase 4A-3.5 code changes (PipelineBoard drag
--   refusal, ProposalResource form cutover) ship as ordinary application
--   code, not SQL.
-- - Any production deployment execution. This file is reviewed and run by
--   hand during the later 4A-3.6 controlled deployment, not now.
