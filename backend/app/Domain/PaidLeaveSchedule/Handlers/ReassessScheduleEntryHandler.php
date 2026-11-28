<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ReassessScheduleEntry;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Domain\PaidLeaveSchedule\Support\PaidLeaveScheduleAssessmentPlanner;
use App\Models\PaidLeaveScheduleEntry;
use Illuminate\Support\Str;

/**
 * 付与予定の行の再判定。組の相手(同じ付与予定日・継続勤務月数が12か月違う行)の判定と、判定の計画
 * (`PaidLeaveScheduleAssessmentPlanner`)をここで行い、行と相手(あれば)に判定を記録する。
 * 片方が失敗したら両方記録しない(CommandBusの1トランザクション)。
 *
 * @implements CommandHandler<ReassessScheduleEntry>
 */
class ReassessScheduleEntryHandler implements CommandHandler
{
    public function __construct(private readonly PaidLeaveScheduleAssessmentPlanner $planner) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ReassessScheduleEntry);

        $scheduleEntry = PaidLeaveScheduleEntry::query()->with('user')->findOrFail($command->scheduleEntryId);
        $user = $scheduleEntry->user;

        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($user->id))
            ->forUser($user->id);
        $partnerId = $aggregate->pairedEntryId($scheduleEntry->id);

        $targets = collect([$scheduleEntry]);
        if ($partnerId !== null) {
            $partner = PaidLeaveScheduleEntry::query()->find($partnerId);
            if ($partner !== null) {
                $targets->push($partner);
            }
        }

        foreach ($targets as $target) {
            $plan = $this->planner->plan($target, $user);

            PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($user->id))
                ->forUser($user->id)
                ->runAttendanceRateAssessment(
                    scheduleEntryId: $target->id,
                    assessmentId: (string) Str::uuid(),
                    periodStart: $plan->periodStart,
                    periodEnd: $plan->periodEnd,
                    denominatorDays: $plan->denominatorDays,
                    attendanceDays: $plan->attendanceDays,
                    excludedDays: $plan->excludedDays,
                    attendanceRate: $plan->attendanceRate,
                    policyVersion: AttendanceRateAssessor::POLICY_VERSION,
                    automaticResult: $plan->automaticResult,
                    deemedAttendanceDays: $plan->deemedAttendanceDays,
                )
                ->persist();
        }

        return null;
    }
}
