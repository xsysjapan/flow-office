<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\CompensatoryLeave\Support\CompensatoryLeaveWorkType;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 代休を消化申請する。勤怠(実績)を先に作る/編集するという通常の業務フローに合わせ、
 * 申請した時点で対象日の勤怠(attendance_days.work_type)へ即座に反映する
 * (承認を待たない。承認は「事後の確認・記録」に位置づけが変わり、実際の消化
 * (grantの残数減算)のみを承認時に行う。ApproveCompensatoryLeaveRequestHandler参照)。
 * 残数が不足していても申請(=勤怠への反映)自体は成立させる(RequestPaidLeaveHandlerと
 * 同じ考え方)。
 *
 * @implements CommandHandler<RequestCompensatoryLeave>
 */
class RequestCompensatoryLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof RequestCompensatoryLeave);

        $unit = SystemSetting::current()->compensatory_leave_unit;
        $this->assertLeaveTypeAllowed($unit, $command->leaveType);

        $targetDate = Carbon::parse($command->targetDate);

        $calendarEntry = EmployeeCalendarEntry::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        if ($calendarEntry !== null) {
            if (! $calendarEntry->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため代休を申請できません。');
            }
        } elseif (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
            throw new DomainRuleException('勤務予定日ではないため代休を申請できません。');
        }

        if ($this->alreadyHasLeaveOnDate($command->userId, $command->targetDate)) {
            throw new DomainRuleException('この日は既に有給・特別休暇・代休を申請済みです。');
        }

        [$requestedDays, $requestedMinutes] = $this->resolveRequestedAmount($command);

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

        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                requestedMinutes: $requestedMinutes,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
                requestGroupId: $command->requestGroupId,
            )
            // compensatory_leave_usagesへgrant未確定の行を作る(承認時にどのgrantから消化するかが
            // 決まった時点で確定済みへ更新される。CompensatoryLeaveGrantProjector参照)。勤怠側は
            // この行の存在だけで休暇設定の有無を判定でき、compensatory_leave_requestsを見に行く
            // 必要が無くなる(ルートCLAUDE.md「操作経路と業務ロジックを分離する」と同じ考え方で、
            // ドメインをまたいだ参照を避ける)。
            ->designateUsage(
                userId: $command->userId,
                attendanceDayId: $day->id,
                usedOn: $command->targetDate,
                usedDays: $requestedDays,
                usedMinutes: $requestedMinutes,
                usageType: $command->leaveType,
            );

        // workflow_requestが指定されている場合、CompensatoryLeaveRequestSharedイベントを発行して
        // workflow_requestの提出を促す(SpecialLeaveと同じパターン)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        $calculation = $this->calculator->calculate(
            $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
        );
        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return CompensatoryLeaveRequest::query()->findOrFail($requestId);
    }

    /**
     * 対象日の勤怠(attendance_days)へ代休区分を反映する(旧ApproveCompensatoryLeaveRequestHandlerの
     * reflectOnAttendanceDayを申請時点へ移したもの)。ガード済みの`$existingDay`をそのまま
     * 使い回すことで、直前の`assertMutable`呼び出しと二重に問い合わせない。集約の永続化前に
     * `$day->id`を確定させる必要があるため、コマンドの値からそのまま反映する
     * (RequestPaidLeaveHandler::reflectOnAttendanceDayと同じ考え方)。
     */
    private function reflectOnAttendanceDay(RequestCompensatoryLeave $command, ?AttendanceDay $existingDay): AttendanceDay
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

        $day->work_type = CompensatoryLeaveWorkType::toAttendanceWorkType($command->leaveType);
        if ($command->leaveType === PaidLeaveType::FULL) {
            // 全休は出退勤操作が発生しないため、締め忘れとして警告されないよう完了扱いにする。
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        return $day;
    }

    private function assertLeaveTypeAllowed(string $unit, string $leaveType): void
    {
        $allowed = match ($unit) {
            'daily' => [PaidLeaveType::FULL],
            'half_day' => [PaidLeaveType::FULL, PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF],
            'hourly' => [PaidLeaveType::HOURLY],
            default => [],
        };

        if (! in_array($leaveType, $allowed, true)) {
            throw new DomainRuleException('現在の代休取得単位設定では指定の取得単位は使用できません。');
        }
    }

    /**
     * 同じ日にactive(提出中・承認済み)な有給・特別休暇・代休の申請が既にあるか。
     * attendance_days.work_typeは1日1件しか値を持てないため、いずれの休暇であっても
     * 二重申請を防ぐ必要がある。
     */
    private function alreadyHasLeaveOnDate(string $userId, string $targetDate): bool
    {
        $hasPaidLeave = PaidLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED])
            ->exists();

        if ($hasPaidLeave) {
            return true;
        }

        $hasSpecialLeave = SpecialLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->exists();

        if ($hasSpecialLeave) {
            return true;
        }

        return CompensatoryLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [CompensatoryLeaveRequestStatus::SUBMITTED, CompensatoryLeaveRequestStatus::APPROVED])
            ->exists();
    }

    /**
     * @return array{0: float, 1: ?int}
     */
    private function resolveRequestedAmount(RequestCompensatoryLeave $command): array
    {
        if ($command->leaveType === PaidLeaveType::FULL) {
            return [1.0, null];
        }

        if (in_array($command->leaveType, [PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            return [0.5, null];
        }

        if ($command->leaveType === PaidLeaveType::HOURLY) {
            if ($command->hours === null || $command->hours <= 0) {
                throw new DomainRuleException('時間単位の場合は取得時間を指定してください。');
            }

            return [0.0, (int) round($command->hours * 60)];
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
