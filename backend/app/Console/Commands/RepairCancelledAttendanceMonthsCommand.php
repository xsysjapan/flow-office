<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\CancelSubmittedAttendanceMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Console\Command;

/**
 * データ修復用(1回限りの手動実行を想定、cron登録はしない): 申請者自身がworkflow_requestを
 * 取り消した際にattendance_monthを未提出へ戻すAttendanceMonthCancelOnWorkflowRequestCancelled
 * Reactorが無かった期間に取り消しが行われ、提出済み/差戻し済みのまま取り残された月次勤怠を
 * 未提出へ戻す。対象: 月次勤怠がsubmitted/returnedのまま、対応する最新のworkflow_requestが
 * cancelledになっている行。
 */
class RepairCancelledAttendanceMonthsCommand extends Command
{
    protected $signature = 'attendance:repair-cancelled-months {--dry-run : 実際には修復せず対象件数のみ表示する}';

    protected $description = '取り消し済みのworkflow_requestに取り残された提出済み/差戻し済みの月次勤怠を未提出へ戻す';

    public function handle(CommandBus $commandBus): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $months = AttendanceMonth::query()
            ->whereIn('status', [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::RETURNED])
            ->get();

        $repaired = 0;

        foreach ($months as $month) {
            $latestWorkflowRequest = WorkflowRequest::query()
                ->where('subject_type', 'attendance_month')
                ->where('subject_id', $month->id)
                ->latest('created_at')
                ->first();

            if ($latestWorkflowRequest === null || $latestWorkflowRequest->status !== WorkflowRequestStatus::CANCELLED) {
                continue;
            }

            $this->line("{$month->user_id} / {$month->year_month} (attendance_months.id={$month->id}) を未提出へ戻します。");

            if (! $dryRun) {
                // 取り消せるのは申請者本人のみ(CancelWorkflowRequestHandler)なので、
                // 取り消した本人=applicant_user_idで確定する。
                $commandBus->dispatch(new CancelSubmittedAttendanceMonth(
                    $month->id,
                    $latestWorkflowRequest->applicant_user_id,
                ));
            }

            $repaired++;
        }

        $this->info($dryRun ? "{$repaired} 件が対象です(--dry-runのため未修復)。" : "{$repaired} 件を未提出へ戻しました。");

        return self::SUCCESS;
    }
}
