<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\BackfillAttendanceMonthLockShare;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\AttendanceLock;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\EntityShare;
use Illuminate\Support\Carbon;

/**
 * AttendanceMonthLocked/AttendanceMonthSharedは提出時ロック・共有(ルートCLAUDE.md
 * 「絶対に外してはいけない設計原則」1・原則11の周辺)を実現するためにAttendanceMonthAggregate::
 * submit()へ後から追加されたイベントであり、それ以前に提出済み(submitted/approved/closed)
 * だった月次勤怠には記録されていない。そのため対象月がまだ編集ロックされておらず、
 * かつ承認者への開示(entity_shares)も行われていない状態のまま残っている可能性がある。
 * このHandlerは、現在もロック・共有が有効であるべき状態(submitted/approved/closed)の
 * 月次勤怠のうち、対応するAttendanceLock/EntityShareが欠けているものを見つけて
 * 事後的にLocked/Sharedイベントを記録する(1回限りのバックフィル用途。cron常駐は前提としない)。
 *
 * @implements CommandHandler<BackfillAttendanceMonthLockShare>
 */
class BackfillAttendanceMonthLockShareHandler implements CommandHandler
{
    /**
     * @return int バックフィル対象として処理した月次勤怠の件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof BackfillAttendanceMonthLockShare);

        $months = AttendanceMonth::query()
            ->whereIn('status', [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED])
            ->get();

        $backfilledCount = 0;

        foreach ($months as $month) {
            $periodStartDate = Carbon::parse("{$month->year_month}-01")->toDateString();
            $periodEndDate = Carbon::parse($periodStartDate)->endOfMonth()->toDateString();

            $hasActiveLock = AttendanceLock::query()
                ->where('scope_type', AttendanceLock::SCOPE_MONTH)
                ->where('user_id', $month->user_id)
                ->whereDate('period_start_date', $periodStartDate)
                ->whereDate('period_end_date', $periodEndDate)
                ->whereNull('unlocked_at')
                ->exists();

            $hasShare = $month->approver_user_id !== null && EntityShare::query()
                ->where('shareable_type', 'attendance_month')
                ->where('shareable_id', $month->id)
                ->where('shared_with_user_id', $month->approver_user_id)
                ->exists();

            if ($hasActiveLock && $hasShare) {
                continue;
            }

            $aggregate = AttendanceMonthAggregate::retrieve($month->id);

            if (! $hasActiveLock) {
                $aggregate->lock(
                    userId: $month->user_id,
                    periodStartDate: $periodStartDate,
                    periodEndDate: $periodEndDate,
                    lockedByUserId: $month->user_id,
                );
            }

            if (! $hasShare && $month->approver_user_id !== null) {
                $aggregate->share(sharedWithUserId: $month->approver_user_id, sharedByUserId: $month->user_id);
            }

            $aggregate->persist();
            $backfilledCount++;
        }

        return $backfilledCount;
    }
}
