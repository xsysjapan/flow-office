<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Commands\ConfirmCompensatoryLeaveGrantsForMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * 月次勤怠の提出を受けて、対象月に属するdraft状態の代休Grantを全て確定する
 * (ConfirmCompensatoryLeaveGrantsOnAttendanceMonthSubmittedReactorから発行される)。
 *
 * @implements CommandHandler<ConfirmCompensatoryLeaveGrantsForMonth>
 */
class ConfirmCompensatoryLeaveGrantsForMonthHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ConfirmCompensatoryLeaveGrantsForMonth);

        $validDays = SystemSetting::current()->compensatory_leave_valid_days;
        $expiresOn = $validDays !== null
            ? Carbon::parse($command->submittedAt)->addDays($validDays)->toDateString()
            : null;

        $grants = CompensatoryLeaveGrant::query()
            ->where('user_id', $command->userId)
            ->where('status', CompensatoryLeaveGrantStatus::DRAFT)
            ->where('work_date', 'like', "{$command->yearMonth}%")
            ->get();

        foreach ($grants as $grant) {
            CompensatoryLeaveGrantAggregate::retrieve($grant->id)
                ->confirm(confirmedAt: $command->submittedAt, expiresOn: $expiresOn)
                ->persist();
        }

        return null;
    }
}
