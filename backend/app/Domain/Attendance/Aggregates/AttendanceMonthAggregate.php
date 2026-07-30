<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\Attendance\Events\AttendanceMonthLocked;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthShared;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\Attendance\Events\AttendanceMonthUnlocked;
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
    public function submit(
        string $userId,
        string $yearMonth,
        string $approverUserId,
        array $snapshot,
        string $periodStartDate,
        string $periodEndDate,
    ): self {
        $this->recordThat(new AttendanceMonthSubmitted(
            userId: $userId,
            yearMonth: $yearMonth,
            approverUserId: $approverUserId,
            snapshot: $snapshot,
        ));

        // 提出した月次勤怠(対象月の日次勤怠一式)を編集不可にし、承認者へ開示する
        // (ルートCLAUDE.md「絶対に外してはいけない設計原則」1・原則11の周辺: 提出時ロック・共有)。
        $this->recordThat(new AttendanceMonthLocked(
            userId: $userId,
            periodStartDate: $periodStartDate,
            periodEndDate: $periodEndDate,
            lockedByUserId: $userId,
        ));
        $this->recordThat(new AttendanceMonthShared(
            sharedWithUserId: $approverUserId,
            sharedByUserId: $userId,
        ));

        return $this;
    }

    public function approve(string $approvedByUserId): self
    {
        $this->recordThat(new AttendanceMonthApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnToApplicant(
        string $userId,
        string $returnedByUserId,
        string $comment,
        string $periodStartDate,
        string $periodEndDate,
    ): self {
        $this->recordThat(new AttendanceMonthReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        // 差戻し時に提出時のロックを解除し、対象月の日次勤怠を再編集できるようにする。
        $this->recordThat(new AttendanceMonthUnlocked(
            userId: $userId,
            periodStartDate: $periodStartDate,
            periodEndDate: $periodEndDate,
            unlockedByUserId: $returnedByUserId,
        ));

        return $this;
    }

    public function close(string $closedByUserId): self
    {
        $this->recordThat(new AttendanceMonthClosed(closedByUserId: $closedByUserId));

        return $this;
    }
}
