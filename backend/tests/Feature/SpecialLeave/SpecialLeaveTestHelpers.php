<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeaveAccount\Commands\RegisterSpecialLeaveGrant;
use App\Models\AttendanceDayLeave;
use App\Models\SpecialLeaveGrant;
use Illuminate\Support\Str;

/**
 * 特別休暇のテストで共通に使う操作(付与・休暇ビューの確認)。
 */
trait SpecialLeaveTestHelpers
{
    /**
     * 特別休暇を付与する(付与の入口。利用者単位の特別休暇口座の集約へ記録し、付与の行は口座のProjectorが作る)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function grantSpecialLeave(array $attributes): SpecialLeaveGrant
    {
        $grantId = (string) Str::uuid();

        app(CommandBus::class)->dispatch(new RegisterSpecialLeaveGrant(
            userId: (string) $attributes['user_id'],
            grantId: $grantId,
            specialLeaveTypeId: (int) $attributes['special_leave_type_id'],
            grantedOn: (string) $attributes['granted_on'],
            expiresOn: $attributes['expires_on'] ?? null,
            grantedDays: (float) $attributes['granted_days'],
            grantReason: $attributes['grant_reason'] ?? null,
        ));

        return SpecialLeaveGrant::query()->findOrFail($grantId);
    }

    /**
     * 対象日の特別休暇の休暇ビューの行(勤怠が休暇申請のイベントから作る。無ければnull)。
     * 休暇は勤怠日の作業内容・statusではなく、この休暇ビューで確認する(論点1・9・10)。
     */
    /**
     * 対象日に有効な特別休暇(申請中・承認済み)だけを返す。差戻し・取消の行は状態付きで残るため、
     * 「休暇が無い」の確認はこちらを使う(休暇ビューは差戻し・取消の行を残す。WP5a決定)。
     */
    private function activeSpecialLeaveOn(string $userId, string $date): ?AttendanceDayLeave
    {
        return AttendanceDayLeave::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $date)
            ->where('leave_kind', AttendanceDayLeave::KIND_SPECIAL)
            ->whereIn('request_status', [AttendanceDayLeave::STATUS_SUBMITTED, AttendanceDayLeave::STATUS_APPROVED])
            ->first();
    }

    private function specialLeaveOn(string $userId, string $date): ?AttendanceDayLeave
    {
        return AttendanceDayLeave::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $date)
            ->where('leave_kind', AttendanceDayLeave::KIND_SPECIAL)
            ->first();
    }
}
