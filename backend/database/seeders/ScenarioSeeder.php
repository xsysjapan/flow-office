<?php

namespace Database\Seeders;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\User\Commands\AssignUserRoles;
use App\Domain\User\Commands\SetUserHireDate;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * docs/testing/scenario-tests.md のシナリオを実施するための最小マスタデータを投入する。
 *
 * 前提: DatabaseSeeder (roles / request_types / admin@example.com) が実行済みであること。
 * 何度実行しても壊れないよう firstOrCreate/updateOrCreate のみを使う。
 *
 * 実行: cd backend && php artisan db:seed --class=ScenarioSeeder
 *
 * 投入するユーザーは mock-oidc/server.js の追加ユーザー(mock-entra-user-004〜009)と
 * entra_user_id・emailを揃えている。モックOIDCでログインすると初回ログインではなく
 * このユーザーとして扱われ、ロール・入社日が設定済みの状態でシナリオを開始できる。
 */
class ScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $commandBus = app(CommandBus::class);

        $this->linkAdminToMockOidc();

        $month = Carbon::now()->startOfMonth();
        $calendarFrom = $month->copy()->subMonth()->startOfMonth();
        $calendarTo = $month->copy()->addMonth()->endOfMonth();

        $calendar = $this->seedCalendar($month, $calendarFrom, $calendarTo);
        $punchWorkStyle = $this->seedWorkStyle('standard_punch', '標準勤務(打刻)', $calendar);
        $monthlyWorkStyle = $this->seedWorkStyle('standard_monthly', '標準勤務(月次入力)', $calendar);
        $this->seedPaidLeaveGrantRule();

        $users = $this->seedUsers($commandBus);

        $this->generateShiftAssignments($users['punch']->id, $punchWorkStyle, $calendarFrom, $calendarTo);
        $this->generateShiftAssignments($users['monthly']->id, $monthlyWorkStyle, $calendarFrom, $calendarTo);

        $this->grantPaidLeave($commandBus, $users['punch'], $month);
        $this->grantPaidLeave($commandBus, $users['monthly'], $month);
    }

    /**
     * DatabaseSeederが作成する admin@example.com は entra_user_id がランダムなUUIDのため
     * (UserFactory参照)、そのままではモックOIDCでログインできない。mock-oidc/server.js の
     * 'mock-entra-admin' エントリと対応させ、管理者としてもシナリオを実施できるようにする。
     */
    private function linkAdminToMockOidc(): void
    {
        User::query()->where('email', 'admin@example.com')->update(['entra_user_id' => 'mock-entra-admin']);
    }

    private function seedCalendar(Carbon $month, Carbon $from, Carbon $to): WorkCalendar
    {
        $calendar = WorkCalendar::query()->firstOrCreate(
            ['fiscal_year' => $month->year],
            [
                'name' => "{$month->year}年度 シナリオテスト用カレンダー",
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'week_starts_on' => 1,
                'status' => 'draft',
            ]
        );

        // 土日を法定休日、それ以外を平日として簡易的に登録する(実運用では祝日マスタ等と
        // 突き合わせて個別設定するが、シナリオテスト用の最小データとして割り切る)。
        $period = $from->copy()->toPeriod($to);
        foreach ($period as $date) {
            $isWeekend = $date->isWeekend();

            // 'date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する
            // (WorkCalendarController::putDaysと同じ理由)。
            $calendarDay = $calendar->days()->whereDate('date', $date->toDateString())->first()
                ?? $calendar->days()->make(['date' => $date->toDateString()]);

            $calendarDay->fill([
                'day_type' => $isWeekend ? 'legal_holiday' : 'weekday',
                'is_working_day' => ! $isWeekend,
                'is_legal_holiday' => $isWeekend,
                'is_company_holiday' => false,
            ])->save();
        }

        $calendar->update(['status' => 'published']);

        return $calendar;
    }

    private function seedWorkStyle(string $code, string $name, WorkCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'work_time_system' => 'fixed',
                'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 2400,
                'default_start_time' => '09:00',
                'default_end_time' => '18:00',
                'default_break_minutes' => 60,
                'calendar_id' => $calendar->id,
                'is_shift_based' => false,
            ]
        );
    }

    private function seedPaidLeaveGrantRule(): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->firstOrCreate(
            ['name' => '一般社員 標準付与ルール(シナリオテスト用)'],
            [
                'min_attendance_rate' => 80,
                'first_grant_after_months' => 6,
                'grant_cycle_months' => 12,
                'is_active' => true,
            ]
        );

        if ($rule->steps()->count() === 0) {
            foreach ([
                ['continuous_service_months' => 6, 'grant_days' => 10],
                ['continuous_service_months' => 18, 'grant_days' => 11],
                ['continuous_service_months' => 30, 'grant_days' => 12],
            ] as $step) {
                $rule->steps()->create($step);
            }
        }

        return $rule;
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(CommandBus $commandBus): array
    {
        $definitions = [
            'punch' => [
                'entra_user_id' => 'mock-entra-user-004',
                'name' => '高橋 健太',
                'email' => 'kenta.takahashi@example.com',
                'department' => '開発部',
                'job_title' => '一般社員',
                'roles' => [Role::EMPLOYEE],
                'hire_date' => '2023-04-01',
            ],
            'monthly' => [
                'entra_user_id' => 'mock-entra-user-005',
                'name' => '伊藤 舞',
                'email' => 'mai.ito@example.com',
                'department' => '営業部',
                'job_title' => '一般社員',
                'roles' => [Role::EMPLOYEE],
                'hire_date' => '2023-04-01',
            ],
            'approver' => [
                'entra_user_id' => 'mock-entra-user-006',
                'name' => '渡辺 直樹',
                'email' => 'naoki.watanabe@example.com',
                'department' => '開発部',
                'job_title' => 'マネージャー',
                'roles' => [Role::EMPLOYEE],
                'hire_date' => '2018-04-01',
            ],
            'accounting_staff' => [
                'entra_user_id' => 'mock-entra-user-007',
                'name' => '小林 誠',
                'email' => 'makoto.kobayashi@example.com',
                'department' => '経理部',
                'job_title' => '経理担当者',
                'roles' => [Role::ACCOUNTING_STAFF],
                'hire_date' => '2019-04-01',
            ],
            'general_affairs_staff' => [
                'entra_user_id' => 'mock-entra-user-008',
                'name' => '中村 恵',
                'email' => 'megumi.nakamura@example.com',
                'department' => '総務部',
                'job_title' => '総務担当者',
                'roles' => [Role::GENERAL_AFFAIRS_STAFF],
                'hire_date' => '2019-04-01',
            ],
            'hr_staff' => [
                'entra_user_id' => 'mock-entra-user-009',
                'name' => '加藤 由美',
                'email' => 'yumi.kato@example.com',
                'department' => '人事部',
                'job_title' => '人事担当者',
                'roles' => [Role::HR_STAFF],
                'hire_date' => '2017-04-01',
            ],
        ];

        $users = [];

        foreach ($definitions as $key => $definition) {
            $user = User::query()->firstOrCreate(
                ['entra_user_id' => $definition['entra_user_id']],
                [
                    'name' => $definition['name'],
                    'email' => $definition['email'],
                    'department' => $definition['department'],
                    'job_title' => $definition['job_title'],
                    'employment_status' => 'active',
                    'timezone' => 'Asia/Tokyo',
                ]
            );

            $commandBus->dispatch(new AssignUserRoles(
                userId: $user->id,
                roleCodes: $definition['roles'],
                changedByUserId: $user->id,
            ));

            $commandBus->dispatch(new SetUserHireDate(
                userId: $user->id,
                hireDate: $definition['hire_date'],
                changedByUserId: $user->id,
            ));

            $users[$key] = $user->refresh();
        }

        return $users;
    }

    private function generateShiftAssignments(int $userId, WorkStyle $workStyle, Carbon $from, Carbon $to): void
    {
        $calendarDaysByDate = $workStyle->calendar->days()->get()->keyBy(fn ($day) => $day->date->toDateString());

        $period = $from->copy()->toPeriod($to);

        foreach ($period as $date) {
            $calendarDay = $calendarDaysByDate->get($date->toDateString());
            $isWorkingDay = $calendarDay?->is_working_day ?? true;

            // 'work_date' もdateキャストのため、上と同じ理由でwhereDateにより明示的に検索する。
            $assignment = EmployeeShiftAssignment::query()
                ->where('user_id', $userId)
                ->whereDate('work_date', $date->toDateString())
                ->first() ?? new EmployeeShiftAssignment(['user_id' => $userId, 'work_date' => $date->toDateString()]);

            $assignment->fill([
                'work_style_id' => $workStyle->id,
                'day_type' => $calendarDay?->day_type ?? 'weekday',
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $calendarDay?->is_legal_holiday ?? false,
                'is_company_holiday' => $calendarDay?->is_company_holiday ?? false,
                'planned_start_at' => $isWorkingDay ? $date->copy()->setTimeFromTimeString($workStyle->default_start_time) : null,
                'planned_end_at' => $isWorkingDay ? $date->copy()->setTimeFromTimeString($workStyle->default_end_time) : null,
                'planned_break_minutes' => $isWorkingDay ? $workStyle->default_break_minutes : 0,
            ])->save();
        }
    }

    private function grantPaidLeave(CommandBus $commandBus, User $user, Carbon $month): void
    {
        $grantedOn = $month->copy()->startOfMonth();

        $alreadyGranted = PaidLeaveGrant::query()
            ->where('user_id', $user->id)
            ->whereDate('granted_on', $grantedOn->toDateString())
            ->exists();

        if ($alreadyGranted) {
            return;
        }

        $commandBus->dispatch(new GrantPaidLeave(
            userId: $user->id,
            grantedOn: $grantedOn->toDateString(),
            expiresOn: $grantedOn->copy()->addYears(2)->toDateString(),
            grantedDays: 10.0,
            grantReason: 'シナリオテスト用 初期付与',
        ));
    }
}
