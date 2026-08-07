<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
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
 * @implements CommandHandler<RequestPaidLeave>
 */
class RequestPaidLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof RequestPaidLeave);

        $shiftAssignment = EmployeeShiftAssignment::query()
            ->with('workStyle')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        $targetDate = Carbon::parse($command->targetDate);
        $workStyle = $shiftAssignment?->workStyle;

        if ($shiftAssignment !== null) {
            if (! $shiftAssignment->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため有給を申請できません。');
            }
        } else {
            // 通常勤務(シフト非対象)は運用上employee_shift_assignmentsが事前展開されないことが
            // 多いため、勤務予定が無い日は「未展開」として扱い、その月に割り当てられた働き方
            // (無ければシステムのデフォルト働き方)から所定労働日かどうかを判定する
            // (ScheduledWorkingDayResolver参照)。
            $workStyle = $this->scheduledWorkingDayResolver->resolveWorkStyle($command->userId, $targetDate);

            if (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
                throw new DomainRuleException('勤務予定日ではないため有給を申請できません。');
            }
        }

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
        $usedMinutes = $command->hours !== null ? (int) round($command->hours * 60) : null;

        $aggregate = PaidLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
                requestGroupId: $command->requestGroupId,
            )
            // paid_leave_usagesへgrant未確定の行を作る(承認時にどのgrantから消化するかが
            // 決まった時点で確定済みへ更新される。PaidLeaveUsageProjector参照)。勤怠側は
            // この行の存在だけで休暇設定の有無を判定でき、paid_leave_requestsを見に行く
            // 必要が無くなる(ルートCLAUDE.md「操作経路と業務ロジックを分離する」と同じ考え方で、
            // ドメインをまたいだ参照を避ける)。
            ->designateUsage(
                userId: $command->userId,
                attendanceDayId: $day->id,
                usedOn: $command->targetDate,
                usedDays: $requestedDays,
                usedMinutes: $usedMinutes,
                usageType: $command->leaveType,
            );

        // workflow_requestが指定されている場合、PaidLeaveRequestSharedイベントを発行して
        // workflow_requestの提出を促す(ReactorからのRequestPaidLeaveのみこのIDを持つ)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        // 通知はSubmitWorkflowRequestHandlerが一括して送るため、ここでは送らない
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)

        $calculation = $this->calculator->calculate(
            $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'),
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
            $shiftAssignment = EmployeeShiftAssignment::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $command->targetDate)
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $command->userId,
                'work_date' => $command->targetDate,
                'shift_assignment_id' => $shiftAssignment?->id,
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
