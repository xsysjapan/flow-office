<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * attendance_month集約。主キー(attendance_months.id)はコマンド側/Handlerが決めたUUIDで、
 * 行の新規作成自体(初回提出時)もAttendanceMonthProjectorに委ねられる。ステータス遷移の
 * 可否判定はHandlerがEloquent Projectionの現在値を読んで行う(他ドメインと同じ理由)。
 */
class AttendanceMonthAggregate extends AggregateRoot
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function submit(string $userId, string $yearMonth, string $approverUserId, array $snapshot): self
    {
        $this->recordThat(new AttendanceMonthSubmitted(
            userId: $userId,
            yearMonth: $yearMonth,
            approverUserId: $approverUserId,
            snapshot: $snapshot,
        ));

        return $this;
    }

    public function approve(string $approvedByUserId): self
    {
        $this->recordThat(new AttendanceMonthApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnToApplicant(string $returnedByUserId, string $comment): self
    {
        $this->recordThat(new AttendanceMonthReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function close(string $closedByUserId): self
    {
        $this->recordThat(new AttendanceMonthClosed(closedByUserId: $closedByUserId));

        return $this;
    }
}
