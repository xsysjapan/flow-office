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
use App\Models\EmployeeCalendarEntry;
use App\Models\ShiftSwapRequest;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 振替休日を申請する。対象日・振替先日のどちらを休日→労働日にし、どちらを労働日→休日に
 * するかは問わない(対象日・振替先日のうち、現在休日である方を基準に検証する。休日→労働日
 * (旧来の対象日側)・労働日→休日(旧来の振替先日側)のどちらの方向で申請しても同じ検証を行う)。
 * 固定勤務であること・所定労働時間週40時間の制約・法定休日の振替は同一週内に限る制約
 * (変形休日制を除く)を検証してから、ShiftSwapRequestAggregate::request()を保存する。
 * 実際のシフト入れ替えはこの時点ではまだ行わず、承認時(ApproveShiftSwapRequestHandler)に
 * 実行する(ルートCLAUDE.md「打刻と勤怠編集を区別する」と同様、申請と実行のタイミングを
 * 分離する)。
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

        $targetAssignment = EmployeeCalendarEntry::query()
            ->with('workStyle.calendar')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        $substituteAssignment = EmployeeCalendarEntry::query()
            ->with('workStyle.calendar')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->substituteDate)
            ->first();

        $targetIsHoliday = $this->isHoliday($targetAssignment);
        $substituteIsHoliday = $this->isHoliday($substituteAssignment);

        if ($targetIsHoliday && $substituteIsHoliday) {
            throw new DomainRuleException('対象日・振替先のどちらか一方のみを休日にしてください。');
        }
        if (! $targetIsHoliday && ! $substituteIsHoliday) {
            throw new DomainRuleException('対象日または振替先のいずれかが休日である必要があります。');
        }

        // 休日→労働日にする方を基準(holiday)、労働日→休日にする方を基準(workday)とする。
        // 申請時のtarget_date/substitute_dateのどちらがどちらの方向でも扱えるようにする。
        $holidayAssignment = $targetIsHoliday ? $targetAssignment : $substituteAssignment;
        $holidayDate = $targetIsHoliday ? $targetDate : $substituteDate;
        $workdayDate = $targetIsHoliday ? $substituteDate : $targetDate;

        $workStyle = $holidayAssignment->workStyle
            ?? $this->workStyleFallbackResolver->resolveForUser($command->userId, $holidayDate);

        if ($workStyle === null || $workStyle->work_time_system !== WorkStyle::WORK_TIME_SYSTEM_FIXED) {
            throw new DomainRuleException('固定勤務の社員のみ振替休日を申請できます。');
        }

        [$weekStart, $weekEnd] = $this->weekBoundariesFor($holidayDate, $workStyle);

        if ($holidayAssignment->is_legal_holiday
            && $workStyle->legal_holiday_rule !== WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS
            && ($workdayDate->lt($weekStart) || $workdayDate->gt($weekEnd))
        ) {
            throw new DomainRuleException('法定休日を振り替える場合、振替先は同一週内である必要があります。');
        }

        $weeklyPlannedMinutes = EmployeeCalendarEntry::query()
            ->where('user_id', $command->userId)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
            ->whereDate('work_date', '!=', $holidayDate->toDateString())
            ->get()
            ->sum(fn (EmployeeCalendarEntry $assignment) => $assignment->plannedWorkMinutes());

        if ($weeklyPlannedMinutes >= self::WEEKLY_STATUTORY_LIMIT_MINUTES) {
            throw new DomainRuleException('休日を含む週は既に所定労働時間が週40時間に達しているため振替できません。');
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
     * 振替対象にできない(EditEmployeeCalendarEntryHandlerと同じ考え方)。
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
     * 勤務予定行が存在し、労働日ではなく、法定休日または所定休日であるか。行が存在しない日は
     * (シフト行が未展開なだけの通常の労働日として扱い)休日とはみなさない。
     */
    private function isHoliday(?EmployeeCalendarEntry $assignment): bool
    {
        return $assignment !== null
            && ! $assignment->is_working_day
            && ($assignment->is_legal_holiday || $assignment->is_company_holiday);
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
