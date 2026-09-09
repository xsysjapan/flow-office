<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Domain\PaidLeaveSchedule\Support\GrantDaysResolver;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrantRule;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * hire_date/usage_start_date/work_style_id割当/独自ルール変更等の変更を検知した
 * Reactor(Phase C)から発行される。過去確定(Granted/Cancelled)・個別修正
 * (manualOverride)エントリの保護は`PaidLeaveScheduleAggregate::supersedeEntry()`側で
 * 行うため、このHandlerは対象社員の非終端エントリすべてに新しい算出結果を提示するだけでよい。
 *
 * @implements CommandHandler<RecalculateFutureSchedule>
 */
class RecalculateFutureScheduleHandler implements CommandHandler
{
    public function __construct(
        private readonly GrantCategoryClassifier $classifier,
        private readonly GrantDaysResolver $grantDaysResolver,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RecalculateFutureSchedule);

        $user = User::query()->findOrFail($command->userId);
        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($command->userId));

        $rule = PaidLeaveGrantRule::query()->where('is_active', true)->with('steps')
            ->where(fn ($q) => $q->whereNull('work_style_id')->orWhere('work_style_id', $this->currentWorkStyleId($user)))
            ->first();

        $workStyle = $this->currentWorkStyle($user);
        $newCategory = $this->classifier->classify($workStyle);

        foreach ($aggregate->allEntryIds() as $entryId) {
            $entry = $aggregate->entry($entryId);

            if (ScheduleEntryStatus::isTerminal($entry['status'])) {
                continue;
            }

            $scheduledOn = Carbon::parse($entry['scheduledOn']);
            $months = $user->hire_date !== null ? Carbon::parse($user->hire_date)->diffInMonths($scheduledOn) : 0;
            $newCandidateGrantDays = $this->grantDaysResolver->resolve($rule, $months, $newCategory, $workStyle);

            $aggregate->supersedeEntry(
                entryId: $entryId,
                reason: $command->reason,
                newCategory: $newCategory,
                newCandidateGrantDays: $newCandidateGrantDays,
            );
        }

        $aggregate->persist();

        return null;
    }

    private function currentWorkStyleId(User $user): ?string
    {
        return EmployeeCalendarEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '<=', Carbon::now()->toDateString())
            ->orderByDesc('work_date')
            ->value('work_style_id');
    }

    private function currentWorkStyle(User $user): ?WorkStyle
    {
        $workStyleId = $this->currentWorkStyleId($user);

        return $workStyleId === null ? null : WorkStyle::query()->find($workStyleId);
    }
}
