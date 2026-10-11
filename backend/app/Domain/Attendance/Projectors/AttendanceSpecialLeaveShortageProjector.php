<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageCancelled;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageConfirmed;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageDesignated;
use App\Models\AttendanceSpecialLeaveShortage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 特別休暇口座のイベントから、勤怠側の特別休暇の残数不足のビュー(attendance_special_leave_shortages)を作る。
 * 月次APIの特別休暇の残数不足警告が、特別休暇の消化記録テーブルを直接読まずにこのビューを読むために置く。
 *
 * - usage_designated: 消化記録の行を作る(未充当は0。再処理しても同じ行になるようupsert)
 * - usage_confirmed: 承認で確定した未充当日数を記録する(代入。加減算しない)
 * - usage_cancelled: 差戻し・取消で行を削除する
 * 行の存在を前提とする更新は、行が無ければ何もしない。
 */
class AttendanceSpecialLeaveShortageProjector extends Projector
{
    public function onSpecialLeaveAccountUsageDesignated(SpecialLeaveAccountUsageDesignated $event): void
    {
        AttendanceSpecialLeaveShortage::query()->updateOrCreate(
            ['usage_id' => $event->usageId],
            [
                'user_id' => $event->userId,
                'work_date' => $event->usedOn,
                'special_leave_request_id' => $event->requestId,
                'special_leave_type_id' => $event->specialLeaveTypeId,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'unallocated_days' => 0,
            ],
        );
    }

    public function onSpecialLeaveAccountUsageConfirmed(SpecialLeaveAccountUsageConfirmed $event): void
    {
        AttendanceSpecialLeaveShortage::query()
            ->whereKey($event->usageId)
            ->update(['unallocated_days' => $event->unallocatedDays]);
    }

    public function onSpecialLeaveAccountUsageCancelled(SpecialLeaveAccountUsageCancelled $event): void
    {
        AttendanceSpecialLeaveShortage::query()->whereKey($event->usageId)->delete();
    }
}
