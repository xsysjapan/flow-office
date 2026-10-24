<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\PaidLeaveScheduleCurrent;
use App\Domain\EventSourcing\Rebuild\PaidLeaveScheduleRebuilder;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use PHPUnit\Framework\TestCase;

/**
 * 旧版の付与予定イベントの置き換え(論点19 決定3)の正常・境界・異常。ReadModelの現在値は PaidLeaveScheduleCurrent で渡す。
 */
class PaidLeaveScheduleRebuilderTest extends TestCase
{
    private const ENTRY = 'entry-1';

    private PaidLeaveScheduleRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new PaidLeaveScheduleRebuilder;
    }

    public function test_legacy_row_is_detected_by_entry_id_without_schedule_entry_id(): void
    {
        $this->assertTrue(PaidLeaveScheduleRebuilder::isLegacyRow($this->row(1, 'entry_created', ['entryId' => 'e'])));
        $this->assertFalse(PaidLeaveScheduleRebuilder::isLegacyRow($this->row(1, 'entry_created', ['scheduleEntryId' => 'e'])));
        $this->assertFalse(PaidLeaveScheduleRebuilder::isLegacyRow($this->row(1, 'entry_created', ['entryId' => 'e', 'scheduleEntryId' => 'e'])));
        $this->assertFalse(PaidLeaveScheduleRebuilder::isLegacyRow(new StoredEventRow(1, 'u1', 1, 'attendance_day.created', ['entryId' => 'e'], [], '2026-09-01 10:00:00')));
    }

    public function test_plain_scheduled_entry_becomes_an_entry_created_only(): void
    {
        $result = $this->rebuilder->rebuild([$this->row(7, 'entry_created', ['entryId' => self::ENTRY])], [self::ENTRY => $this->current()]);

        $this->assertSame([], $result->issues);
        $this->assertSame([7], $result->consumedIds);
        $this->assertSame(['paid_leave_schedule.entry_created' => 1], $result->replacedByClass);
        $this->assertSame(['paid_leave_schedule.entry_created' => 1], $result->derivedByClass);
        $event = $result->events[0];
        $this->assertSame('u1', $event->aggregateUuid);
        $this->assertSame(7, $event->anchorId);
        $this->assertSame('2026-09-01 09:00:00', $event->createdAt);
        $this->assertSame([
            'scheduleEntryId' => self::ENTRY,
            'scheduledOn' => '2026-10-01',
            'category' => 'standard',
            'candidateGrantDays' => 10.0,
            'isDeterminate' => true,
        ], $event->payload);
    }

    public function test_needs_review_entry_is_not_determinate(): void
    {
        $result = $this->rebuilder->rebuild([$this->row(1, 'entry_created', ['entryId' => self::ENTRY])], [self::ENTRY => $this->current(['status' => 'NeedsReview'])]);

        $this->assertFalse($result->events[0]->payload['isDeterminate']);
    }

    public function test_full_history_is_derived_in_order_with_created_at_of_the_matching_old_event(): void
    {
        $rows = [
            $this->row(1, 'entry_created', ['entryId' => self::ENTRY], '2026-09-01 09:00:00'),
            $this->row(2, 'assessment_recorded', ['entryId' => self::ENTRY], '2026-09-02 09:00:00'),
            $this->row(3, 'assessment_overridden', ['entryId' => self::ENTRY], '2026-09-03 09:00:00'),
            $this->row(4, 'entry_granted', ['entryId' => self::ENTRY, 'operatorUserId' => 'admin'], '2026-09-04 09:00:00'),
            $this->row(5, 'entry_cancelled', ['entryId' => self::ENTRY], '2026-09-05 09:00:00'),
        ];
        $current = $this->current([
            'status' => 'Cancelled',
            'isManuallyOverridden' => true,
            'manualOverrideReason' => '調整',
            'manualOverrideByUserId' => 'admin2',
            'grantId' => 'grant-1',
            'cancelledReason' => null,
            'assessments' => [$this->assessment(['finalResult' => 'Eligible', 'automaticResult' => 'NotEligible', 'overrideReason' => '例外', 'overriddenByUserId' => 'admin3'])],
        ]);

        $result = $this->rebuilder->rebuild($rows, [self::ENTRY => $current]);

        $this->assertSame([], $result->issues);
        $this->assertSame([1, 2, 3, 4, 5], $result->consumedIds);
        $classes = array_map(fn ($e) => $e->eventClass, $result->events);
        $this->assertSame([
            'paid_leave_schedule.entry_created',
            'paid_leave_schedule.assessment_recorded',
            'paid_leave_schedule.assessment_overridden',
            'paid_leave_schedule.entry_manually_edited',
            'paid_leave_schedule.entry_granted',
            'paid_leave_schedule.entry_cancelled',
        ], $classes);
        $this->assertSame([0, 1, 2, 3, 4, 5], array_map(fn ($e) => $e->subOrder, $result->events));
        $this->assertSame(['2026-09-01 09:00:00', '2026-09-02 09:00:00', '2026-09-03 09:00:00', '2026-09-01 09:00:00', '2026-09-04 09:00:00', '2026-09-05 09:00:00'], array_map(fn ($e) => $e->createdAt, $result->events));

        $payloads = array_column(array_map(fn ($e) => ['c' => $e->eventClass, 'p' => $e->payload], $result->events), 'p', 'c');
        $this->assertSame([
            'scheduleEntryId' => self::ENTRY, 'assessmentId' => 'a1', 'periodStart' => '2025-04-01', 'periodEnd' => '2026-03-31', 'denominatorDays' => 240,
            'attendanceDays' => 200, 'excludedDays' => 2, 'attendanceRate' => 83.33, 'policyVersion' => 'v1', 'automaticResult' => 'NotEligible',
        ], $payloads['paid_leave_schedule.assessment_recorded']);
        $this->assertSame(['scheduleEntryId' => self::ENTRY, 'assessmentId' => 'a1', 'finalResult' => 'Eligible', 'reason' => '例外', 'operatorUserId' => 'admin3'], $payloads['paid_leave_schedule.assessment_overridden']);
        $this->assertSame([
            'scheduleEntryId' => self::ENTRY,
            'changes' => ['category' => 'standard', 'candidateGrantDays' => 10.0],
            'reason' => '調整',
            'operatorUserId' => 'admin2',
        ], $payloads['paid_leave_schedule.entry_manually_edited']);
        $this->assertSame(['scheduleEntryId' => self::ENTRY, 'grantId' => 'grant-1', 'operatorUserId' => 'admin'], $payloads['paid_leave_schedule.entry_granted']);
        $this->assertSame(['scheduleEntryId' => self::ENTRY, 'reason' => '既存データの引き継ぎ'], $payloads['paid_leave_schedule.entry_cancelled']);
    }

    public function test_manual_edit_and_cancel_use_the_row_timestamps_when_there_is_no_old_event(): void
    {
        $result = $this->rebuilder->rebuild([$this->row(1, 'entry_created', ['entryId' => self::ENTRY])], [self::ENTRY => $this->current([
            'status' => 'Cancelled', 'isManuallyOverridden' => true, 'manualOverrideReason' => '調整', 'manualOverrideByUserId' => 'a',
            'manualOverrideAt' => '2026-09-03 08:00:00', 'updatedAt' => '2026-09-06 08:00:00',
        ])]);

        $byClass = array_column(array_map(fn ($e) => ['c' => $e->eventClass, 't' => $e->createdAt], $result->events), 't', 'c');
        $this->assertSame('2026-09-03 08:00:00', $byClass['paid_leave_schedule.entry_manually_edited']);
        $this->assertSame('2026-09-06 08:00:00', $byClass['paid_leave_schedule.entry_cancelled']);
    }

    public function test_assessment_without_override_has_no_overridden_and_grant_operator_defaults_to_the_user(): void
    {
        $result = $this->rebuilder->rebuild(
            [$this->row(1, 'entry_created', ['entryId' => self::ENTRY])],
            [self::ENTRY => $this->current(['grantId' => 'grant-1', 'cancelledReason' => '理由', 'status' => 'Cancelled', 'assessments' => [$this->assessment()]])],
        );

        $classes = array_map(fn ($e) => $e->eventClass, $result->events);
        $this->assertNotContains('paid_leave_schedule.assessment_overridden', $classes);
        $granted = array_values(array_filter($result->events, fn ($e) => $e->eventClass === 'paid_leave_schedule.entry_granted'))[0];
        $this->assertSame('u1', $granted->payload['operatorUserId']);
        $cancelled = array_values(array_filter($result->events, fn ($e) => $e->eventClass === 'paid_leave_schedule.entry_cancelled'))[0];
        $this->assertSame('理由', $cancelled->payload['reason']);
        // 対応する旧版のイベントが無い付与・取消の発生日時は entry_created と同じ(行の created_at)。
        $this->assertSame('2026-09-01 09:00:00', $granted->createdAt);
    }

    public function test_events_of_one_user_are_placed_at_the_first_old_row_and_numbered_across_entries(): void
    {
        $rows = [
            $this->row(5, 'entry_created', ['entryId' => 'e1']),
            $this->row(9, 'entry_created', ['entryId' => 'e2']),
        ];
        $result = $this->rebuilder->rebuild($rows, ['e1' => $this->current(['id' => 'e1']), 'e2' => $this->current(['id' => 'e2'])]);

        $this->assertSame([5, 5], array_map(fn ($e) => $e->anchorId, $result->events));
        $this->assertSame([0, 1], array_map(fn ($e) => $e->subOrder, $result->events));
        $this->assertSame(['e1', 'e2'], array_map(fn ($e) => $e->payload['scheduleEntryId'], $result->events));
    }

    public function test_entry_without_a_read_model_row_is_reported(): void
    {
        $result = $this->rebuilder->rebuild([$this->row(1, 'entry_created', ['entryId' => 'missing'])], []);

        $this->assertCount(1, $result->issues);
        $this->assertSame('missing', $result->issues[0]['key']);
        $this->assertSame([], $result->events);
    }

    public function test_required_value_that_cannot_be_decided_is_reported(): void
    {
        $result = $this->rebuilder->rebuild([$this->row(1, 'entry_created', ['entryId' => self::ENTRY])], [self::ENTRY => $this->current(['category' => null])]);
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('category', $result->issues[0]['reason']);

        $result = $this->rebuilder->rebuild([$this->row(1, 'entry_created', ['entryId' => self::ENTRY])], [self::ENTRY => $this->current([
            'assessments' => [$this->assessment(['finalResult' => 'Eligible', 'automaticResult' => 'NotEligible', 'overrideReason' => null])],
        ])]);
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('override_reason', $result->issues[0]['reason']);

        $result = $this->rebuilder->rebuild([$this->row(1, 'entry_created', ['entryId' => self::ENTRY])], [self::ENTRY => $this->current(['isManuallyOverridden' => true, 'manualOverrideReason' => null, 'manualOverrideByUserId' => 'a'])]);
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('manual_override_reason', $result->issues[0]['reason']);
    }

    public function test_event_store_rebuilder_replaces_legacy_rows_and_leaves_other_rows(): void
    {
        $rows = [
            $this->row(1, 'entry_created', ['entryId' => self::ENTRY]),
            new StoredEventRow(2, 'x1', 1, 'shift_pattern.created', ['a' => 1], [], '2026-09-01 10:00:00'),
            $this->row(3, 'entry_granted', ['entryId' => self::ENTRY, 'operatorUserId' => 'admin']),
            // 現行の形(scheduleEntryId)は触らない。
            new StoredEventRow(4, 'u2', 1, 'paid_leave_schedule.entry_cancelled', ['scheduleEntryId' => 'z', 'reason' => 'r'], [], '2026-09-01 10:00:00'),
        ];

        $result = (new EventStoreRebuilder)->rebuild($rows, [], [self::ENTRY => $this->current(['status' => 'Granted', 'grantId' => 'g1'])]);

        $this->assertTrue($result->ok);
        $this->assertSame(['paid_leave_schedule.entry_cancelled' => 1, 'paid_leave_schedule.entry_created' => 1, 'paid_leave_schedule.entry_granted' => 1, 'shift_pattern.created' => 1], $this->sorted($result->report['by_class_after']));
        $this->assertSame(['paid_leave_schedule.entry_created' => 1, 'paid_leave_schedule.entry_granted' => 1], $this->sorted($result->report['paid_leave_schedule']['replaced']));
        $this->assertSame(['paid_leave_schedule.entry_created' => 1, 'paid_leave_schedule.entry_granted' => 1], $this->sorted($result->report['paid_leave_schedule']['derived']));
        // 変換した付与予定のイベントは、最後の工程(PaidLeaveStreamRekeyer)で取り除かれ、id は詰められる。
        $this->assertSame(3, $result->report['paid_leave_rekey']['removed_rows']);
        $this->assertSame([1], array_column($result->rows, 'id'));
        $this->assertSame(['shift_pattern.created'], array_column($result->rows, 'event_class'));
    }

    public function test_event_store_rebuilder_fails_when_the_read_model_row_is_missing(): void
    {
        $result = (new EventStoreRebuilder)->rebuild([$this->row(1, 'entry_created', ['entryId' => 'missing'])]);

        $this->assertFalse($result->ok);
        $this->assertSame('missing', $result->unsupported[0]['key']);
    }

    /** @param  array<string, int>  $counts */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /** @param  array<string, mixed>  $payload */
    private function row(int $id, string $suffix, array $payload, string $createdAt = '2026-09-01 10:00:00'): StoredEventRow
    {
        return new StoredEventRow($id, 'old-aggregate', $id, 'paid_leave_schedule.'.$suffix, $payload, ['aggregate-root-uuid' => 'old-aggregate'], $createdAt);
    }

    /** @param  array<string, mixed>  $overrides */
    private function current(array $overrides = []): PaidLeaveScheduleCurrent
    {
        $values = $overrides + [
            'id' => self::ENTRY,
            'userId' => 'u1',
            'scheduledOn' => '2026-10-01 00:00:00',
            'category' => 'standard',
            'candidateGrantDays' => 10.0,
            'status' => 'Scheduled',
            'isManuallyOverridden' => false,
            'manualOverrideReason' => null,
            'manualOverrideByUserId' => null,
            'grantId' => null,
            'cancelledReason' => null,
            'createdAt' => '2026-09-01 09:00:00',
            'manualOverrideAt' => null,
            'updatedAt' => null,
            'assessments' => [],
        ];

        return new PaidLeaveScheduleCurrent(...$values);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assessment(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'a1', 'periodStart' => '2025-04-01', 'periodEnd' => '2026-03-31', 'denominatorDays' => 240, 'attendanceDays' => 200, 'excludedDays' => 2,
            'attendanceRate' => 83.33, 'policyVersion' => 'v1', 'automaticResult' => 'Eligible', 'finalResult' => 'Eligible',
            'overrideReason' => null, 'overriddenByUserId' => null, 'createdAt' => '2026-09-02 09:00:00',
        ];
    }
}
