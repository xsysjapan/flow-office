<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 特別休暇を申請する。特別休暇申請の集約(SpecialLeaveRequestAggregate)へ申請を記録するだけで、
 * 他の文脈の集約・テーブルは読み書きしない(原則15)。
 *
 * - 種別の有効性・勤務予定日でない日の申請拒否・時間休の入力検証は、この文脈のルールとして残す。
 * - 残数の消化記録(特別休暇口座)・勤怠の休暇(勤怠のReactor)・ワークフローの提出は、記録された
 *   `special_leave.requested`/`.shared`をそれぞれの文脈のReactorが受けて行う。
 * - 同日の休暇の衝突・締め判定は勤怠文脈が休暇の反映時に行う(違反なら連鎖全体が取り消される)。
 * - viaReactor=true(workflow_request.drafted からのReactor発行)で、同じ申請IDが既に申請済みなら何もしない(冪等)。
 *
 * @implements CommandHandler<RequestSpecialLeave>
 */
class RequestSpecialLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
    ) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof RequestSpecialLeave);

        $requestId = $command->requestId ?? (string) Str::uuid();
        $aggregate = SpecialLeaveRequestAggregate::retrieve($requestId);

        if ($command->viaReactor && $aggregate->status() !== 'none') {
            return SpecialLeaveRequest::query()->findOrFail($requestId);
        }

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

        $requestedDays = $this->resolveRequestedDays($command, $workStyle);

        $aggregate->request(
            userId: $command->userId,
            specialLeaveTypeId: $command->specialLeaveTypeId,
            targetDate: $command->targetDate,
            leaveType: $command->leaveType,
            hours: $command->hours,
            requestedDays: $requestedDays,
            approverUserId: $command->approverUserId,
            reason: $command->reason,
            requestGroupId: $command->requestGroupId,
        );

        // ワークフローと対応する申請は提出を記録する(ワークフローの提出はWorkflow側のReactorが行う)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        return SpecialLeaveRequest::query()->findOrFail($requestId);
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
