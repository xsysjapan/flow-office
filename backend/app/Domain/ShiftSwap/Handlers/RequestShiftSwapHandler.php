<?php

namespace App\Domain\ShiftSwap\Handlers;

use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\WorkStyleFallbackResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ShiftSwap\Aggregates\ShiftSwapRequestAggregate;
use App\Domain\ShiftSwap\Commands\RequestShiftSwap;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\ShiftSwapRequest;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 振替休日を申請する。対象日が固定勤務の休日であること・所定労働時間週40時間の制約・
 * 法定休日の振替は同一週内に限る制約(変形休日制を除く)を検証してから、
 * ShiftSwapRequestAggregate::request()を保存する。実際のシフト入れ替えはこの時点では
 * まだ行わず、承認時(ApproveShiftSwapRequestHandler)に実行する
 * (ルートCLAUDE.md「打刻と勤怠編集を区別する」と同様、申請と実行のタイミングを分離する)。
 *
 * @implements CommandHandler<RequestShiftSwap>
 */
class RequestShiftSwapHandler implements CommandHandler
{
    private const WEEKLY_STATUTORY_LIMIT_MINUTES = 2400; // 労基法32条: 1週40時間

    public function __construct(
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): ShiftSwapRequest
    {
        assert($command instanceof RequestShiftSwap);

        $targetDate = Carbon::parse($command->targetDate)->startOfDay();
        $substituteDate = Carbon::parse($command->substituteDate)->startOfDay();

        if ($targetDate->equalTo($substituteDate)) {
            throw new DomainRuleException('振替先は対象日と異なる日にしてください。');
        }

        $targetAssignment = EmployeeShiftAssignment::query()
            ->with('workStyle.calendar')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        $workStyle = $targetAssignment?->workStyle
            ?? $this->workStyleFallbackResolver->resolveForUser($command->userId, $targetDate);

        if ($workStyle === null || $workStyle->work_time_system !== WorkStyle::WORK_TIME_SYSTEM_FIXED) {
            throw new DomainRuleException('固定勤務の社員のみ振替休日を申請できます。');
        }

        if ($targetAssignment === null
            || $targetAssignment->is_working_day
            || ! ($targetAssignment->is_legal_holiday || $targetAssignment->is_company_holiday)
        ) {
            throw new DomainRuleException('対象日は休日ではないため振替できません。');
        }

        [$weekStart, $weekEnd] = $this->weekBoundariesFor($targetDate, $workStyle);

        if ($targetAssignment->is_legal_holiday
            && $workStyle->legal_holiday_rule !== WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS
            && ($substituteDate->lt($weekStart) || $substituteDate->gt($weekEnd))
        ) {
            throw new DomainRuleException('法定休日を振り替える場合、振替先は同一週内である必要があります。');
        }

        $weeklyPlannedMinutes = EmployeeShiftAssignment::query()
            ->where('user_id', $command->userId)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
            ->whereDate('work_date', '!=', $targetDate->toDateString())
            ->get()
            ->sum(fn (EmployeeShiftAssignment $assignment) => $assignment->plannedWorkMinutes());

        if ($weeklyPlannedMinutes >= self::WEEKLY_STATUTORY_LIMIT_MINUTES) {
            throw new DomainRuleException('対象日を含む週は既に所定労働時間が週40時間に達しているため振替できません。');
        }

        $this->assertSwappable($command->userId, $command->targetDate);
        $this->assertSwappable($command->userId, $command->substituteDate);

        $requestId = $command->requestId ?? (string) Str::uuid();

        $aggregate = ShiftSwapRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                targetDate: $command->targetDate,
                substituteDate: $command->substituteDate,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
            );

        // workflow_requestが指定されている場合、ShiftSwapRequestSharedイベントを発行して
        // workflow_requestの提出を促す(ReactorからのRequestShiftSwapのみこのIDを持つ)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        // 通知はSubmitWorkflowRequestHandlerが一括して送るため、ここでは送らない
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)

        return ShiftSwapRequest::query()->findOrFail($requestId);
    }

    /**
     * 既に勤務実績がある、または編集不可(締め後・提出済み以降の月次等)の日は
     * 振替対象にできない(EditEmployeeShiftAssignmentHandlerと同じ考え方)。
     */
    private function assertSwappable(string $userId, string $date): void
    {
        $day = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $date)
            ->first();

        if ($day?->actual_start_at !== null) {
            throw new DomainRuleException('既に勤務実績がある日を振替対象にすることはできません。');
        }

        $this->guard->assertMutable($day, $userId, $date);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function weekBoundariesFor(Carbon $date, WorkStyle $workStyle): array
    {
        $weekStartsOn = $workStyle->calendar?->week_starts_on ?? 1;

        $start = $date->copy();
        while ($start->isoWeekday() !== $weekStartsOn) {
            $start->subDay();
        }

        return [$start->copy()->startOfDay(), $start->copy()->addDays(6)->endOfDay()];
    }
}
