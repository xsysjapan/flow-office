<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UC-P003: 有給を申請する。勤怠(実績)を先に作る/編集するという通常の業務フローに合わせ、
 * 申請した時点で対象日の勤怠(attendance_days.work_type)へ即座に反映する
 * (承認を待たない。承認はUC-P004として別途行われる「事後の確認・記録」に位置づけが変わり、
 * 実際の消化(grantの残数減算)のみを承認時に行う。ApprovePaidLeaveRequestHandler参照)。
 * 残数が不足していても申請(=勤怠への反映)自体は成立させる。残数は承認済み分のみで
 * 計測するため、申請中の分は別枠で可視化する(LeaveUsageQuery::usageBreakdownWithinPastYear)。
 *
 * Phase 5(cutover、docs/changesets/20260906-paid-leave-domain-redesign/spec.md)により、
 * 旧`App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate`は廃止した。
 * `paid_leave_requests`行はこのHandlerが直接Eloquentで作成するのではなく、
 * `App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage`を発行し、
 * `App\Domain\PaidLeaveAccount\Projectors\PaidLeaveRequestProjector`が
 * `PaidLeaveUsageDesignated`イベントから作成する(`event-sourcing:replay`で
 * 再生成可能であることを保つため)。workflow_requestの提出(旧`PaidLeaveRequestShared`→
 * `SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor`が担っていた処理)もこのHandlerが
 * 直接`SubmitWorkflowRequest`を発行する。
 *
 * hourly(時間単位)有給申請も、full/半休と同じ経路でUsageを作成する(Phase 4で
 * 一時的にスキップしていた判断をユーザー指示により撤回。spec.md「実装方針の変更」参照)。
 *
 * @implements CommandHandler<RequestPaidLeave>
 */
class RequestPaidLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
        private readonly CommandBus $commandBus,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof RequestPaidLeave);

        $targetDate = Carbon::parse($command->targetDate);
        $calendarEntry = $this->scheduledWorkingDayResolver->resolveSchedule($command->userId, $targetDate);
        $workStyle = $calendarEntry?->workStyle;

        if ($calendarEntry !== null) {
            if (! $calendarEntry->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため有給を申請できません。');
            }
        } else {
            // 通常勤務(シフト非対象)は運用上employee_calendar_entriesが事前展開されないことが
            // 多いため、勤務予定が無い日は「未展開」として扱い、その月に割り当てられた働き方
            // (無ければシステムのデフォルト働き方)から所定労働日かどうかを判定する
            // (ScheduledWorkingDayResolver参照)。
            $workStyle = $this->scheduledWorkingDayResolver->resolveWorkStyle($command->userId, $targetDate);

            if (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
                throw new DomainRuleException('勤務予定日ではないため有給を申請できません。');
            }
        }

        // 同日重複申請チェック(有給ドメインの外側=Workflow層の判定として、
        // Eloquent Projectionを直接クエリしてよい。docs/changesets/20260906-
        // paid-leave-domain-redesign/spec.md 論点1/2参照。PaidLeaveAccountAggregate自体は
        // これを判定しない)。
        $alreadyRequested = PaidLeaveRequest::query()
            ->where('user_id', $command->userId)
            ->whereDate('target_date', $command->targetDate)
            ->whereIn('status', [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED])
            ->exists();

        if ($alreadyRequested) {
            throw new DomainRuleException('この日は既に有給を申請済みです。');
        }

        $alreadyHasSpecialLeave = SpecialLeaveRequest::query()
            ->where('user_id', $command->userId)
            ->whereDate('target_date', $command->targetDate)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->exists();

        if ($alreadyHasSpecialLeave) {
            throw new DomainRuleException('この日は既に特別休暇を申請済みです。');
        }

        $requestedDays = $this->resolveRequestedDays($command, $workStyle);

        // 対象日の勤怠が編集可能(月次未確定)であることを、勤怠反映の前に確認する
        // (ここで弾かれれば申請自体を作らない。修正が必要な場合は修正申請ワークフローを使う)。
        $existingDay = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();
        $this->guard->assertMutable($existingDay, $command->userId, $command->targetDate);

        // 承認を待たず、申請した時点で対象日の勤怠へ即座に反映する(このファイル冒頭のコメント参照)。
        $day = $this->reflectOnAttendanceDay($command, $existingDay);

        $requestId = $command->requestId ?? (string) Str::uuid();

        $this->commandBus->dispatch(new DesignatePaidLeaveUsage(
            userId: $command->userId,
            workflowRequestId: $command->workflowRequestId,
            attendanceDayId: $day->id,
            usedOn: $command->targetDate,
            usedDays: $requestedDays,
            usageType: $command->leaveType,
            paidLeaveRequestId: $requestId,
            approverUserId: $command->approverUserId,
            reason: $command->reason,
            requestGroupId: $command->requestGroupId,
            hours: $command->hours,
        ));

        // workflow_requestが指定されている場合、下書きのworkflow_requestを提出済みにする
        // (旧`PaidLeaveRequestShared`→`SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor`の
        // 置き換え。ReactorからのRequestPaidLeaveのみこのIDを持つ)。
        if ($command->workflowRequestId !== null) {
            $workflowRequest = WorkflowRequest::query()->find($command->workflowRequestId);

            if ($workflowRequest !== null && $workflowRequest->status === WorkflowRequestStatus::DRAFT) {
                $this->commandBus->dispatch(new SubmitWorkflowRequest(
                    workflowRequestId: $workflowRequest->id,
                    submittedByUserId: $workflowRequest->applicant_user_id,
                    approverUserId: $workflowRequest->approver_user_id,
                ));
            }
        }

        // 通知はSubmitWorkflowRequestHandlerが一括して送るため、ここでは送らない
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)

        $calculation = $this->calculator->calculate(
            $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
        );
        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return PaidLeaveRequest::query()->findOrFail($requestId);
    }

    /**
     * 対象日の勤怠(attendance_days)へ有給区分を反映する。
     */
    private function reflectOnAttendanceDay(RequestPaidLeave $command, ?AttendanceDay $existingDay): AttendanceDay
    {
        $day = $existingDay;

        if ($day === null) {
            $calendarEntry = EmployeeCalendarEntry::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $command->targetDate)
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $command->userId,
                'work_date' => $command->targetDate,
                'calendar_entry_id' => $calendarEntry?->id,
                'status' => AttendanceDayStatus::NOT_STARTED,
                'source' => AttendanceDaySource::MANUAL,
            ]);
        }

        $day->work_type = PaidLeaveType::toAttendanceWorkType($command->leaveType);
        if ($command->leaveType === PaidLeaveType::FULL) {
            // 全休は出退勤操作が発生しないため、締め忘れとして警告されないよう完了扱いにする。
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        return $day;
    }

    private function resolveRequestedDays(RequestPaidLeave $command, ?WorkStyle $workStyle): float
    {
        if ($command->leaveType === PaidLeaveType::FULL) {
            return 1.0;
        }

        if (in_array($command->leaveType, [PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            return 0.5;
        }

        if ($command->leaveType === PaidLeaveType::HOURLY) {
            if ($command->hours === null || $command->hours <= 0) {
                throw new DomainRuleException('時間休の場合は取得時間を指定してください。');
            }

            if ($workStyle === null) {
                throw new DomainRuleException('働き方が特定できないため時間休を申請できません。');
            }

            // マスタ値をそのまま使い、ハードコードしたフォールバックは持たない。
            $prescribedDailyMinutes = $workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            if ($requestedDays <= 0 || $requestedDays >= 1) {
                throw new DomainRuleException('時間休として妥当な取得時間を指定してください。');
            }

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
