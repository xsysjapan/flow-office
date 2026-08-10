<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\Attendance\Events\AttendanceMonthLocked;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthShared;
use App\Domain\Attendance\Events\AttendanceMonthSnapshotRecalculated;
use App\Domain\Attendance\Events\AttendanceMonthSubmissionCancelled;
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
        ?string $workflowRequestId = null,
    ): self {
        $this->recordThat(new AttendanceMonthSubmitted(
            userId: $userId,
            yearMonth: $yearMonth,
            approverUserId: $approverUserId,
            snapshot: $snapshot,
        ));

        // 提出した月次勤怠(対象月の日次勤怠一式)を編集不可にし、承認者へ開示する
        // (ルートCLAUDE.md「絶対に外してはいけない設計原則」1・原則11の周辺: 提出時ロック・共有)。
        $this->lock(userId: $userId, periodStartDate: $periodStartDate, periodEndDate: $periodEndDate, lockedByUserId: $userId, workflowRequestId: $workflowRequestId);
        $this->share(sharedWithUserId: $approverUserId, sharedByUserId: $userId);

        return $this;
    }

    /**
     * 対象月の日次勤怠一式を編集不可にする。通常はsubmit()内から呼ばれる。
     */
    public function lock(string $userId, string $periodStartDate, string $periodEndDate, string $lockedByUserId, ?string $workflowRequestId = null): self
    {
        $this->recordThat(new AttendanceMonthLocked(
            userId: $userId,
            periodStartDate: $periodStartDate,
            periodEndDate: $periodEndDate,
            lockedByUserId: $lockedByUserId,
            workflowRequestId: $workflowRequestId,
        ));

        return $this;
    }

    /**
     * 対象月の日次勤怠一式を承認者へ開示する。通常はsubmit()内から呼ばれる。
     */
    public function share(string $sharedWithUserId, string $sharedByUserId): self
    {
        $this->recordThat(new AttendanceMonthShared(
            sharedWithUserId: $sharedWithUserId,
            sharedByUserId: $sharedByUserId,
        ));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
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

    /**
     * 申請者自身が、提出済み・差戻し済みの月次勤怠申請(workflow_request)を取り消した際に、
     * 未提出へ戻す(承認者による差戻しと異なり、差戻し理由は残らない)。
     */
    public function cancelSubmission(
        string $userId,
        string $cancelledByUserId,
        string $periodStartDate,
        string $periodEndDate,
    ): self {
        $this->recordThat(new AttendanceMonthSubmissionCancelled(cancelledByUserId: $cancelledByUserId));

        $this->recordThat(new AttendanceMonthUnlocked(
            userId: $userId,
            periodStartDate: $periodStartDate,
            periodEndDate: $periodEndDate,
            unlockedByUserId: $cancelledByUserId,
        ));

        return $this;
    }

    public function close(string $closedByUserId): self
    {
        $this->recordThat(new AttendanceMonthClosed(closedByUserId: $closedByUserId));

        return $this;
    }

    /**
     * 提出済み・承認済み・締め済みの月次勤怠について、対象月の日次実績(ロック済みで変更されない)
     * から集計ロジックを再実行した結果でsnapshot_jsonを更新する
     * (RecalculateAttendanceMonthSnapshotHandler参照)。
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function recalculateSnapshot(array $snapshot): self
    {
        $this->recordThat(new AttendanceMonthSnapshotRecalculated(snapshot: $snapshot));

        return $this;
    }
}
