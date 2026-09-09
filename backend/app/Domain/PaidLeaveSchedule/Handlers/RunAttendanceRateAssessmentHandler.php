<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Models\PaidLeaveGrantRule;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 対象Scheduleエントリの出勤率Assessmentを実行し、結果をAggregateへ記録する
 * (依頼書§40「再判定」・§56相当)。算定期間は`scheduledOn`からその適用サイクル
 * (grant_cycle_months、既定12か月)を遡った期間とする。
 *
 * @implements CommandHandler<RunAttendanceRateAssessment>
 */
class RunAttendanceRateAssessmentHandler implements CommandHandler
{
    public function __construct(private readonly AttendanceRateAssessor $assessor) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RunAttendanceRateAssessment);

        $user = User::query()->findOrFail($command->userId);
        $aggregate = PaidLeaveScheduleAggregate::retrieve($command->userId);
        $entry = $aggregate->entry($command->entryId);

        if ($entry === null) {
            throw new DomainRuleException("Scheduleエントリ [{$command->entryId}] は存在しません。");
        }

        $cycleMonths = PaidLeaveGrantRule::query()->where('is_active', true)->value('grant_cycle_months') ?? 12;

        $periodEnd = Carbon::parse($entry['scheduledOn']);
        $periodStart = $periodEnd->copy()->subMonths($cycleMonths);

        $result = $this->assessor->assess($user, $periodStart, $periodEnd);

        $aggregate->recordAssessment(
            entryId: $command->entryId,
            periodStart: $result['periodStart'],
            periodEnd: $result['periodEnd'],
            denominatorDays: $result['denominatorDays'],
            attendanceDays: $result['attendanceDays'],
            excludedDays: $result['excludedDays'],
            attendanceRate: $result['attendanceRate'],
            policyVersion: $result['policyVersion'],
            automaticResult: $result['automaticResult'],
        );

        $aggregate->persist();

        return null;
    }
}
