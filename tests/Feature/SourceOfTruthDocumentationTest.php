<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F7: the repository must actually contain the rules it references.
 *
 * Dozens of docblocks, comments, migrations and tests cite AGENTS.md by
 * section number. Before this pass those citations pointed at a file that
 * did not exist. This is the guard that keeps them honest: it re-derives
 * every cited section number from the codebase on each run, so adding a new
 * `AGENTS.md section N` reference to a number that was never written up
 * fails here rather than quietly rotting.
 */
class SourceOfTruthDocumentationTest extends TestCase
{
    private function repositoryFiles(): array
    {
        $paths = [];

        foreach (['app', 'tests', 'database', 'config', 'resources'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php'], true)) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }

    /**
     * Every section number the codebase cites, including the endpoints of a
     * range like "sections 24-27" and the individual numbers in a list like
     * "sections 11, 29, 58".
     *
     * @return array<int, int>
     */
    private function citedSectionNumbers(): array
    {
        $cited = [];

        foreach ($this->repositoryFiles() as $path) {
            $contents = file_get_contents($path);

            if (! str_contains($contents, 'AGENTS.md')) {
                continue;
            }

            // "AGENTS.md section 16", "AGENTS.md sections 24-27, 44",
            // "AGENTS.md §32" — capture the whole run of numbers, ranges and
            // separators that follows the keyword.
            preg_match_all('/AGENTS\.md\s+(?:sections?|§)\s*([0-9,\s\-–\/]+)/u', $contents, $matches);

            foreach ($matches[1] as $run) {
                preg_match_all('/(\d+)\s*[-–]\s*(\d+)|(\d+)/', $run, $parts, PREG_SET_ORDER);

                foreach ($parts as $part) {
                    if (($part[1] ?? '') !== '' && ($part[2] ?? '') !== '') {
                        foreach (range((int) $part[1], (int) $part[2]) as $number) {
                            $cited[] = $number;
                        }

                        continue;
                    }

                    $cited[] = (int) $part[3];
                }
            }
        }

        $cited = array_values(array_unique(array_filter($cited, fn (int $n) => $n >= 1 && $n <= 61)));
        sort($cited);

        return $cited;
    }

    /** @return array<int, int> */
    private function documentedSectionNumbers(): array
    {
        $agents = file_get_contents(base_path('AGENTS.md'));

        // Headings are either "## 16. Title" or "## 35-36. Not reconstructed".
        preg_match_all('/^##\s+(\d+)(?:\s*[-–]\s*(\d+))?\./m', $agents, $matches, PREG_SET_ORDER);

        $documented = [];

        foreach ($matches as $match) {
            $from = (int) $match[1];
            $to = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : $from;

            foreach (range($from, $to) as $number) {
                $documented[] = $number;
            }
        }

        sort($documented);

        return $documented;
    }

    public function test_the_three_source_of_truth_documents_are_tracked(): void
    {
        $this->assertFileExists(base_path('AGENTS.md'));
        $this->assertFileExists(base_path('docs/PHASE4_OUTCOME_CUTOVER_GATE.md'));
        $this->assertFileExists(base_path('docs/OPEN_BUSINESS_DECISIONS.md'));
    }

    public function test_the_codebase_actually_cites_agents_md_sections(): void
    {
        // Guards the guard: if the extraction below silently stopped
        // matching, every other assertion here would pass vacuously.
        $this->assertGreaterThan(20, count($this->citedSectionNumbers()));
    }

    public function test_every_cited_agents_md_section_resolves(): void
    {
        $missing = array_values(array_diff($this->citedSectionNumbers(), $this->documentedSectionNumbers()));

        $this->assertSame([], $missing, 'AGENTS.md is missing section(s) the codebase cites: '.implode(', ', $missing));
    }

    public function test_agents_md_covers_the_numbered_range_without_gaps(): void
    {
        // Numbering is fixed forever — a gap would mean a future citation of
        // that number silently resolves to nothing.
        $documented = $this->documentedSectionNumbers();

        $this->assertSame(range(1, 61), $documented);
    }

    public function test_the_cited_section_61_questions_are_documented(): void
    {
        $agents = file_get_contents(base_path('AGENTS.md'));

        foreach ([1, 2, 3, 5, 6, 7] as $question) {
            $this->assertMatchesRegularExpression(
                '/\*\*Question '.$question.' —/',
                $agents,
                "AGENTS.md section 61 is missing Question {$question}, which the codebase cites."
            );
        }
    }

    public function test_the_cutover_gate_is_documented_as_open_with_ten_items(): void
    {
        $gate = file_get_contents(base_path('docs/PHASE4_OUTCOME_CUTOVER_GATE.md'));

        $this->assertStringContainsString('Status: OPEN', $gate);
        $this->assertStringNotContainsString('Status: CLOSED', $gate);

        preg_match_all('/^### \d+\. /m', $gate, $items);
        $this->assertCount(10, $items[0], 'The gate checklist must have exactly 10 items.');
    }

    public function test_the_open_decisions_document_tracks_both_known_decisions(): void
    {
        $decisions = file_get_contents(base_path('docs/OPEN_BUSINESS_DECISIONS.md'));

        $this->assertStringContainsString('OPEN-1', $decisions);
        $this->assertStringContainsString('OPEN-2', $decisions);
        $this->assertStringContainsString('offboarding', $decisions);
        $this->assertStringContainsString('origin_type', $decisions);
    }
}
