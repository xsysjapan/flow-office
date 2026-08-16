<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Commands\GrantCompensatoryLeave;
use App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\CompensatoryLeaveGrant;
use App\Models\DayClassification;
use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * 管理者が休日出勤の対象日(workDate)を指定して代休を手動付与する
 * (SyncCompensatoryLeaveGrantHandlerによる勤怠実績からの自動導出とは別の経路。
 * ルートCLAUDE.md「操作経路と業務ロジックを分離する」に基づき、付与日数の算出ルールは
 * CompensatoryLeaveGrantCalculatorを共有して重複させない)。
 *
 * @implements CommandHandler<GrantCompensatoryLeave>
 */
class GrantCompensatoryLeaveHandler implements CommandHandler
{
    public function handle(Command $command): CompensatoryLeaveGrant
    {
        assert($command instanceof GrantCompensatoryLeave);

        $day = AttendanceDay::query()
            ->with('calculation')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->workDate)
            ->first();

        if ($day === null) {
            throw new DomainRuleException('指定日の勤怠実績が見つからないため、代休を付与できません。');
        }

        $isHolidayWork = in_array($day->day_classification, [DayClassification::PRESCRIBED_HOLIDAY, DayClassification::LEGAL_HOLIDAY], true)
            && ($day->calculation?->work_minutes ?? 0) > 0;

        if (! $isHolidayWork) {
            throw new DomainRuleException('指定日は休日出勤の実績がないため、代休を付与できません。');
        }

        $settings = SystemSetting::current();
        [$grantedDays, $grantedMinutes] = CompensatoryLeaveGrantCalculator::resolveGrantedAmount(
            $settings,
            (int) $day->calculation->work_minutes,
        );

        $grantId = (string) Str::uuid();

        CompensatoryLeaveGrantAggregate::retrieve($grantId)
            ->grantManually(
                userId: $command->userId,
                workDate: $command->workDate,
                grantedDays: $grantedDays,
                grantedMinutes: $grantedMinutes,
                expiresOn: $command->expiresOn,
                grantReason: $command->grantReason,
            )
            ->persist();

        return CompensatoryLeaveGrant::query()->findOrFail($grantId);
    }
}
