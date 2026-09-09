<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Domain\PaidLeaveSchedule\Support\GrantDaysResolver;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrantRule;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 対象社員1名について、現在から1年先までのScheduleエントリ存在を保証する(べき等)。
 *
 * 候補付与日数は`GrantDaysResolver`(spec.md論点5: 独自ルール優先、無ければ
 * `GrantCategoryClassifier`の区分に対応する法定Policy)で決定する。
 * `paid-leave:roll-schedules`バッチ本体(全社員ループ)はPhase Cで実装する
 * (spec.md「実装対象」)。
 *
 * @implements CommandHandler<EnsureFutureScheduleGenerated>
 */
class EnsureFutureScheduleGeneratedHandler implements CommandHandler
{
    public function __construct(
        private readonly GrantCategoryClassifier $classifier,
        private readonly GrantDaysResolver $grantDaysResolver,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof EnsureFutureScheduleGenerated);

        $user = User::query()->findOrFail($command->userId);

        if ($user->hire_date === null) {
            return null;
        }

        $horizon = Carbon::now()->addYear();
        $aggregate = PaidLeaveScheduleAggregate::retrieve($command->userId);

        $rule = PaidLeaveGrantRule::query()->where('is_active', true)->with('steps')
            ->where(fn ($q) => $q->whereNull('work_style_id')->orWhere('work_style_id', $this->currentWorkStyleId($user)))
            ->first();

        $cycleMonths = $rule?->grant_cycle_months ?? 12;
        $firstGrantAfterMonths = $rule?->first_grant_after_months ?? 6;

        $hireDate = Carbon::parse($user->hire_date);
        $months = $firstGrantAfterMonths;

        while (true) {
            $scheduledOn = $hireDate->copy()->addMonths($months);

            if ($scheduledOn->gt($horizon)) {
                break;
            }

            if ($aggregate->entryIdForDate($scheduledOn->toDateString()) === null) {
                $workStyle = $this->currentWorkStyle($user);
                $category = $this->classifier->classify($workStyle);
                $candidateGrantDays = $this->grantDaysResolver->resolve($rule, $months, $category, $workStyle);

                $aggregate->createEntry(
                    entryId: (string) Str::uuid(),
                    scheduledOn: $scheduledOn->toDateString(),
                    category: $category,
                    candidateGrantDays: $candidateGrantDays,
                );
            }

            $months += $cycleMonths;
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
