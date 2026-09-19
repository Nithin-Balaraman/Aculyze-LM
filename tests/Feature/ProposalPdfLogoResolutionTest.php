<?php

namespace Tests\Feature;

use App\Services\ProposalPdfArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression coverage for the PDF-logo-silently-missing bug:
 * ProposalPdfArtifactService::logoDataUri() previously only accepted a
 * real absolute filesystem path — a web-root-relative path (the natural
 * way ACULYZE_ORG_LOGO_PATH is written, e.g. "/images/logo.png") silently
 * resolved to no logo at all, with a real Proposal PDF generating
 * successfully and no error or log anywhere to explain the missing image.
 *
 * Fixed to also resolve such a path against public_path() before giving
 * up, and to log a distinguishing warning when a genuinely-configured
 * path still can't be resolved either way — but NOT when no path was
 * configured at all (a logo is optional; that case must stay silent,
 * exactly as before).
 */
class ProposalPdfLogoResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function invokeLogoDataUri(?string $logoPath): ?string
    {
        $service = app(ProposalPdfArtifactService::class);
        $method = new ReflectionMethod($service, 'logoDataUri');
        $method->setAccessible(true);

        return $method->invoke($service, $logoPath);
    }

    /**
     * (a) A leading-slash, web-root-relative path — exactly how
     * ACULYZE_ORG_LOGO_PATH was configured in the reported incident —
     * now resolves via public_path() and renders the real logo already
     * shipped at public/images/aculyze_logo.png, with no warning logged.
     */
    public function test_a_web_relative_path_resolves_via_public_path_and_renders_the_logo(): void
    {
        Log::spy();

        $result = $this->invokeLogoDataUri('/images/aculyze_logo.png');

        $this->assertNotNull($result);
        $this->assertStringStartsWith('data:image/png;base64,', $result);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * (b) No path configured at all is a normal, intentional absence
     * (locked Decision 2: a logo is optional) — must stay completely
     * silent, unchanged from before this fix.
     */
    public function test_a_blank_path_silently_omits_the_logo_with_no_warning(): void
    {
        Log::spy();

        $result = $this->invokeLogoDataUri(null);

        $this->assertNull($result);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * (c) A path that was genuinely configured but resolves to nothing —
     * neither as-is nor under public_path() — is a real misconfiguration,
     * not an intentional absence, so it now logs a distinguishing warning
     * naming the exact configured value (while generation still succeeds
     * without a logo, per locked Decision 2 — a logo is optional).
     */
    public function test_a_configured_but_unresolvable_path_logs_a_distinguishing_warning(): void
    {
        Log::spy();

        $badPath = '/images/this-file-does-not-exist-anywhere.png';

        $result = $this->invokeLogoDataUri($badPath);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->once()->with(
            'Proposal PDF letterhead logo is configured but could not be loaded — generating without it.',
            ['configured_logo_path' => $badPath],
        );
    }
}
