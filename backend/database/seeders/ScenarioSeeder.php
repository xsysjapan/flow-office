<?php

namespace Database\Seeders;

use App\Domain\Attendance\Commands\CreateWorkCalendar;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Commands\GenerateEmployeeShiftAssignments;
use App\Domain\Attendance\Commands\PublishWorkCalendar;
use App\Domain\Attendance\Commands\UpdateWorkCalendarDays;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Services\StandardGroupMembershipRecorder;
use App\Models\EmploymentCategory;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * docs/testing/scenario-tests.md のシナリオを実施するための最小マスタデータを投入する。
 *
 * 前提: DatabaseSeeder (roles / request_types / admin@example.com) が実行済みであること。
 * 何度実行しても壊れないよう、既存データの有無を確認してから作成する(CQRS+ESの対象と
 * なるドメイン(WorkCalendar/WorkStyle/User/EmployeeShiftAssignment/PaidLeaveGrant)は
 * 必ずCommand経由でstored_eventsに記録し、マスタ相当のテーブル(EmploymentCategory/
 * PaidLeaveGrantRule)のみfirstOrCreateで直接投入する)。
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

        $admin = $this->linkAdminToMockOidc();

        $month = Carbon::now()->startOfMonth();
        $calendarFrom = $month->copy()->subMonth()->startOfMonth();
        $calendarTo = $month->copy()->addMonth()->endOfMonth();

        $calendar = $this->seedCalendar($commandBus, $admin->id, $month, $calendarFrom, $calendarTo);
        $punchWorkStyle = $this->seedWorkStyle($commandBus, $admin->id, 'standard_punch', '標準勤務(打刻)', $calendar);
        $monthlyWorkStyle = $this->seedWorkStyle($commandBus, $admin->id, 'standard_monthly', '標準勤務(月次入力)', $calendar);
        $this->seedPaidLeaveGrantRule();

        $users = $this->seedUsers($commandBus);

        // DatabaseSeederの共通グループ構築後にシナリオユーザーを作成するため、
        // 新規ユーザーを全利用者・バックオフィス等へ反映し、実効アクセスも同期する。
        $this->call([
            UserManagementSeeder::class,
            AccessControlSeeder::class,
            ScenarioAccessSeeder::class,
        ]);

        $commandBus->dispatch(new GenerateEmployeeShiftAssignments(
            userId: $users['punch']->id,
            workStyleId: $punchWorkStyle->id,
            from: $calendarFrom->toDateString(),
            to: $calendarTo->toDateString(),
            generatedByUserId: $admin->id,
        ));
        $commandBus->dispatch(new GenerateEmployeeShiftAssignments(
            userId: $users['monthly']->id,
            workStyleId: $monthlyWorkStyle->id,
            from: $calendarFrom->toDateString(),
            to: $calendarTo->toDateString(),
            generatedByUserId: $admin->id,
        ));

        $this->grantPaidLeave($commandBus, $users['punch'], $month);
        $this->grantPaidLeave($commandBus, $users['monthly'], $month);
    }

    /**
     * DatabaseSeederが作成する admin@example.com は entra_user_id がランダムなUUIDのため
     * (UserFactory参照)、そのままではモックOIDCでログインできない。mock-oidc/server.js の
     * 'mock-entra-admin' エントリと対応させ、管理者としてもシナリオを実施できるようにする。
     *
     * LinkSsoAccountコマンドはentra_user_id未設定のユーザーにしか使えない(UC-004、
     * 既に連携済みアカウントの上書きは業務上想定しない)ため、ここだけはテスト環境専用の
     * 割り切りとして直接更新する。
     */
    private function linkAdminToMockOidc(): User
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $admin->update(['entra_user_id' => 'mock-entra-admin']);

        return $admin->refresh();
    }

    private function seedCalendar(CommandBus $commandBus, string $adminId, Carbon $month, Carbon $from, Carbon $to): WorkCalendar
    {
        $calendar = WorkCalendar::query()->where('fiscal_year', $month->year)->first();

        if ($calendar === null) {
            $calendar = $commandBus->dispatch(new CreateWorkCalendar(
                name: "{$month->year}年度 シナリオテスト用カレンダー",
                fiscalYear: $month->year,
                startsOn: $from->toDateString(),
                endsOn: $to->toDateString(),
                weekStartsOn: 1,
                createdByUserId: $adminId,
            ));
        }

        // 土日を法定休日、それ以外を平日として簡易的に登録する(実運用では祝日マスタ等と
        // 突き合わせて個別設定するが、シナリオテスト用の最小データとして割り切る)。
        $days = [];
        $period = $from->copy()->toPeriod($to);
        foreach ($period as $date) {
            $isWeekend = $date->isWeekend();

            $days[] = [
                'date' => $date->toDateString(),
                'day_type' => $isWeekend ? 'legal_holiday' : 'weekday',
                'is_working_day' => ! $isWeekend,
                'is_legal_holiday' => $isWeekend,
                'is_company_holiday' => false,
            ];
        }

        $commandBus->dispatch(new UpdateWorkCalendarDays(
            workCalendarId: $calendar->id,
            days: $days,
            updatedByUserId: $adminId,
        ));

        if ($calendar->status !== 'published') {
            $commandBus->dispatch(new PublishWorkCalendar(
                workCalendarId: $calendar->id,
                publishedByUserId: $adminId,
            ));
        }

        return $calendar->refresh();
    }

    private function seedWorkStyle(CommandBus $commandBus, string $adminId, string $code, string $name, WorkCalendar $calendar): WorkStyle
    {
        $existing = WorkStyle::query()->where('code', $code)->first();

        if ($existing !== null) {
            return $existing;
        }

        $employmentCategory = EmploymentCategory::query()->firstOrCreate(
            ['code' => EmploymentCategory::REGULAR],
            ['name' => '正社員'],
        );

        return $commandBus->dispatch(new CreateWorkStyle(
            attributes: [
                'code' => $code,
                'name' => $name,
                'employment_category_id' => $employmentCategory->id,
                'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 2400,
                'default_start_time' => '09:00',
                'default_end_time' => '18:00',
                'default_break_minutes' => 60,
                'calendar_id' => $calendar->id,
                'is_shift_based' => false,
            ],
            createdByUserId: $adminId,
        ));
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
                'roles' => [Role::EMPLOYEE, Role::BACKOFFICE_STAFF],
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
            $existing = User::query()->where('entra_user_id', $definition['entra_user_id'])->first();
            $userId = $existing?->id ?? (string) Str::uuid();

            // department/job_titleは実運用ではMS365同期(UserSyncedFromMs365)でのみ設定される
            // ため、シナリオテスト用ユーザーもMS365同期を経由したものとして同じ集約イベントで
            // 作成する(SyncUsersFromMs365Handlerの1ユーザー分の処理と同じ経路)。
            UserAggregate::retrieve($userId)
                ->syncFromMs365(
                    entraUserId: $definition['entra_user_id'],
                    name: $definition['name'],
                    email: $definition['email'],
                    department: $definition['department'],
                    jobTitle: $definition['job_title'],
                    employmentStatus: 'active',
                )
                ->persist();

            foreach ($definition['roles'] as $roleCode) {
                $roleId = Role::query()->where('code', $roleCode)->value('id');
                $scopeType = match ($roleCode) {
                    Role::EMPLOYEE => 'self',
                    Role::BACKOFFICE_STAFF => 'approval_task',
                    default => 'global',
                };
                DB::table('role_assignments')->updateOrInsert(
                    ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "scenario-user-role-assignment:{$userId}:{$roleCode}")->toString()],
                    [
                        'subject_type' => 'user',
                        'subject_id' => $userId,
                        'role_id' => $roleId,
                        'scope_type' => $scopeType,
                        'status' => 'active',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            $standardGroupCodes = ['ALL_USERS'];
            if (in_array(Role::HR_STAFF, $definition['roles'], true)) {
                $standardGroupCodes[] = 'HUMAN_RESOURCES_USERS';
            }
            if (array_intersect($definition['roles'], [Role::BACKOFFICE_STAFF, Role::ACCOUNTING_STAFF, Role::GENERAL_AFFAIRS_STAFF, Role::HR_STAFF, Role::ADMIN]) !== []) {
                $standardGroupCodes[] = 'BACKOFFICE_USERS';
            }
            if (in_array(Role::ADMIN, $definition['roles'], true)) {
                $standardGroupCodes[] = 'SYSTEM_ADMINISTRATORS';
            }
            app(StandardGroupMembershipRecorder::class)->add($userId, $standardGroupCodes, $userId);

            $commandBus->dispatch(new SetUserHireDate(
                userId: $userId,
                hireDate: $definition['hire_date'],
                changedByUserId: $userId,
            ));

            $users[$key] = User::query()->findOrFail($userId);
        }

        return $users;
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
