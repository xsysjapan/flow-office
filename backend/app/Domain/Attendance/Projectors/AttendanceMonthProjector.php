<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attendance_month.*イベントからattendance_monthsを作成・更新する。
 */
class AttendanceMonthProjector extends Projector
{
    public function onAttendanceMonthSubmitted(AttendanceMonthSubmitted $event): void
    {
        AttendanceMonth::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'year_month' => $event->yearMonth,
                'status' => AttendanceMonthStatus::SUBMITTED,
                'approver_user_id' => $event->approverUserId,
                'submitted_at' => $event->createdAt(),
                'snapshot_json' => $event->snapshot,
                // 差戻し後の再提出では、前回の差戻し理由を画面に残さない。
                'return_comment' => null,
            ],
        );
    }

    public function onAttendanceMonthApproved(AttendanceMonthApproved $event): void
    {
        AttendanceMonth::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => AttendanceMonthStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onAttendanceMonthReturned(AttendanceMonthReturned $event): void
    {
        AttendanceMonth::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => AttendanceMonthStatus::RETURNED,
            'returned_at' => $event->createdAt(),
            'return_comment' => $event->comment,
        ]);
    }

    public function onAttendanceMonthClosed(AttendanceMonthClosed $event): void
    {
        AttendanceMonth::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => AttendanceMonthStatus::CLOSED,
            'closed_at' => $event->createdAt(),
        ]);
    }
}
