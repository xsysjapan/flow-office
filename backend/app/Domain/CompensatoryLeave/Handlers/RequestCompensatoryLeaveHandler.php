<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\PaidLeaveType;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 代休を申請する。代休申請の集約(CompensatoryLeaveRequestAggregate)へ申請を記録するだけで、
 * 他の文脈の集約・テーブルは読み書きしない(原則15)。
 *
 * - 取得単位の設定・勤務予定日でない日の申請拒否・時間休の入力検証は、この文脈のルールとして残す。
 * - 残数の消化記録(代休口座)・勤怠の休暇(勤怠のReactor)・ワークフローの提出は、記録された
 *   `compensatory_leave.requested`/`.shared`をそれぞれの文脈のReactorが受けて行う。
 * - 同日の休暇の衝突・締め判定は勤怠文脈が休暇の反映時に行う(違反なら連鎖全体が取り消される)。
 * - viaReactor=true(workflow_request.drafted からのReactor発行)で、同じ申請IDが既に申請済みなら何もしない(冪等)。
 *
 * @implements CommandHandler<RequestCompensatoryLeave>
 */
class RequestCompensatoryLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RequestCompensatoryLeave);

        $requestId = $command->requestId ?? (string) Str::uuid();
        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($requestId);

        if ($command->viaReactor && $aggregate->status() !== 'none') {
            return null;
        }

        $unit = SystemSetting::current()->compensatory_leave_unit;
        $this->assertLeaveTypeAllowed($unit, $command->leaveType);

        $targetDate = Carbon::parse($command->targetDate);

        $calendarEntry = $this->scheduledWorkingDayResolver->resolveSchedule($command->userId, $targetDate);

        if ($calendarEntry !== null) {
            if (! $calendarEntry->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため代休を申請できません。');
            }
        } elseif (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
            throw new DomainRuleException('勤務予定日ではないため代休を申請できません。');
        }

        [$requestedDays, $requestedMinutes] = $this->resolveRequestedAmount($command);

        $aggregate->request(
            userId: $command->userId,
            targetDate: $command->targetDate,
            leaveType: $command->leaveType,
            hours: $command->hours,
            requestedDays: $requestedDays,
            requestedMinutes: $requestedMinutes,
            approverUserId: $command->approverUserId,
            reason: $command->reason,
            requestGroupId: $command->requestGroupId,
        );

        // ワークフローと対応する申請は提出を記録する(ワークフローの提出はWorkflow側のReactorが行う)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        return null;
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
