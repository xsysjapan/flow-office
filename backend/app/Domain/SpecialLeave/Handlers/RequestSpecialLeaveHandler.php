<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\SpecialLeave\SpecialLeaveWorkType;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveType;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 特別休暇を申請する。有給休暇(RequestPaidLeaveHandler)と同じ考え方に揃え、勤怠(実績)を
 * 先に作る/編集するという通常の業務フローに合わせ、申請した時点で対象日の勤怠
 * (attendance_days.work_type)へ即座に反映する(承認を待たない。承認は
 * ApproveSpecialLeaveRequestHandlerとして別途行われる「事後の確認・記録」に位置づけが変わり、
 * 実際の消化(special_leave_grantの残数減算)のみを承認時に行う)。残数が不足していても
 * 申請(=勤怠への反映)自体は成立させる。残数・消化は特別休暇種別(special_leave_type_id)
 * ごとにスコープする。有給とはビジネスロジックを分けて実装し、法定の要件を持つ有給側の
 * ルールには一切影響しない。
 *
 * @implements CommandHandler<RequestSpecialLeave>
 */
class RequestSpecialLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof RequestSpecialLeave);

        $specialLeaveType = SpecialLeaveType::query()->findOrFail($command->specialLeaveTypeId);
        if (! $specialLeaveType->is_active) {
            throw new DomainRuleException('無効な特別休暇種別です。');
        }

        $targetDate = Carbon::parse($command->targetDate);
        $calendarEntry = $this->scheduledWorkingDayResolver->resolveSchedule($command->userId, $targetDate);
        $workStyle = $calendarEntry?->workStyle;

        if ($calendarEntry !== null) {
            if (! $calendarEntry->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため特別休暇を申請できません。');
            }
        } else {
            // 通常勤務(シフト非対象)は運用上employee_calendar_entriesが事前展開されないことが
            // 多いため、勤務予定が無い日は「未展開」として扱い、その月に割り当てられた働き方
            // (無ければシステムのデフォルト働き方)から所定労働日かどうかを判定する
            // (ScheduledWorkingDayResolver参照)。
            $workStyle = $this->scheduledWorkingDayResolver->resolveWorkStyle($command->userId, $targetDate);

            if (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
                throw new DomainRuleException('勤務予定日ではないため特別休暇を申請できません。');
            }
        }

        if ($this->alreadyHasLeaveOnDate($command->userId, $command->targetDate)) {
            throw new DomainRuleException('この日は既に有給または特別休暇を申請済みです。');
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

        $aggregate = SpecialLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
                requestGroupId: $command->requestGroupId,
            )
            // special_leave_usagesへgrant未確定の行を作る(承認時にどのgrantから消化するかが
            // 決まった時点で確定済みへ更新される。SpecialLeaveUsageProjector参照)。勤怠側は
            // この行の存在だけで休暇設定の有無を判定でき、special_leave_requestsを見に行く
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

        // workflow_requestが指定されている場合、SpecialLeaveRequestSharedイベントを発行して
        // workflow_requestの提出を促す(ReactorからのRequestSpecialLeaveのみこのIDを持つ)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        // 通知はSubmitWorkflowRequestHandlerが一括して送るため、ここでは送らない
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)

        $calculation = $this->calculator->calculate(
            $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
        );
        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return SpecialLeaveRequest::query()->findOrFail($requestId);
    }

    /**
     * 対象日の勤怠(attendance_days)へ特別休暇区分を反映する。
     */
    private function reflectOnAttendanceDay(RequestSpecialLeave $command, ?AttendanceDay $existingDay): AttendanceDay
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

        $day->work_type = SpecialLeaveWorkType::toAttendanceWorkType($command->leaveType);
        if ($command->leaveType === PaidLeaveType::FULL) {
            // 全休は出退勤操作が発生しないため、締め忘れとして警告されないよう完了扱いにする。
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        return $day;
    }

    /**
     * 同じ日にactive(提出中・承認済み)な有給または特別休暇の申請が既にあるか。
     * attendance_days.work_typeは1日1件しか値を持てないため、どちらの休暇であっても
     * 二重申請を防ぐ必要がある。
     */
    private function alreadyHasLeaveOnDate(string $userId, string $targetDate): bool
    {
        $activeStatuses = [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED];

        $hasPaidLeave = PaidLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasPaidLeave) {
            return true;
        }

        return SpecialLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->exists();
    }

    private function resolveRequestedDays(RequestSpecialLeave $command, ?WorkStyle $workStyle): float
    {
        if ($command->leaveType === PaidLeaveType::FULL) {
            return 1.0;
        }

        if (in_array($command->leaveType, [PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            return 0.5;
        }

        if ($command->leaveType === PaidLeaveType::HOURLY) {
            if ($command->hours === null || $command->hours <= 0) {
                throw new DomainRuleException('時間単位の場合は取得時間を指定してください。');
            }

            if ($workStyle === null) {
                throw new DomainRuleException('働き方が特定できないため時間単位の特別休暇を申請できません。');
            }

            $prescribedDailyMinutes = $workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            if ($requestedDays <= 0 || $requestedDays >= 1) {
                throw new DomainRuleException('時間単位として妥当な取得時間を指定してください。');
            }

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
