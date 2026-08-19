<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard "Today" KPI fix (root cause, not the symptom): Hostinger's
 * MySQL server runs on UTC (confirmed live via SELECT NOW()), while this
 * app has always generated and compared timestamps in
 * APP_TIMEZONE=Asia/Kolkata, with no 'timezone' key set on the mysql
 * connection (now added in config/database.php alongside this migration).
 * MySQL's TIMESTAMP columns silently mislabelled every stored IST
 * wall-clock value as if it were UTC. A ~24h "Today" window is narrow
 * enough for that mislabelling to miss most of a day's real data; wider
 * windows (This Week/Month/All Time) absorbed the same drift almost
 * invisibly — which is why only "Today" ever looked broken.
 *
 * Two things happen here, both scoped to MySQL only. SQLite (used by the
 * test suite) has no session-timezone concept at all and never exhibited
 * this bug in the first place, so this migration is a deliberate no-op
 * there — none of its raw SQL is MySQL/SQLite-portable.
 *
 * 1. call_records.called_at/follow_up_at/appointment_at,
 *    follow_ups.follow_up_at, and appointments.appointment_at convert
 *    from DATETIME to TIMESTAMP, so every date-tracking column behaves
 *    consistently once the connection is explicitly timezone-aware —
 *    DATETIME never gets any session-timezone conversion at all,
 *    regardless of this fix, and was the one column type NOT already
 *    covered by the correction below.
 *
 * 2. Every existing TIMESTAMP column (the five just converted, plus every
 *    pre-existing one) gets a one-time -5:30 correction. Under the
 *    session this app has always implicitly used (UTC), a TIMESTAMP
 *    column's stored value round-trips back out exactly as written —
 *    self-consistent today, but only because reads and writes have always
 *    shared the same (wrong) session assumption. The instant
 *    config/database.php starts forcing time_zone='+05:30' on every new
 *    connection, MySQL will add 5:30 to every existing row on read (the
 *    underlying stored bytes don't change just because the session's
 *    conversion target does) — so this subtracts 5:30 once now, while
 *    still under the current UTC session, so post-fix reads land back on
 *    the original, correct wall-clock value. Rows written after the
 *    config change go through the (now-correctly-calibrated) conversion
 *    automatically and need no such adjustment.
 *
 * This migration explicitly forces its own session to UTC via SET
 * time_zone='+00:00' at the very start, rather than relying on
 * config/database.php's new 'timezone' setting not having been deployed
 * yet — that makes the correction arithmetic correct regardless of
 * whether the config change and this migration land in the same deploy or
 * not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("SET time_zone = '+00:00'");

        DB::statement('ALTER TABLE call_records MODIFY called_at TIMESTAMP NOT NULL');
        DB::statement('ALTER TABLE call_records MODIFY follow_up_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE call_records MODIFY appointment_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE follow_ups MODIFY follow_up_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE appointments MODIFY appointment_at TIMESTAMP NULL');

        DB::statement('UPDATE users SET email_verified_at = email_verified_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE prospects SET created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE, deleted_at = deleted_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE call_records SET called_at = called_at - INTERVAL 5 HOUR 30 MINUTE, follow_up_at = follow_up_at - INTERVAL 5 HOUR 30 MINUTE, appointment_at = appointment_at - INTERVAL 5 HOUR 30 MINUTE, processed_at = processed_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE follow_ups SET follow_up_at = follow_up_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE appointments SET appointment_at = appointment_at - INTERVAL 5 HOUR 30 MINUTE, stage_changed_at = stage_changed_at - INTERVAL 5 HOUR 30 MINUTE, lost_at = lost_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE leads SET stage_changed_at = stage_changed_at - INTERVAL 5 HOUR 30 MINUTE, lost_at = lost_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE proposals SET stage_changed_at = stage_changed_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE export_requests SET decided_at = decided_at - INTERVAL 5 HOUR 30 MINUTE, expires_at = expires_at - INTERVAL 5 HOUR 30 MINUTE, downloaded_at = downloaded_at - INTERVAL 5 HOUR 30 MINUTE, created_at = created_at - INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at - INTERVAL 5 HOUR 30 MINUTE');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("SET time_zone = '+00:00'");

        DB::statement('UPDATE users SET email_verified_at = email_verified_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE prospects SET created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE, deleted_at = deleted_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE call_records SET called_at = called_at + INTERVAL 5 HOUR 30 MINUTE, follow_up_at = follow_up_at + INTERVAL 5 HOUR 30 MINUTE, appointment_at = appointment_at + INTERVAL 5 HOUR 30 MINUTE, processed_at = processed_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE follow_ups SET follow_up_at = follow_up_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE appointments SET appointment_at = appointment_at + INTERVAL 5 HOUR 30 MINUTE, stage_changed_at = stage_changed_at + INTERVAL 5 HOUR 30 MINUTE, lost_at = lost_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE leads SET stage_changed_at = stage_changed_at + INTERVAL 5 HOUR 30 MINUTE, lost_at = lost_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE proposals SET stage_changed_at = stage_changed_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');
        DB::statement('UPDATE export_requests SET decided_at = decided_at + INTERVAL 5 HOUR 30 MINUTE, expires_at = expires_at + INTERVAL 5 HOUR 30 MINUTE, downloaded_at = downloaded_at + INTERVAL 5 HOUR 30 MINUTE, created_at = created_at + INTERVAL 5 HOUR 30 MINUTE, updated_at = updated_at + INTERVAL 5 HOUR 30 MINUTE');

        DB::statement('ALTER TABLE call_records MODIFY called_at DATETIME NOT NULL');
        DB::statement('ALTER TABLE call_records MODIFY follow_up_at DATETIME NULL');
        DB::statement('ALTER TABLE call_records MODIFY appointment_at DATETIME NULL');
        DB::statement('ALTER TABLE follow_ups MODIFY follow_up_at DATETIME NULL');
        DB::statement('ALTER TABLE appointments MODIFY appointment_at DATETIME NULL');
    }
};
