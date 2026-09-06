<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\PaidLeave\Commands\GrantScheduledPaidLeave;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\DailyBatchTimezoneGroups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UC-P002: 有給を自動付与する。
 *
 * 対象者の判定は「入社日からの継続勤務期間が、付与ルールの初回付与月数・付与サイクル月数に
 * ちょうど合致する月次記念日」を基準日として毎日実行することを前提にする(cron;
 * routes/console.php参照)。同じ対象者に同日重複付与しないよう、当日すでに付与済みの場合は
 * スキップする。
 *
 * 出勤率は、直近の付与サイクル期間(grant_cycle_months)における `employee_calendar_entries`
 * の勤務予定日を分母、`attendance_days` が退勤済みまたは有給消化済みの日を分子として計算する
 * (有給取得日は出勤したものとして扱う。労働基準法上の8割出勤要件の考え方に基づく)。
 * 期間中の勤務予定日が1件も無い場合は判定不能としてスキップする。
 * 利用開始日が設定されていて、かつそれが未到来の場合は対象外とする(未設定なら制限しない)。
 *
 * @implements CommandHandler<GrantScheduledPaidLeave>
 */
class GrantScheduledPaidLeaveHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array<int, string> 作成された paid_leave_grants のID一覧
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GrantScheduledPaidLeave);

        $defaultTimezone = SystemSetting::current()->default_timezone;
        $grantedIds = [];

        $rules = PaidLeaveGrantRule::query()->where('is_active', true)->with('steps')->get();

        // `asOf`未指定(cronからの実運用)の場合、会社既定のタイムゾーンではなく社員本人の
        // `users.timezone`基準で「今日」を判定する。タイムゾーンごとに対象社員を絞り、
        // それぞれの「今日」で記念日・出勤率判定を行う(DailyBatchTimezoneGroups参照)。
        // ルール×タイムゾーングループの全組み合わせを回す。
        $timezoneGroups = DailyBatchTimezoneGroups::resolve(
            $command->asOf,
            User::query()->whereNotNull('hire_date'),
        );

        foreach ($rules as $rule) {
            foreach ($timezoneGroups as $group) {
                $today = $group['today'];

                foreach ($this->eligibleUsers($rule, $today, $group['timezone'], $defaultTimezone) as $user) {
                    $months = $this->monthsOfServiceOnAnniversary($user->hire_date, $today);

                    if ($months === null || $months < $rule->first_grant_after_months) {
                        continue;
                    }

                    $cycleOffset = $months - $rule->first_grant_after_months;
                    if ($cycleOffset % $rule->grant_cycle_months !== 0) {
                        continue;
                    }

                    $alreadyGrantedToday = PaidLeaveGrant::query()
                        ->where('user_id', $user->id)
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

                    $grant = $this->commandBus->dispatch(new GrantPaidLeave(
                        userId: $user->id,
                        grantedOn: $today->toDateString(),
                        expiresOn: $today->copy()->addYears(2)->toDateString(),
                        grantedDays: (float) $grantDays,
                        grantReason: "自動付与（{$rule->name}、勤続{$months}か月）",
                    ));

                    $grantedIds[] = $grant->id;
                }
            }
        }

        return $grantedIds;
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleUsers(PaidLeaveGrantRule $rule, Carbon $today, string $timezone, string $defaultTimezone): Collection
    {
        $query = User::query()
            ->whereNotNull('hire_date')
            ->where('paid_leave_auto_grant_enabled', true)
            ->where(DailyBatchTimezoneGroups::constraint($timezone, $defaultTimezone))
            ->where(fn ($q) => $q->whereNull('usage_start_date')->orWhereDate('usage_start_date', '<=', $today->toDateString()));

        if ($rule->work_style_id !== null) {
            $userIds = EmployeeCalendarEntry::query()
                ->where('work_style_id', $rule->work_style_id)
                ->whereDate('work_date', $today->toDateString())
                ->pluck('user_id');
            $query->whereIn('id', $userIds);
        }

        return $query->get();
    }

    /**
     * 入社日から今日までの完了月数を求め、今日がちょうど月次記念日(hire_date + n か月の
     * 同じ日)である場合のみその月数を返す。記念日でなければnullを返す。
     */
    private function monthsOfServiceOnAnniversary(?Carbon $hireDate, Carbon $today): ?int
    {
        if ($hireDate === null) {
            return null;
        }

        $months = $hireDate->diffInMonths($today);

        return $hireDate->copy()->addMonths($months)->isSameDay($today) ? $months : null;
    }

    private function resolveGrantDays(PaidLeaveGrantRule $rule, int $months): int
    {
        $applicableStep = $rule->steps
            ->filter(fn ($step) => $step->continuous_service_months <= $months)
            ->sortByDesc('continuous_service_months')
            ->first();

        return $applicableStep?->grant_days ?? 0;
    }

    private function meetsAttendanceRate(User $user, PaidLeaveGrantRule $rule, Carbon $today): bool
    {
        $periodStart = $today->copy()->subMonths($rule->grant_cycle_months);

        // whereDate()で明示的に日付部分のみを比較する。DATETIME格納値との文字列比較で
        // 境界日(当日)がずれて除外されるのを避けるため (whereBetween/whereInの生文字列
        // 比較には頼らない)。
        $scheduledDates = EmployeeCalendarEntry::query()
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
                    ->orWhere('work_type', 'like', 'paid_leave_%');
            })
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        $attendedCount = $scheduledDates->intersect($attendedDates)->count();
        $rate = ($attendedCount / $scheduledDates->count()) * 100;

        return $rate >= $rule->min_attendance_rate;
    }
}
