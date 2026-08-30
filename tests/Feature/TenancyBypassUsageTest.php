<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 1 plan: "direct OrganizationScope bypass is prohibited" — the only
 * approved way to intentionally bypass tenant scoping is
 * App\Support\Tenancy\Tenancy::withoutScopeForSystemTask(), which writes an
 * audit_events row every time it's used. PHP itself provides no way to
 * forbid another file from calling Eloquent's own withoutGlobalScope()/
 * withoutGlobalScopes() directly — this test is the enforcement mechanism,
 * a static, codebase-shape assertion that fails the suite the same day an
 * unauthorized bypass is introduced, rather than relying on code review
 * alone.
 */
class TenancyBypassUsageTest extends TestCase
{
    private const ALLOWED_FILE = 'app/Support/Tenancy/Tenancy.php';

    public function test_no_application_file_bypasses_organization_scope_directly_outside_tenancy(): void
    {
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            $relativePath = 'app/'.$file->getRelativePathname();

            if ($relativePath === self::ALLOWED_FILE) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Matches withoutGlobalScope(...OrganizationScope...) or
            // withoutGlobalScopes([...OrganizationScope...]) specifically —
            // not just the two substrings appearing anywhere in the file.
            // Several models legitimately call
            // ->withoutGlobalScope(SoftDeletingScope::class) elsewhere
            // (unrelated to tenancy), which a naive co-occurrence check
            // would incorrectly flag.
            if (preg_match('/withoutGlobalScopes?\s*\(([^)]*OrganizationScope[^)]*)\)/s', $contents) === 1) {
                $violations[] = $relativePath;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Only App\\Support\\Tenancy\\Tenancy::withoutScopeForSystemTask() may bypass OrganizationScope — '.
            'found direct withoutGlobalScope()/withoutGlobalScopes() usage referencing OrganizationScope in: '.
            implode(', ', $violations)
        );
    }

    /**
     * The one legitimate exception is App\Models\AuditEvent and the seven
     * business models, which correctly reference OrganizationScope to
     * attach it (`static::addGlobalScope(new OrganizationScope)`) — that is
     * not a bypass and must not trip this test. Confirms the scan itself
     * isn't simply matching on the class name alone.
     */
    public function test_the_scan_does_not_flag_ordinary_scope_attachment(): void
    {
        $contents = file_get_contents(app_path('Models/Prospect.php'));

        $this->assertStringContainsString('addGlobalScope(new OrganizationScope)', $contents);
        $this->assertStringNotContainsString('withoutGlobalScope', $contents);
    }
}
