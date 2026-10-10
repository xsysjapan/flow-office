<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\ConfirmCompensatoryLeaveGrantsForPeriod;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\SystemSetting;

/**
 * 月次勤怠の提出を受けて、対象期間に休日出勤日がある下書き付与を全て確定する
 * (月次提出からのReactorが発行する。判定は日付範囲。確定の判定は口座集約が行う)。
 *
 * @implements CommandHandler<ConfirmCompensatoryLeaveGrantsForPeriod>
 */
class ConfirmCompensatoryLeaveGrantsForPeriodHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ConfirmCompensatoryLeaveGrantsForPeriod);

        CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId)
            ->confirmGrantsForPeriod(
                periodStart: $command->periodStart,
                periodEnd: $command->periodEnd,
                confirmedAt: $command->submittedAt,
                validDays: SystemSetting::current()->compensatory_leave_valid_days,
            )
            ->persist();

        return null;
    }
}
