<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeave\Commands\GrantScheduledSpecialLeave;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 特別休暇種別ごとの自動付与ルールに基づき特別休暇を自動付与する。
 * GrantScheduledPaidLeaveHandlerと同じ考え方だが、有給の法定8割出勤要件の判定
 * (paid_leave_%のみを出勤扱いとする)には一切手を入れず、こちらは独自に出勤率を判定する
 * (有給・特別休暇いずれの消化日も出勤扱いとする。会社独自の制度のため要件は柔軟に持てる)。
 *
 * @implements CommandHandler<GrantScheduledSpecialLeave>
 */
class GrantScheduledSpecialLeaveHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array<int, string> 作成された special_leave_grants のID一覧
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GrantScheduledSpecialLeave);

        $today = $command->asOf !== null ? Carbon::parse($command->asOf) : Carbon::today();
        $grantedIds = [];

        $rules = SpecialLeaveGrantRule::query()->where('is_active', true)->with(['steps', 'specialLeaveType'])->get();

        foreach ($rules as $rule) {
            if (! $rule->specialLeaveType->is_active) {
                continue;
            }

            foreach ($this->eligibleUsers($rule, $today) as $user) {
                $months = $this->monthsOfServiceOnAnniversary($user->hire_date, $today);

                if ($months === null || $months < $rule->first_grant_after_months) {
                    continue;
                }

                $cycleOffset = $months - $rule->first_grant_after_months;
                if ($cycleOffset % $rule->grant_cycle_months !== 0) {
                    continue;
                }

                $alreadyGrantedToday = SpecialLeaveGrant::query()
                    ->where('user_id', $user->id)
                    ->where('special_leave_type_id', $rule->special_leave_type_id)
                    ->whereDate('granted_on', $today->toDateString())
                    ->exists();
                if ($alreadyGrantedToday) {
                    continue;
                }

                $grantDays = $this->resolveGrantDays($rule, $months);
                if ($grantDays <= 0) {
                    continue;
                }

                if (! $this->meetsAttendanceRate($user, $rule, $today)) {
                    continue;
                }

                $expiresOn = $rule->expires_after_months !== null
                    ? $today->copy()->addMonths($rule->expires_after_months)->toDateString()
                    : null;

                $grant = $this->commandBus->dispatch(new GrantSpecialLeave(
                    userId: $user->id,
                    specialLeaveTypeId: $rule->special_leave_type_id,
                    grantedOn: $today->toDateString(),
                    expiresOn: $expiresOn,
                    grantedDays: (float) $grantDays,
                    grantReason: "自動付与（{$rule->name}、勤続{$months}か月）",
                ));

                $grantedIds[] = $grant->id;
            }
        }

        return $grantedIds;
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleUsers(SpecialLeaveGrantRule $rule, Carbon $today): Collection
    {
        $query = User::query()->whereNotNull('hire_date');

        if ($rule->work_style_id !== null) {
            $userIds = EmployeeShiftAssignment::query()
                ->where('work_style_id', $rule->work_style_id)
                ->whereDate('work_date', $today->toDateString())
                ->pluck('user_id');
            $query->whereIn('id', $userIds);
        }

        return $query->get();
    }

    private function monthsOfServiceOnAnniversary(?Carbon $hireDate, Carbon $today): ?int
    {
        if ($hireDate === null) {
            return null;
        }

        $months = $hireDate->diffInMonths($today);

        return $hireDate->copy()->addMonths($months)->isSameDay($today) ? $months : null;
    }

    private function resolveGrantDays(SpecialLeaveGrantRule $rule, int $months): int
    {
        $applicableStep = $rule->steps
            ->filter(fn ($step) => $step->continuous_service_months <= $months)
            ->sortByDesc('continuous_service_months')
            ->first();

        return $applicableStep?->grant_days ?? 0;
    }

    private function meetsAttendanceRate(User $user, SpecialLeaveGrantRule $rule, Carbon $today): bool
    {
        $periodStart = $today->copy()->subMonths($rule->grant_cycle_months);

        $scheduledDates = EmployeeShiftAssignment::query()
            ->where('user_id', $user->id)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $today->toDateString())
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        if ($scheduledDates->isEmpty()) {
            return false;
        }

        $attendedDates = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $today->toDateString())
            ->where(function ($query) {
                $query->where('status', AttendanceDayStatus::CLOCKED_OUT)
                    ->orWhere('work_type', 'like', 'paid_leave_%')
                    ->orWhere('work_type', 'like', 'special_leave_%');
            })
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        $attendedCount = $scheduledDates->intersect($attendedDates)->count();
        $rate = ($attendedCount / $scheduledDates->count()) * 100;

        return $rate >= $rule->min_attendance_rate;
    }
}
