<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Domain\PaidLeaveSchedule\Support\PaidLeaveScheduleAssessmentPlanner;
use App\Models\AdminCommandRun;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * 付与予定日が来た(今日以前の)「予定」(Scheduled)の付与予定の出勤率を判定する(`paid-leave:assess-schedules`)。
 * 「今日」は`system_settings.default_timezone`の日付(`BusinessDate`)。判定の内容は
 * `PaidLeaveScheduleAssessmentPlanner`が決め、社員ごとに`RunAttendanceRateAssessment`を発行する
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6)。
 *
 * - 1社員の失敗(その社員の判定は全て取り消す)で全体は止めず、失敗した社員と理由を出力に並べる。1件でも失敗があれば終了コード1。
 * - 実行は、キュー(60秒で打ち切られる)を使わず、スケジューラーから直接動かす(`routes/console.php`)。
 *   - `--trigger=schedule`(定期): 記録(`admin_command_runs`)を作って実行する。実行中・待機中の記録があれば何もしない。
 *   - `--pending`(手動): 画面の操作で作られた待機中(`queued`)の記録を1件取り出して実行する。
 * - 記録の`running`→`succeeded`/`failed`の更新と、集計(`summary`)の記録は、このコマンド自身が行う。
 *   一部失敗は`status=failed`、`error_message`に「N件の判定に失敗しました」。
 * - 運用コマンド画面からは実行させない(`#[AdminExecutable]`を付けない。経路を1つにするため)。
 */
class AssessPaidLeaveSchedulesCommand extends Command
{
    protected $signature = 'paid-leave:assess-schedules
        {--trigger=schedule : 起動の種別(schedule|manual)。記録に残す}
        {--pending : 待っている手動の記録を1件取り出して実行する}';

    protected $description = '付与予定日が来た「予定」の有給付与予定の出勤率を判定する';

    public function handle(
        CommandBus $commandBus,
        PaidLeaveScheduleAssessmentPlanner $planner,
        ScheduleAssessmentRunService $runService,
    ): int {
        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, [ScheduleAssessmentRunService::TRIGGER_SCHEDULE, ScheduleAssessmentRunService::TRIGGER_MANUAL], true)) {
            $this->error('--triggerは schedule または manual を指定してください。');

            return self::FAILURE;
        }

        $run = $this->option('pending')
            ? $runService->claimPendingRun()
            : $runService->startRun($trigger);

        if ($run === null) {
            $this->info($this->option('pending')
                ? '実行できる待機中の記録がありません(または実行中です)。'
                : '実行中または待機中の判定があるため、実行しませんでした。');

            return self::SUCCESS;
        }

        try {
            $summary = $this->assessAll($commandBus, $planner);
        } catch (Throwable $e) {
            report($e);
            $this->error('判定を実行できませんでした: '.$e->getMessage());
            $run->update([
                'status' => ScheduleAssessmentRunService::STATUS_FAILED,
                'finished_at' => now(),
                'exit_code' => self::FAILURE,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return self::FAILURE;
        }

        $this->recordResult($run, $summary);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{targets: int, assessed: int, eligible: int, not_eligible: int, needs_review: int, failed: int, failures: array<int, string>}
     */
    private function assessAll(CommandBus $commandBus, PaidLeaveScheduleAssessmentPlanner $planner): array
    {
        $today = BusinessDate::today();

        $entries = PaidLeaveScheduleEntry::query()
            ->where('status', PaidLeaveScheduleAggregate::STATUS_SCHEDULED)
            ->whereDate('scheduled_on', '<=', $today->toDateString())
            ->orderBy('scheduled_on')
            ->orderBy('nominal_service_months')
            ->get()
            ->groupBy('user_id');

        $summary = ['targets' => 0, 'assessed' => 0, 'eligible' => 0, 'not_eligible' => 0, 'needs_review' => 0, 'failed' => 0, 'failures' => []];

        foreach ($entries as $userId => $userEntries) {
            $summary['targets'] += $userEntries->count();

            try {
                $results = DB::transaction(function () use ($userId, $userEntries, $commandBus, $planner): array {
                    $user = User::query()->find($userId);
                    if ($user === null) {
                        throw new \RuntimeException('社員が見つかりません。');
                    }

                    $results = [];
                    foreach ($userEntries as $entry) {
                        $plan = $planner->plan($entry, $user);

                        $commandBus->dispatch(new RunAttendanceRateAssessment(
                            userId: $user->id,
                            scheduleEntryId: $entry->id,
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
                        ));

                        $results[] = $plan->automaticResult;
                    }

                    return $results;
                });

                foreach ($results as $result) {
                    $summary['assessed']++;
                    match ($result) {
                        PaidLeaveScheduleAggregate::STATUS_ELIGIBLE => $summary['eligible']++,
                        PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE => $summary['not_eligible']++,
                        default => $summary['needs_review']++,
                    };
                }
            } catch (Throwable $e) {
                report($e);
                $summary['failed'] += $userEntries->count();
                $summary['failures'][] = "社員 {$userId}: {$e->getMessage()}";
            }
        }

        return $summary;
    }

    /**
     * @param  array{targets: int, assessed: int, eligible: int, not_eligible: int, needs_review: int, failed: int, failures: array<int, string>}  $result
     */
    private function recordResult(AdminCommandRun $run, array $result): void
    {
        $failures = $result['failures'];
        unset($result['failures']);

        $this->info("判定しました: 対象{$result['targets']}件 / 判定{$result['assessed']}件(付与対象{$result['eligible']}件・対象外{$result['not_eligible']}件・要確認{$result['needs_review']}件) / 失敗{$result['failed']}件");

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $run->update([
            'status' => $result['failed'] > 0 ? ScheduleAssessmentRunService::STATUS_FAILED : ScheduleAssessmentRunService::STATUS_SUCCEEDED,
            'finished_at' => now(),
            'exit_code' => $result['failed'] > 0 ? self::FAILURE : self::SUCCESS,
            'summary' => $result,
            'error_message' => $result['failed'] > 0 ? "{$result['failed']}件の判定に失敗しました" : null,
            'output' => mb_substr(implode("\n", [
                "対象{$result['targets']}件 / 判定{$result['assessed']}件 / 失敗{$result['failed']}件",
                ...$failures,
            ]), 0, 100000),
        ]);
    }
}
