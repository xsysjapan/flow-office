<?php

namespace Tests\Unit\EventSourcing;

use App\Domain\EventSourcing\HistoryEventTypesExporter;
use Tests\TestCase;

class HistoryEventTypesExporterTest extends TestCase
{
    public function test_committed_frontend_file_matches_render_output(): void
    {
        $path = HistoryEventTypesExporter::outputPath();

        $this->assertFileExists($path, 'frontend/src/generated/historyEventTypes.ts が無い。`php artisan events:export-history-types` で生成してコミットしてください。');
        $this->assertSame(
            (new HistoryEventTypesExporter)->render(),
            file_get_contents($path),
            'frontend/src/generated/historyEventTypes.ts が古い。`php artisan events:export-history-types` を実行してコミットしてください。',
        );
    }

    public function test_special_leave_prefix_does_not_match_special_leave_account(): void
    {
        $map = [
            'special_leave.requested' => 'A',
            'special_leave_account.grant_registered' => 'B',
            'special_leave.request_approved' => 'C',
        ];

        $this->assertSame(
            ['special_leave.requested', 'special_leave.request_approved'],
            HistoryEventTypesExporter::extract($map, ['special_leave.']),
        );
        $this->assertSame(
            ['special_leave_account.grant_registered'],
            HistoryEventTypesExporter::extract($map, ['special_leave_account.']),
        );
    }

    public function test_extract_keeps_config_order_across_prefixes(): void
    {
        $map = [
            'paid_leave_account.grant_created' => 'A',
            'paid_leave_request.requested' => 'B',
            'paid_leave_account.usage_allocated' => 'C',
            'asset.registered' => 'D',
        ];

        $this->assertSame(
            ['paid_leave_account.grant_created', 'paid_leave_request.requested', 'paid_leave_account.usage_allocated'],
            HistoryEventTypesExporter::extract($map, ['paid_leave_account.', 'paid_leave_request.']),
        );
    }

    public function test_extract_requires_dot_boundary_and_ignores_similar_names(): void
    {
        $map = [
            'asset_loan.requested' => 'A',
            'assets.registered' => 'B',
            'asset.registered' => 'C',
            'paid_leave.granted' => 'D',
        ];

        $this->assertSame(['asset.registered'], HistoryEventTypesExporter::extract($map, ['asset.']));
        $this->assertSame([], HistoryEventTypesExporter::extract($map, ['paid_leave_account.']));
    }

    public function test_render_contains_all_sections_with_expected_counts(): void
    {
        $content = (new HistoryEventTypesExporter)->render();

        $this->assertSame(17, $this->countEntries($content, 'paidLeaveHistoryEventTypes'));
        $this->assertSame(12, $this->countEntries($content, 'specialLeaveHistoryEventTypes'));
        $this->assertSame(14, $this->countEntries($content, 'compensatoryLeaveHistoryEventTypes'));
        $this->assertSame(17, $this->countEntries($content, 'assetHistoryEventTypes'));
    }

    public function test_paid_leave_section_contains_account_and_request_types(): void
    {
        $content = (new HistoryEventTypesExporter)->render();

        $this->assertStringContainsString("  'paid_leave_account.usage_allocated',\n", $content);
        $this->assertStringContainsString("  'paid_leave_request.resubmitted',\n", $content);
        $this->assertStringNotContainsString("'special_leave_account.", $this->section($content, 'paidLeaveHistoryEventTypes'));
    }

    public function test_special_leave_section_contains_both_prefixes_only(): void
    {
        $section = $this->section((new HistoryEventTypesExporter)->render(), 'specialLeaveHistoryEventTypes');

        $this->assertStringContainsString("'special_leave.request_shared'", $section);
        $this->assertStringContainsString("'special_leave_account.grant_revoked'", $section);
        $this->assertStringNotContainsString("'paid_leave", $section);
        $this->assertStringNotContainsString("'compensatory_leave", $section);
    }

    private function countEntries(string $content, string $name): int
    {
        return substr_count($this->section($content, $name), "\n  '");
    }

    private function section(string $content, string $name): string
    {
        $start = strpos($content, "export const {$name} = [");
        $this->assertNotFalse($start, "{$name} が生成されていない");
        $end = strpos($content, '] as const', $start);

        return substr($content, $start, $end - $start);
    }
}
