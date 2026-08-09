<?php

namespace Tests\Feature\UserManagement;

use App\Domain\EventSourcing\EventStore;
use App\Domain\UserManagement\Commands\SyncUsersFromMs365;
use App\Domain\UserManagement\Graph\MicrosoftGraphClient;
use App\Domain\UserManagement\Graph\MicrosoftGraphUser;
use App\Domain\UserManagement\Handlers\SyncUsersFromMs365Handler;
use App\Domain\UserManagement\SsoAuthenticator;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Tests\TestCase;

/**
 * 入社日の設定 (docs/09-usecases-paid-leave.md UC-P002 で使う継続勤務期間の基準日)。
 */
class UserHireDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_staff_can_set_a_users_hire_date(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($hr)->putJson("/api/users/{$employee->id}/hire-date", [
            'hire_date' => '2024-04-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('hire_date', '2024-04-01');
        $this->assertSame('2024-04-01', $employee->refresh()->hire_date->toDateString());
    }

    public function test_employee_cannot_set_a_hire_date(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->putJson("/api/users/{$other->id}/hire-date", [
            'hire_date' => '2024-04-01',
        ])->assertForbidden();
    }

    public function test_hr_staff_can_set_and_clear_a_users_termination_date(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create(['hire_date' => '2024-04-01']);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/termination-date", [
            'termination_date' => '2026-03-31',
        ])->assertOk()->assertJsonPath('termination_date', '2026-03-31');

        $this->assertSame('2026-03-31', $employee->refresh()->termination_date->toDateString());

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/termination-date", [
            'termination_date' => null,
        ])->assertOk()->assertJsonPath('termination_date', null);

        $this->assertNull($employee->refresh()->termination_date);
    }

    public function test_hire_and_termination_dates_must_form_a_valid_employment_period(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create(['hire_date' => '2024-04-01', 'termination_date' => '2026-03-31']);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/hire-date", [
            'hire_date' => '2026-04-01',
        ])->assertStatus(422);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/termination-date", [
            'termination_date' => '2024-03-31',
        ])->assertStatus(422);
    }

    public function test_hr_staff_can_set_a_users_usage_start_date(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($hr)->putJson("/api/users/{$employee->id}/usage-start-date", [
            'usage_start_date' => '2026-07-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('usage_start_date', '2026-07-01');
        $this->assertSame('2026-07-01', $employee->refresh()->usage_start_date->toDateString());
    }

    public function test_employee_cannot_set_a_usage_start_date(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->putJson("/api/users/{$other->id}/usage-start-date", [
            'usage_start_date' => '2026-07-01',
        ])->assertForbidden();
    }

    public function test_sso_first_login_defaults_usage_start_date_to_the_creation_date(): void
    {
        Role::query()->create(['code' => Role::EMPLOYEE, 'name' => '一般社員']);
        $authenticator = app(SsoAuthenticator::class);
        $ssoUser = $this->fakeSocialiteUser('entra-usd-1', 'テスト新人', 'shinjin@example.com');

        Carbon::setTestNow('2026-07-31 09:00:00');
        try {
            $user = $authenticator->handle($ssoUser);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotNull($user->usage_start_date);
        $this->assertSame('2026-07-31', $user->usage_start_date->toDateString());
    }

    public function test_ms365_sync_does_not_overwrite_an_existing_users_usage_start_date(): void
    {
        $user = User::factory()->create(['entra_user_id' => 'entra-usd-2', 'usage_start_date' => '2024-04-01']);

        $handler = new SyncUsersFromMs365Handler(
            new FakeMicrosoftGraphClientForUsageStartDateTest([
                new MicrosoftGraphUser('entra-usd-2', $user->name, $user->email, $user->department, $user->job_title, true),
            ]),
            app(EventStore::class),
        );

        $handler->handle(new SyncUsersFromMs365);

        $this->assertSame('2024-04-01', $user->refresh()->usage_start_date->toDateString());
    }

    private function fakeSocialiteUser(string $id, string $name, string $email): SocialiteUser
    {
        return new class($id, $name, $email) implements SocialiteUser
        {
            public function __construct(
                private readonly string $id,
                private readonly string $name,
                private readonly string $email,
            ) {}

            public function getId()
            {
                return $this->id;
            }

            public function getNickname()
            {
                return null;
            }

            public function getName()
            {
                return $this->name;
            }

            public function getEmail()
            {
                return $this->email;
            }

            public function getAvatar()
            {
                return null;
            }
        };
    }
}

class FakeMicrosoftGraphClientForUsageStartDateTest implements MicrosoftGraphClient
{
    /**
     * @param  array<int, MicrosoftGraphUser>  $users
     */
    public function __construct(private readonly array $users) {}

    public function listUsers(): iterable
    {
        return $this->users;
    }
}
