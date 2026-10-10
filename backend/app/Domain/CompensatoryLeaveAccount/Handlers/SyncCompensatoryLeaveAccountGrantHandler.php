<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\SyncCompensatoryLeaveAccountGrant;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * 休日出勤の計算結果から代休の下書き付与を同期する(勤怠の計算イベントを受けるReactorから発行される)。
 * 判定と換算は口座集約(syncGrantFromHolidayWork)が行い、ここは設定値を渡すだけ。
 *
 * @implements CommandHandler<SyncCompensatoryLeaveAccountGrant>
 */
class SyncCompensatoryLeaveAccountGrantHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof SyncCompensatoryLeaveAccountGrant);

        $settings = SystemSetting::current();

        CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId)
            ->syncGrantFromHolidayWork(
                newGrantId: (string) Str::uuid(),
                sourceWorkDate: $command->workDate,
                enabled: (bool) $settings->compensatory_leave_enabled,
                isHolidayDay: $command->isHolidayDay,
                workMinutes: $command->workMinutes,
                unit: $settings->compensatory_leave_unit,
                halfDayThresholdMinutes: $settings->compensatory_leave_half_day_threshold_minutes,
            )
            ->persist();

        return null;
    }
}
