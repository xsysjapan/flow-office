<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\Attendance\Events\AttendanceMonthLocked;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthShared;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\Attendance\Events\AttendanceMonthUnlocked;
use App\Models\AttendanceLock;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\EntityShare;
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

    /**
     * UC-A008: 提出時に対象月(period_start_date〜period_end_date)の日次勤怠を編集不可にする。
     * 同一の(user_id, scope_type, period_start_date, period_end_date, locked_at)の行が既に
     * 存在すれば更新するだけにすることで、projections:rebuildによるイベント再生時にも行を
     * 重複作成しない(locked_atはイベント記録時のcreatedAt()で、再生時も同じ値になる)。
     * date型カラムは日時文字列で保存されるため、日付部分の一致判定にはwhereDate()を使う。
     */
    public function onAttendanceMonthLocked(AttendanceMonthLocked $event): void
    {
        $existing = AttendanceLock::query()
            ->where('scope_type', AttendanceLock::SCOPE_MONTH)
            ->where('user_id', $event->userId)
            ->whereDate('period_start_date', $event->periodStartDate)
            ->whereDate('period_end_date', $event->periodEndDate)
            ->where('locked_at', $event->createdAt())
            ->first();

        $attributes = [
            'scope_type' => AttendanceLock::SCOPE_MONTH,
            'user_id' => $event->userId,
            'period_start_date' => $event->periodStartDate,
            'period_end_date' => $event->periodEndDate,
            'locked_at' => $event->createdAt(),
            'unlocked_at' => null,
            'workflow_request_id' => $event->workflowRequestId,
        ];

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            AttendanceLock::query()->create($attributes);
        }
    }

    /**
     * UC-A010: 差戻し時に提出時のロックを解除する。まだ解除されていない対象期間のロック行を
     * 探して更新するため、再生時に重複更新しても結果は変わらない(冪等)。
     */
    public function onAttendanceMonthUnlocked(AttendanceMonthUnlocked $event): void
    {
        AttendanceLock::query()
            ->where('scope_type', AttendanceLock::SCOPE_MONTH)
            ->where('user_id', $event->userId)
            ->whereDate('period_start_date', $event->periodStartDate)
            ->whereDate('period_end_date', $event->periodEndDate)
            ->whereNull('unlocked_at')
            ->update(['unlocked_at' => $event->createdAt()]);
    }

    /**
     * UC-A008: 提出時に対象月の日次勤怠一式を承認者へ開示したことをentity_sharesへ記録する。
     * entity_sharesは追記専用ログ(ルートCLAUDE.md「絶対に外してはいけない設計原則」)のため、
     * リプレイ時の重複作成を避けるためexistsチェックを行う。
     */
    public function onAttendanceMonthShared(AttendanceMonthShared $event): void
    {
        $alreadyShared = EntityShare::query()
            ->where('shareable_type', 'attendance_month')
            ->where('shareable_id', $event->aggregateRootUuid())
            ->where('shared_with_user_id', $event->sharedWithUserId)
            ->where('shared_at', $event->createdAt())
            ->exists();

        if ($alreadyShared) {
            return;
        }

        EntityShare::query()->create([
            'shareable_type' => 'attendance_month',
            'shareable_id' => $event->aggregateRootUuid(),
            'shared_with_user_id' => $event->sharedWithUserId,
            'shared_by_user_id' => $event->sharedByUserId,
            'shared_at' => $event->createdAt(),
        ]);
    }
}
