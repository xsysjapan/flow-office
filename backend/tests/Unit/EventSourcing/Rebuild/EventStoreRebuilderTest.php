<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\AttendanceDayCurrent;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use PHPUnit\Framework\TestCase;

/**
 * 作り直し全体(分類・変換・並べ替え・id/版の振り直し)の純粋な検証。DBは使わない。
 */
class EventStoreRebuilderTest extends TestCase
{
    private EventStoreRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new EventStoreRebuilder;
    }

    public function test_rebuild_orders_by_original_position_and_renumbers_ids_and_versions(): void
    {
        $result = $this->rebuilder->rebuild($this->baseRows());

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame([], $result->unsupported);
        $this->assertSame(
            [
                'attendance_day.created',
                'paid_leave_account.grant_created',
                'paid_leave_request.requested',
                'paid_leave_account.usage_designated',
                'paid_leave_request.shared',
                'workflow_request.drafted',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.usage_allocated',
                'attendance_day.calculated',
                'paid_leave_account.grant_created',
            ],
            array_column($result->rows, 'event_class'),
        );

        $ids = array_column($result->rows, 'id');
        $this->assertSame(range(1, count($result->rows)), $ids);
    }

    public function test_versions_are_contiguous_per_aggregate_and_unrelated_aggregates_keep_their_versions(): void
    {
        $result = $this->rebuilder->rebuild($this->baseRows());

        $byAggregate = [];
        foreach ($result->rows as $row) {
            $byAggregate[$row['aggregate_uuid']][] = $row['aggregate_version'];
        }
        foreach ($byAggregate as $uuid => $versions) {
            $this->assertSame(range(1, count($versions)), $versions, "aggregate {$uuid}");
        }

        $this->assertSame([1, 2], $byAggregate['D1']);
        $this->assertSame([1], $byAggregate['W1']);
        $this->assertSame(0, $result->report['versions_changed_outside_leave']);
    }

    public function test_composed_rows_are_marked_with_rebuilt_from_and_keep_the_anchor_meta(): void
    {
        $result = $this->rebuilder->rebuild($this->baseRows());

        $designated = $this->rowOf($result->rows, 'paid_leave_account.usage_designated');
        $this->assertSame(3, $designated['meta_data']['rebuilt_from']);
        $this->assertSame('u1', $designated['aggregate_uuid']);

        $allocated = $this->rowOf($result->rows, 'paid_leave_account.usage_allocated');
        $this->assertSame(7, $allocated['meta_data']['rebuilt_from']);

        $requested = $this->rowOf($result->rows, 'paid_leave_request.requested');
        $this->assertArrayNotHasKey('rebuilt_from', $requested['meta_data']);
        $this->assertSame('R1', $requested['aggregate_uuid']);
    }

    public function test_carried_over_grant_keeps_cutover_information_in_meta(): void
    {
        $result = $this->rebuilder->rebuild($this->baseRows());

        $carried = array_values(array_filter($result->rows, fn (array $r) => ($r['event_properties']['source'] ?? null) === 'carried_over'));
        $this->assertCount(1, $carried);
        $this->assertSame('carry', $carried[0]['meta_data']['cutover']['mode']);
        $this->assertSame('G2', $carried[0]['event_properties']['grantId']);
    }

    public function test_unsupported_class_fails_and_writes_no_rows(): void
    {
        $rows = $this->baseRows();
        $rows[] = new StoredEventRow(60, 'S1', 1, 'attendance_day.corrected', ['userId' => 'u1'], [], '2026-08-02 10:00:00');

        $result = $this->rebuilder->rebuild($rows);

        $this->assertFalse($result->ok);
        $this->assertSame([], $result->rows);
        $this->assertSame('S1', $result->unsupported[0]['key']);
    }

    public function test_cutover_mismatch_fails_the_trial(): void
    {
        $rows = $this->baseRows();
        // G1 は 10 - 使用 1 = 9 だが cutover は 3 と言っている
        $rows[8] = new StoredEventRow(9, 'u1', 1, 'paid_leave_account.migrated', [
            'cutoverDate' => '2026-08-01',
            'grants' => [
                ['grantId' => 'G1', 'originalGrantedOn' => '2026-04-01', 'originalGrantedDays' => 10.0, 'remainingDaysAtCutover' => 3.0, 'expiresOn' => '2027-03-31', 'source' => 'migration', 'cutoverMetadata' => ['mode' => 'carry', 'notes' => null]],
            ],
        ], [], '2026-08-01 09:00:00');

        $result = $this->rebuilder->rebuild($rows);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('残数が一致しない', $result->unsupported[0]['reason']);
    }

    public function test_cutover_era_paid_request_is_linked_by_the_workflow_subject_and_converted(): void
    {
        $at = '2026-09-01 10:00:00';
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'G1', 1, 'paid_leave.granted', ['userId' => 'u1', 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null], [], $at),
            new StoredEventRow(2, 'W1', 1, 'workflow_request.drafted', ['applicantUserId' => 'u1', 'subjectType' => 'paid_leave_request', 'subjectId' => 'R1'], [], $at),
            new StoredEventRow(3, 'u1', 2, 'paid_leave_account.usage_designated', [
                'usageId' => 'x-1', 'workflowRequestId' => 'W1', 'attendanceDayId' => null, 'usedOn' => '2026-09-10', 'usedDays' => 1.0, 'usageType' => 'full',
                'paidLeaveRequestId' => null, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null, 'hours' => null,
            ], [], $at),
            new StoredEventRow(4, 'W1', 2, 'workflow_request.submitted', ['approverUserId' => 'a1', 'submittedByUserId' => 'u1'], [], $at),
            new StoredEventRow(5, 'u1', 3, 'paid_leave_account.usage_confirmed', ['usageId' => 'x-1', 'confirmedByUserId' => 'a1'], [], $at),
            new StoredEventRow(6, 'u1', 4, 'paid_leave_account.usage_allocated', ['usageId' => 'x-1', 'grantId' => 'G1', 'allocatedDays' => 1.0], [], $at),
            new StoredEventRow(7, 'W1', 3, 'workflow_request.approved', ['approvedByUserId' => 'a1'], [], $at),
        ]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(
            [
                'paid_leave_account.grant_created',
                'workflow_request.drafted',
                'paid_leave_request.requested',
                'paid_leave_request.shared',
                'paid_leave_account.usage_designated',
                'workflow_request.submitted',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.usage_allocated',
                'workflow_request.approved',
            ],
            array_column($result->rows, 'event_class'),
        );
        $this->assertSame([1, 2, 3, 4], $this->versionsOf($result->rows, 'u1'));
        $this->assertSame([1, 2, 3], $this->versionsOf($result->rows, 'R1'));
        $this->assertSame([1, 2, 3], $this->versionsOf($result->rows, 'W1'));
        $this->assertSame('approved', $result->report['paid_requests'][0]['final_state']);
        // 旧の乱数のusageIdはそのまま引き継ぐ。
        $this->assertSame('x-1', $this->rowOf($result->rows, 'paid_leave_account.usage_confirmed')['event_properties']['usageId']);
    }

    public function test_mixed_old_request_and_cutover_era_account_rows_are_unsupported(): void
    {
        $at = '2026-09-01 10:00:00';
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'R1', 1, 'paid_leave.requested', ['userId' => 'u1', 'targetDate' => '2026-09-10', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null], [], $at),
            new StoredEventRow(2, 'u1', 1, 'paid_leave_account.usage_designated', [
                'usageId' => 'x-1', 'workflowRequestId' => null, 'attendanceDayId' => null, 'usedOn' => '2026-09-10', 'usedDays' => 1.0, 'usageType' => 'full',
                'paidLeaveRequestId' => 'R1', 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null, 'hours' => null,
            ], [], $at),
        ]);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('混在', $result->unsupported[0]['reason']);
    }

    public function test_account_usage_without_a_request_link_is_unsupported(): void
    {
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'u1', 1, 'paid_leave_account.usage_designated', [
                'usageId' => 'x-1', 'workflowRequestId' => null, 'attendanceDayId' => null, 'usedOn' => '2026-09-10', 'usedDays' => 1.0, 'usageType' => 'full',
                'paidLeaveRequestId' => null, 'approverUserId' => null, 'reason' => null, 'requestGroupId' => null, 'hours' => null,
            ], [], '2026-09-01 10:00:00'),
        ]);

        $this->assertFalse($result->ok);
    }

    public function test_special_leave_request_links_the_used_rows_by_request_id_and_moves_the_grant_to_the_account(): void
    {
        $at = '2026-09-01 10:00:00';
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'SG1', 1, 'special_leave.granted', ['userId' => 'u1', 'specialLeaveTypeId' => 3, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 5.0, 'grantReason' => null], [], $at),
            new StoredEventRow(2, 'SR1', 1, 'special_leave.requested', $this->specialRequested(), [], $at),
            new StoredEventRow(3, 'SR1', 2, 'special_leave.request_approved', ['approvedByUserId' => 'a1'], [], $at),
            new StoredEventRow(4, 'SG1', 2, 'special_leave.used', ['userId' => 'u1', 'specialLeaveRequestId' => 'SR1', 'attendanceDayId' => 'D1', 'usedOn' => '2026-09-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full'], [], $at),
        ]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(
            [
                'special_leave_account.grant_registered',
                'special_leave.requested',
                'special_leave_account.usage_designated',
                'special_leave.request_approved',
                'special_leave_account.usage_confirmed',
            ],
            array_column($result->rows, 'event_class'),
        );
        $account = UserManagementStreamId::for('special_leave_account', 'u1');
        $this->assertSame([1, 2, 3], $this->versionsOf($result->rows, $account));
        $this->assertSame([1, 2], $this->versionsOf($result->rows, 'SR1'));
        $this->assertSame([], $this->versionsOf($result->rows, 'SG1'));
        $confirmed = $this->rowOf($result->rows, 'special_leave_account.usage_confirmed');
        $this->assertSame([['grantId' => 'SG1', 'allocatedDays' => 1.0]], $confirmed['event_properties']['allocations']);
        $this->assertSame('approved', $result->report['special_requests'][0]['final_state']);
        $this->assertSame(1, $result->report['leave_grants']['special_leave']);
    }

    public function test_used_row_of_an_unknown_special_leave_request_is_unsupported(): void
    {
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'SG1', 1, 'special_leave.granted', ['userId' => 'u1', 'specialLeaveTypeId' => 3, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 5.0, 'grantReason' => null], [], '2026-09-01 10:00:00'),
            new StoredEventRow(2, 'SG1', 2, 'special_leave.used', ['userId' => 'u1', 'specialLeaveRequestId' => 'SR9', 'attendanceDayId' => 'D1', 'usedOn' => '2026-09-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full'], [], '2026-09-01 10:00:00'),
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('SR9', $result->unsupported[0]['key']);
    }

    public function test_compensatory_leave_request_and_grants_are_converted_with_the_account_stream(): void
    {
        $at = '2026-09-01 10:00:00';
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'CG1', 1, 'compensatory_leave.manually_granted', ['userId' => 'u1', 'workDate' => '2026-08-01', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'expiresOn' => null, 'grantReason' => '手動'], [], $at),
            new StoredEventRow(2, 'CR1', 1, 'compensatory_leave.requested', [
                'userId' => 'u1', 'targetDate' => '2026-09-10', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'requestedMinutes' => null, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null,
            ], [], $at),
            new StoredEventRow(3, 'CR1', 2, 'compensatory_leave.request_approved', ['approvedByUserId' => 'a1', 'userId' => null], [], $at),
            new StoredEventRow(4, 'CG1', 2, 'compensatory_leave.used', ['userId' => 'u1', 'compensatoryLeaveRequestId' => 'CR1', 'attendanceDayId' => 'D1', 'usedOn' => '2026-09-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full'], [], $at),
        ]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(
            [
                'compensatory_leave_account.grant_manually_granted',
                'compensatory_leave.requested',
                'compensatory_leave_account.usage_designated',
                'compensatory_leave.request_approved',
                'compensatory_leave_account.usage_confirmed',
            ],
            array_column($result->rows, 'event_class'),
        );
        $this->assertSame('u1', $this->rowOf($result->rows, 'compensatory_leave.request_approved')['event_properties']['userId']);
        $this->assertSame(1, $result->report['leave_grants']['compensatory_leave']);
    }

    public function test_attendance_day_gets_created_and_deleted_around_a_cancelled_special_leave(): void
    {
        $at = '2026-09-01 10:00:00';
        $current = new AttendanceDayCurrent(
            id: 'D1', userId: 'u1', workDate: '2026-09-10', calendarEntryId: null, status: 'clocked_out', source: 'manual', utcOffsetMinutes: 540,
            actualStartAt: null, actualEndAt: null, workType: 'special_leave_full', workLocationType: null, dayClassification: null, note: null,
        );
        $result = $this->rebuilder->rebuild([
            new StoredEventRow(1, 'D1', 1, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 0], 'userId' => null, 'workDate' => null], [], $at),
            new StoredEventRow(2, 'SR1', 1, 'special_leave.requested', $this->specialRequested(), [], $at),
            new StoredEventRow(3, 'SR1', 2, 'special_leave.request_approved', ['approvedByUserId' => 'a1'], [], $at),
            new StoredEventRow(4, 'SR1', 3, 'special_leave.request_cancelled', ['cancelledByUserId' => 'u1'], [], $at),
        ], ['D1' => $current]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(
            [
                'attendance_day.created',
                'attendance_day.calculated',
                'special_leave.requested',
                'special_leave_account.usage_designated',
                'special_leave.request_approved',
                'special_leave_account.usage_confirmed',
                'special_leave.request_cancelled',
                'special_leave_account.usage_cancelled',
                'attendance_day.deleted',
            ],
            array_column($result->rows, 'event_class'),
        );
        $created = $this->rowOf($result->rows, 'attendance_day.created');
        $this->assertSame('leave', $created['event_properties']['source']);
        $this->assertSame('not_started', $created['event_properties']['status']);
        $this->assertNull($created['event_properties']['workType']);
        $calculated = $this->rowOf($result->rows, 'attendance_day.calculated');
        $this->assertSame('u1', $calculated['event_properties']['userId']);
        $this->assertSame('2026-09-10', $calculated['event_properties']['workDate']);
        $this->assertSame([1, 2, 3], $this->versionsOf($result->rows, 'D1'));
        $this->assertSame(['created_added' => 1, 'deleted_added' => 1], [
            'created_added' => $result->report['attendance']['created_added'],
            'deleted_added' => $result->report['attendance']['deleted_added'],
        ]);
    }

    /** @return array<string, mixed> */
    private function specialRequested(): array
    {
        return [
            'userId' => 'u1', 'specialLeaveTypeId' => 3, 'targetDate' => '2026-09-10', 'leaveType' => 'full', 'hours' => null,
            'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function versionsOf(array $rows, string $aggregate): array
    {
        return array_values(array_map(
            fn (array $row) => $row['aggregate_version'],
            array_filter($rows, fn (array $row) => $row['aggregate_uuid'] === $aggregate),
        ));
    }

    /** @return list<StoredEventRow> */
    private function baseRows(): array
    {
        $at = '2026-08-01 10:00:00';

        return [
            new StoredEventRow(1, 'D1', 1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'manual', 'workType' => null], [], $at),
            new StoredEventRow(2, 'G1', 1, 'paid_leave.granted', ['userId' => 'u1', 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null], [], $at),
            new StoredEventRow(3, 'R1', 1, 'paid_leave.requested', ['userId' => 'u1', 'targetDate' => '2026-08-10', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null], [], $at),
            new StoredEventRow(4, 'R1', 2, 'paid_leave.request_shared', ['workflowRequestId' => 'W1'], [], $at),
            new StoredEventRow(5, 'W1', 1, 'workflow_request.drafted', ['applicantUserId' => 'u1'], [], $at),
            new StoredEventRow(6, 'R1', 3, 'paid_leave.request_approved', ['approvedByUserId' => 'a1'], [], $at),
            new StoredEventRow(7, 'G1', 2, 'paid_leave.used', ['userId' => 'u1', 'paidLeaveRequestId' => 'R1', 'attendanceDayId' => 'day-1', 'usedOn' => '2026-08-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full'], [], $at),
            new StoredEventRow(8, 'D1', 2, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 480], 'userId' => 'u1'], [], $at),
            new StoredEventRow(9, 'u1', 1, 'paid_leave_account.migrated', [
                'cutoverDate' => '2026-08-01',
                'grants' => [
                    ['grantId' => 'G2', 'originalGrantedOn' => null, 'originalGrantedDays' => 5.0, 'remainingDaysAtCutover' => 5.0, 'expiresOn' => '2027-03-31', 'source' => 'migration', 'cutoverMetadata' => ['mode' => 'carry', 'notes' => null]],
                ],
            ], [], '2026-08-01 09:00:00'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowOf(array $rows, string $class): array
    {
        foreach ($rows as $row) {
            if ($row['event_class'] === $class) {
                return $row;
            }
        }
        $this->fail("row {$class} not found");
    }
}
