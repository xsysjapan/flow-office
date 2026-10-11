<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\AttendanceDayCurrent;
use App\Domain\EventSourcing\Rebuild\AttendanceRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use PHPUnit\Framework\TestCase;

/**
 * 勤怠日の変換(論点18 assets/event-rebuild-mapping.md 2.4・2.5)の正常・境界・異常。
 * ReadModel(attendance_days)の現在値は AttendanceDayCurrent として渡す(DBは使わない)。
 */
class AttendanceRebuilderTest extends TestCase
{
    private const DAY = 'day-1';

    private AttendanceRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new AttendanceRebuilder;
    }

    public function test_created_is_added_before_the_first_event_from_the_current_values(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current([
            'actualStartAt' => '2026-08-10 09:00:00',
            'actualEndAt' => '2026-08-10 18:00:00',
            'status' => 'clocked_out',
            'source' => 'manual',
            'calendarEntryId' => 'entry-1',
            'workLocationType' => 'office',
            'note' => 'メモ',
            'breaks' => [['start' => '2026-08-10 12:00:00', 'end' => '2026-08-10 13:00:00']],
        ]));

        $created = $this->ordered($result->events)[0];
        $this->assertTrue($result->createdAdded);
        $this->assertSame('attendance_day.created', $created->eventClass);
        $this->assertSame(self::DAY, $created->aggregateUuid);
        $this->assertSame(5, $created->anchorId);
        $this->assertSame(-1, $created->subOrder);
        $this->assertSame(5, $created->rebuiltFrom);
        $this->assertSame(
            [
                'userId' => 'u1',
                'workDate' => '2026-08-10',
                'calendarEntryId' => 'entry-1',
                'status' => 'clocked_out',
                'source' => 'manual',
                'utcOffsetMinutes' => 540,
                'actualStartAt' => '2026-08-10T09:00:00+09:00',
                'actualEndAt' => '2026-08-10T18:00:00+09:00',
                'workType' => null,
                'workLocationType' => 'office',
                'note' => 'メモ',
                'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
                'leaveSegments' => [],
                'reason' => '既存データの引き継ぎ',
                'createdByUserId' => 'u1',
            ],
            $created->payload,
        );
    }

    public function test_a_day_made_by_leave_without_actuals_gets_source_leave_and_status_not_started(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current([
            'status' => 'clocked_out',
            'source' => 'manual',
            'workType' => 'paid_leave_full',
        ]), [['userId' => 'applicant-1', 'finalState' => 'approved', 'lastRelease' => null]]);

        $created = $this->ordered($result->events)[0];
        $this->assertSame('leave', $created->payload['source']);
        $this->assertSame('not_started', $created->payload['status']);
        $this->assertNull($created->payload['workType']);
        $this->assertSame('applicant-1', $created->payload['createdByUserId']);
    }

    public function test_a_day_with_leave_but_actuals_keeps_the_current_source_and_status(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current([
            'actualStartAt' => '2026-08-10 09:00:00',
            'status' => 'working',
            'source' => 'live',
        ]), [['userId' => 'u1', 'finalState' => 'approved', 'lastRelease' => null]]);

        $created = $this->ordered($result->events)[0];
        $this->assertSame('live', $created->payload['source']);
        $this->assertSame('working', $created->payload['status']);
    }

    public function test_a_punch_counts_as_an_actual_for_status_and_source(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current([
            'status' => 'clocked_out',
            'source' => 'punch',
            'hasPunch' => true,
        ]), [['userId' => 'u1', 'finalState' => 'approved', 'lastRelease' => null]]);

        $created = $this->ordered($result->events)[0];
        $this->assertSame('punch', $created->payload['source']);
        $this->assertSame('clocked_out', $created->payload['status']);
    }

    public function test_created_is_not_added_when_the_first_event_creates_the_row(): void
    {
        foreach (['attendance_day.created', 'attendance_day.synced_from_punches', 'attendance_day.live_status_synced'] as $class) {
            $result = $this->rebuilder->rebuild(self::DAY, [$this->row(1, $class, ['userId' => 'u1', 'workDate' => '2026-08-10', 'workType' => null, 'source' => 'manual'])], null);

            $this->assertFalse($result->createdAdded, $class);
            $this->assertSame([], $result->events, $class);
        }
    }

    public function test_missing_current_values_make_the_day_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], null);
    }

    public function test_leave_work_type_in_created_and_edited_is_replaced_by_the_previous_non_leave_value(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [
            $this->row(1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'manual', 'workType' => 'paid_leave_full']),
            $this->row(2, 'attendance_day.edited', ['workType' => 'office']),
            $this->row(3, 'attendance_day.edited', ['workType' => 'special_leave_am_half']),
            $this->row(4, 'attendance_day.edited', ['workType' => 'compensatory_leave_full']),
            $this->row(5, 'attendance_day.edited', ['workType' => 'remote']),
            $this->row(6, 'attendance_day.edited', ['workType' => 'paid_leave_hourly']),
        ], null);

        $byId = [];
        foreach ($result->events as $event) {
            $byId[$event->anchorId] = $event->payload['workType'];
        }
        // 先頭の休暇値は直前の値が無いのでnull。休暇値でないedited(2・5)は変えない(写さない)。
        $this->assertSame([1 => null, 3 => 'office', 4 => 'office', 6 => 'remote'], $byId);
        $this->assertSame(4, $result->workTypeRewritten);
        $this->assertEqualsCanonicalizing([1, 3, 4, 6], $result->consumedIds);
    }

    public function test_calculated_null_items_are_filled_from_the_created_event(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [
            $this->row(1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'manual', 'workType' => null]),
            $this->row(2, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 480], 'userId' => null, 'workDate' => null]),
            $this->row(3, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 450]]),
        ], null);

        $this->assertSame(['u1', '2026-08-10'], [$result->events[0]->payload['userId'], $result->events[0]->payload['workDate']]);
        $this->assertSame(['u1', '2026-08-10'], [$result->events[1]->payload['userId'], $result->events[1]->payload['workDate']]);
        $this->assertSame(['work_minutes' => 450], $result->events[1]->payload['calculation']);
        $this->assertSame(4, $result->nullItemsFilled);
    }

    public function test_adjusted_day_classification_and_work_minutes_come_from_the_preceding_calculation(): void
    {
        $result = $this->rebuilder->rebuild(self::DAY, [
            $this->row(1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'manual', 'workType' => null]),
            $this->row(2, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 480, 'day_classification' => 'legal_holiday'], 'userId' => 'u1', 'workDate' => '2026-08-10']),
            $this->row(3, 'attendance_day.daily_calculation_adjusted', ['prescribedWorkMinutes' => 0, 'reason' => '調整', 'adjustedByUserId' => 'admin']),
        ], null);

        $adjusted = $result->events[0];
        $this->assertSame(3, $adjusted->anchorId);
        $this->assertSame('u1', $adjusted->payload['userId']);
        $this->assertSame('2026-08-10', $adjusted->payload['workDate']);
        $this->assertSame('legal_holiday', $adjusted->payload['dayClassification']);
        $this->assertSame(480, $adjusted->payload['workMinutes']);
    }

    public function test_adjusted_without_a_source_for_work_minutes_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild(self::DAY, [
            $this->row(1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'manual', 'workType' => null]),
            $this->row(2, 'attendance_day.daily_calculation_adjusted', ['reason' => '調整', 'adjustedByUserId' => 'admin']),
        ], null);
    }

    public function test_calculated_without_any_source_for_the_identity_is_unsupported(): void
    {
        $this->expectException(UnsupportedEventSequence::class);

        $this->rebuilder->rebuild(self::DAY, [$this->row(1, 'attendance_day.synced_from_punches', []), $this->row(2, 'attendance_day.calculated', ['calculation' => []])], null);
    }

    public function test_deleted_is_placed_right_after_the_release_when_the_day_is_empty_and_has_no_later_event(): void
    {
        $release = $this->row(8, 'special_leave.request_cancelled', ['cancelledByUserId' => 'u2']);

        $result = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current(['source' => 'leave', 'workType' => 'paid_leave_full']), [
            ['userId' => 'u1', 'finalState' => 'cancelled', 'lastRelease' => ['row' => $release, 'operatorUserId' => 'u2']],
        ]);

        $events = $this->ordered($result->events);
        $this->assertTrue($result->deletedAdded);
        $deleted = $events[count($events) - 1];
        $this->assertSame('attendance_day.deleted', $deleted->eventClass);
        $this->assertSame(8, $deleted->anchorId);
        $this->assertSame(
            ['userId' => 'u1', 'workDate' => '2026-08-10', 'reason' => '休暇の差戻し・取消に伴い、休暇のために作成した勤怠日を削除', 'deletedByUserId' => 'u2', 'punchLogAction' => 'leave_punches'],
            $deleted->payload,
        );
    }

    public function test_deleted_is_not_placed_when_a_later_event_exists(): void
    {
        $release = $this->row(8, 'special_leave.request_cancelled', ['cancelledByUserId' => 'u2']);

        $result = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5), $this->calculated(9)], $this->current(['source' => 'leave']), [
            ['userId' => 'u1', 'finalState' => 'cancelled', 'lastRelease' => ['row' => $release, 'operatorUserId' => 'u2']],
        ]);

        $this->assertFalse($result->deletedAdded);
    }

    public function test_deleted_is_not_placed_when_another_leave_is_still_active_or_the_day_is_used(): void
    {
        $release = $this->row(8, 'special_leave.request_cancelled', ['cancelledByUserId' => 'u2']);
        $cancelled = ['userId' => 'u1', 'finalState' => 'cancelled', 'lastRelease' => ['row' => $release, 'operatorUserId' => 'u2']];

        $withActiveLeave = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current(['source' => 'leave']), [
            $cancelled,
            ['userId' => 'u1', 'finalState' => 'approved', 'lastRelease' => null],
        ]);
        $withActual = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current(['source' => 'leave', 'actualStartAt' => '2026-08-10 09:00:00']), [$cancelled]);
        $notLeaveSource = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current(['source' => 'manual', 'actualStartAt' => '2026-08-10 09:00:00']), [$cancelled]);
        $withNote = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current(['source' => 'leave', 'note' => '残す']), [$cancelled]);
        $withPunch = $this->rebuilder->rebuild(self::DAY, [$this->calculated(5)], $this->current(['source' => 'leave', 'hasPunch' => true]), [$cancelled]);

        $this->assertFalse($withActiveLeave->deletedAdded);
        $this->assertFalse($withActual->deletedAdded);
        $this->assertFalse($notLeaveSource->deletedAdded);
        $this->assertFalse($withNote->deletedAdded);
        $this->assertFalse($withPunch->deletedAdded);
    }

    public function test_deleted_is_not_added_twice(): void
    {
        $release = $this->row(8, 'special_leave.request_cancelled', ['cancelledByUserId' => 'u2']);

        $result = $this->rebuilder->rebuild(self::DAY, [
            $this->row(1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'leave', 'workType' => null]),
            $this->row(7, 'attendance_day.deleted', ['userId' => 'u1', 'workDate' => '2026-08-10', 'reason' => 'x', 'deletedByUserId' => 'u2', 'punchLogAction' => 'leave_punches']),
        ], $this->current(['source' => 'leave']), [
            ['userId' => 'u1', 'finalState' => 'cancelled', 'lastRelease' => ['row' => $release, 'operatorUserId' => 'u2']],
        ]);

        $this->assertFalse($result->deletedAdded);
    }

    public function test_identity_prefers_the_created_event_then_the_read_model_then_other_events(): void
    {
        $this->assertSame(
            ['userId' => 'u1', 'workDate' => '2026-08-10'],
            AttendanceRebuilder::identity([$this->row(1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10 00:00:00'])], $this->current(['userId' => 'u9'])),
        );
        $this->assertSame(
            ['userId' => 'u9', 'workDate' => '2026-08-10'],
            AttendanceRebuilder::identity([$this->calculated(2)], $this->current(['userId' => 'u9'])),
        );
        $this->assertSame(
            ['userId' => 'u1', 'workDate' => '2026-08-10'],
            AttendanceRebuilder::identity([$this->calculated(2)], null),
        );
    }

    public function test_leave_work_type_detection(): void
    {
        $this->assertTrue(AttendanceRebuilder::isLeaveWorkType('paid_leave_full'));
        $this->assertTrue(AttendanceRebuilder::isLeaveWorkType('special_leave_hourly'));
        $this->assertTrue(AttendanceRebuilder::isLeaveWorkType('compensatory_leave_pm_half'));
        $this->assertFalse(AttendanceRebuilder::isLeaveWorkType('office'));
        $this->assertFalse(AttendanceRebuilder::isLeaveWorkType(null));
    }

    private function calculated(int $id): StoredEventRow
    {
        return $this->row($id, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 0], 'userId' => 'u1', 'workDate' => '2026-08-10']);
    }

    /** @param  array<string, mixed>  $payload */
    private function row(int $id, string $class, array $payload): StoredEventRow
    {
        return new StoredEventRow($id, self::DAY, $id, $class, $payload, ['aggregate-root-version' => $id], '2026-08-01 10:00:00');
    }

    /** @param  array<string, mixed>  $override */
    private function current(array $override = []): AttendanceDayCurrent
    {
        $v = $override + [
            'userId' => 'u1',
            'workDate' => '2026-08-10',
            'calendarEntryId' => null,
            'status' => 'not_started',
            'source' => 'manual',
            'utcOffsetMinutes' => 540,
            'actualStartAt' => null,
            'actualEndAt' => null,
            'workType' => null,
            'workLocationType' => null,
            'dayClassification' => null,
            'note' => null,
            'breaks' => [],
            'leaveSegments' => [],
            'hasPunch' => false,
        ];

        return new AttendanceDayCurrent(
            id: self::DAY,
            userId: $v['userId'],
            workDate: $v['workDate'],
            calendarEntryId: $v['calendarEntryId'],
            status: $v['status'],
            source: $v['source'],
            utcOffsetMinutes: $v['utcOffsetMinutes'],
            actualStartAt: $v['actualStartAt'],
            actualEndAt: $v['actualEndAt'],
            workType: $v['workType'],
            workLocationType: $v['workLocationType'],
            dayClassification: $v['dayClassification'],
            note: $v['note'],
            breaks: $v['breaks'],
            leaveSegments: $v['leaveSegments'],
            hasPunch: $v['hasPunch'],
        );
    }

    /**
     * @param  list<RebuiltEvent>  $events
     * @return list<RebuiltEvent>
     */
    private function ordered(array $events): array
    {
        usort($events, fn ($a, $b) => [$a->anchorId, $a->subOrder] <=> [$b->anchorId, $b->subOrder]);

        return $events;
    }
}
