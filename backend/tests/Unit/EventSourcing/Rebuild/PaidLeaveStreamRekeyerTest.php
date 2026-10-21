<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\PaidLeaveStreamRekeyer;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use PHPUnit\Framework\TestCase;

/**
 * 有給口座の付け替え・付与予定の取り除き(純粋な変換)の規則。DBは使わない。
 */
class PaidLeaveStreamRekeyerTest extends TestCase
{
    private PaidLeaveStreamRekeyer $rekeyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rekeyer = new PaidLeaveStreamRekeyer;
    }

    public function test_account_rows_move_to_the_new_stream_with_user_id_and_only_affected_streams_are_renumbered(): void
    {
        $new = PaidLeaveAccountAggregate::streamIdFor('u1');
        $rows = [
            $this->row(1, 'u1', 1, 'user.created', ['name' => 'a']),
            $this->row(2, 'u1', 2, 'paid_leave_account.grant_created', ['grantId' => 'g1', 'source' => 'manual']),
            $this->row(3, 'other', 1, 'shift_pattern.created', ['a' => 1]),
            $this->row(4, 'u1', 3, 'paid_leave_schedule.entry_created', ['scheduleEntryId' => 'e1']),
            $this->row(5, 'u1', 4, 'user.updated', ['name' => 'b']),
            $this->row(6, 'gap', 5, 'shift_pattern.created', ['a' => 2]),
        ];

        $result = $this->rekeyer->rekey($rows);

        $this->assertTrue($result->applied);
        $this->assertSame(1, $result->rekeyedRows);
        $this->assertSame(['paid_leave_schedule.entry_created' => 1], $result->removedByClass);
        $this->assertSame(['u1' => $new], $result->mapping);
        $this->assertSame([1, 2, 3, 5, 6], array_column($result->rows, 'id'), '残る行の id は変えない');

        $byId = array_column($result->rows, null, 'id');
        $this->assertSame($new, $byId[2]['aggregate_uuid']);
        $this->assertSame(['userId' => 'u1', 'grantId' => 'g1', 'source' => 'manual'], $byId[2]['event_properties']);
        $this->assertSame($new, $byId[2]['meta_data']['aggregate-root-uuid']);
        $this->assertSame(1, $byId[2]['aggregate_version']);
        $this->assertSame(1, $byId[2]['meta_data']['aggregate-root-version']);
        // 行が抜けて詰まる社員のストリーム。
        $this->assertSame([1, 2], [$byId[1]['aggregate_version'], $byId[5]['aggregate_version']]);
        $this->assertSame(2, $byId[5]['meta_data']['aggregate-root-version']);
        // 影響しないストリームは、版が飛んでいても(5)そのまま。それ以外の行も1つも変わらない。
        $this->assertSame($rows[2], $byId[3]);
        $this->assertSame($rows[5], $byId[6]);
    }

    public function test_applying_twice_gives_the_same_result(): void
    {
        $rows = [
            $this->row(1, 'u1', 1, 'user.created', []),
            $this->row(2, 'u1', 2, 'paid_leave_account.grant_created', ['grantId' => 'g1', 'source' => 'manual']),
            $this->row(3, 'u1', 3, 'paid_leave_schedule.entry_created', ['scheduleEntryId' => 'e1']),
        ];

        $once = $this->rekeyer->rekey($rows);
        $twice = $this->rekeyer->rekey($once->rows);

        $this->assertSame($once->rows, $twice->rows);
        $this->assertSame(0, $twice->rekeyedRows);
        $this->assertSame(0, $twice->removedRows());
        $this->assertSame(0, $twice->renumberedRows);
    }

    public function test_rows_already_in_the_new_stream_are_renumbered_after_moved_rows_by_id(): void
    {
        $new = PaidLeaveAccountAggregate::streamIdFor('u1');
        $rows = [
            $this->row(1, 'u1', 1, 'paid_leave_account.grant_created', ['grantId' => 'g1', 'source' => 'manual']),
            // 新しいコードが、付け替え前に新しいストリームへ書いた行(版1)。
            $this->row(2, $new, 1, 'paid_leave_account.grant_created', ['userId' => 'u1', 'grantId' => 'g2', 'source' => 'manual']),
        ];

        $result = $this->rekeyer->rekey($rows);

        $this->assertSame([1, 2], array_column($result->rows, 'aggregate_version'));
        $this->assertSame([$new, $new], array_column($result->rows, 'aggregate_uuid'));
        $this->assertSame(1, $result->rekeyedRows);
    }

    public function test_preconditions_stop_the_conversion_and_report_counts(): void
    {
        $rows = [
            $this->row(1, 's1', 1, 'paid_leave_schedule.entry_granted', []),
            $this->row(2, 's1', 2, 'paid_leave_schedule.assessment_overridden', []),
            $this->row(3, 's2', 1, 'paid_leave_schedule.entry_manually_edited', []),
            $this->row(4, 's2', 2, 'paid_leave_schedule.entry_manually_edited', []),
            $this->row(5, 'u1', 1, 'paid_leave_account.grant_created', ['source' => 'scheduled_batch']),
            $this->row(6, 'u1', 2, 'paid_leave_account.grant_created', ['source' => 'manual']),
            $this->row(7, 'u1', 3, 'paid_leave_account.grant_created', ['source' => 'carried_over']),
        ];

        $result = $this->rekeyer->rekey($rows);

        $this->assertFalse($result->applied);
        $this->assertSame($rows, $result->rows, '違反があれば変換しない');
        $this->assertSame([1, 1, 2, 1], array_values($result->violations));
        $this->assertSame(5, array_sum($result->violations));

        // 手動付与・移行だけなら止めない。
        $ok = $this->rekeyer->rekey([$rows[5], $rows[6]]);
        $this->assertTrue($ok->applied);
        $this->assertSame([], $ok->violations);
    }

    public function test_preconditions_can_be_skipped_for_the_rebuild_and_the_schedule_rows_are_removed(): void
    {
        $result = $this->rekeyer->rekey([$this->row(1, 's1', 1, 'paid_leave_schedule.entry_granted', [])], checkPreconditions: false);

        $this->assertTrue($result->applied);
        $this->assertSame([], $result->rows);
        $this->assertSame(['paid_leave_schedule.entry_granted' => 1], $result->removedByClass);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $aggregate, int $version, string $class, array $payload): array
    {
        return [
            'id' => $id,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $version,
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => $payload,
            'meta_data' => ['aggregate-root-uuid' => $aggregate, 'aggregate-root-version' => $version, 'stored-event-id' => $id, 'created-at' => '2026-09-01 10:00:00'],
            'created_at' => '2026-09-01 10:00:00',
        ];
    }
}
