<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\GrantCompensatoryLeave;
use App\Models\CompensatoryHolidayWorkDay;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\SystemSetting;

/**
 * 管理者が休日出勤の対象日を指定して代休を手動付与する。勤怠日は読まず、代休口座の休日出勤のビュー
 * (計算イベントから作る)で休日出勤の実績を確認する(仕様確定事項I)。作成と同時に確定する。
 *
 * @implements CommandHandler<GrantCompensatoryLeave>
 */
class GrantCompensatoryLeaveHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof GrantCompensatoryLeave);

        $holidayWork = CompensatoryHolidayWorkDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->workDate)
            ->first();

        if ($holidayWork === null) {
            throw new DomainRuleException('指定日の勤怠実績が見つからないため、代休を付与できません。');
        }

        $settings = SystemSetting::current();

        CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId)
            ->grantManually(
                newGrantId: $command->grantId,
                sourceWorkDate: $command->workDate,
                isHolidayDay: $holidayWork->is_holiday_day,
                workMinutes: (int) $holidayWork->work_minutes,
                unit: $settings->compensatory_leave_unit,
                halfDayThresholdMinutes: $settings->compensatory_leave_half_day_threshold_minutes,
                expiresOn: $command->expiresOn,
                grantReason: $command->grantReason,
            )
            ->persist();

        return null;
    }
}
