<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dashboard "Today" KPI fix: Hostinger's MySQL server runs on UTC
 * (confirmed live via SELECT NOW()), while this app generates and
 * compares timestamps in APP_TIMEZONE=Asia/Kolkata. Without an explicit
 * 'timezone' on the mysql connection, MySQL silently mislabels every
 * TIMESTAMP column's stored IST wall-clock value as UTC — invisible
 * across wide date ranges, but enough to miss most of a day's data in a
 * ~24h "Today" window. This just locks the fix in place so it can't be
 * silently reverted; the underlying MySQL behavior itself isn't
 * reproducible against this suite's SQLite connection.
 */
class DatabaseTimezoneConfigTest extends TestCase
{
    public function test_the_mysql_connection_has_an_explicit_fixed_offset_timezone(): void
    {
        $timezone = config('database.connections.mysql.timezone');

        $this->assertSame('+05:30', $timezone);

        // A fixed offset, not a named zone: MySQL's named-timezone support
        // depends on the server's mysql.time_zone_name tables being
        // loaded, which isn't guaranteed on shared hosting.
        $this->assertMatchesRegularExpression('/^[+-]\d{2}:\d{2}$/', $timezone);
    }
}
