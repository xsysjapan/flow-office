<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Domain\CompensatoryLeaveAccount\Commands\GrantCompensatoryLeaveToUsers;
use App\Models\CompensatoryLeaveGrant;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 代休の手動付与の一括(POST /compensatory-leave/grants/bulk)。個人の指定だけ(グループの指定は無い)。
 * 入口1回=1つのCommand、全部か無しか、同じrequest_idの再送の冪等。docs/changesets/20261128-bulk-operations/spec.md 論点3。
 */
class CompensatoryLeaveManualBulkGrantTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use CompensatoryLeaveTestHelpers;
    use RefreshDatabase;

    private function hr(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    private function workedHoliday(User $user, $workStyle): void
    {
        $this->makeCompensatoryHolidayShift($user, $workStyle, '2026-08-08');
        $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id, 'work_date' => '2026-08-08',
            'actual_start_at' => '2026-08-08T09:00:00+09:00', 'actual_end_at' => '2026-08-08T17:00:00+09:00',
            'breaks' => [], 'reason' => 'テスト勤務',
        ])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function body(array $userIds, array $extra = []): array
    {
        return $extra + ['request_id' => (string) Str::uuid(), 'user_ids' => $userIds, 'work_date' => '2026-08-08', 'grant_reason' => '一括付与'];
    }

    private function manualGrantCount(User $user): int
    {
        return CompensatoryLeaveGrant::query()->where('user_id', $user->id)->where('source', 'manual')->count();
    }

    public function test_grants_to_every_user_with_a_single_command_and_resend_is_idempotent(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);
        $hr = $this->hr();
        $workStyle = $this->makeCompensatoryWorkStyle();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->workedHoliday($a, $workStyle);
        $this->workedHoliday($b, $workStyle);
        $body = $this->body([$a->id, $b->id]);

        $bus = $this->recordCommands();
        $response = $this->actingAs($hr)->postJson('/api/compensatory-leave/grants/bulk', $body);

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($response->json('data'), 'user_id'));
        $this->assertSingleTopLevelCommand($bus, GrantCompensatoryLeaveToUsers::class);
        $this->assertSame(1, $this->manualGrantCount($a));
        $this->assertSame(1, $this->manualGrantCount($b));

        // 同じrequest_idの再送は、二重にならず成功する。
        $eventCount = DB::table('stored_events')->count();
        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants/bulk', $body)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($eventCount, DB::table('stored_events')->count());
        $this->assertSame(1, $this->manualGrantCount($a));
    }

    public function test_failures_record_nothing_and_422_lists_every_failed_user_with_the_name(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);
        $hr = $this->hr();
        $workStyle = $this->makeCompensatoryWorkStyle();
        $ok = User::factory()->create();
        $this->workedHoliday($ok, $workStyle);
        $noWorkA = User::factory()->create(['name' => '休日出勤なしA']);
        $noWorkB = User::factory()->create(['name' => '休日出勤なしB']);
        $before = DB::table('stored_events')->count();

        $response = $this->actingAs($hr)->postJson('/api/compensatory-leave/grants/bulk', $this->body([$ok->id, $noWorkA->id, $noWorkB->id]));

        $response->assertUnprocessable();
        $response->assertJsonPath('code', 'bulk_operation_rejected');
        $this->assertEqualsCanonicalizing([$noWorkA->id, $noWorkB->id], array_column($response->json('items'), 'id'));
        $this->assertEqualsCanonicalizing(['休日出勤なしA', '休日出勤なしB'], array_column($response->json('items'), 'label'));
        $this->assertNotEmpty($response->json('errors')["items.{$noWorkA->id}"]);
        $this->assertSame($before, DB::table('stored_events')->count());
        $this->assertSame(0, $this->manualGrantCount($ok));
    }

    public function test_limit_distinct_and_group_id_are_rejected(): void
    {
        $hr = $this->hr();
        $user = User::factory()->create();

        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants/bulk', $this->body([$user->id, $user->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids.0', 'user_ids.1']);
        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants/bulk', $this->body(array_map(fn () => (string) Str::uuid(), range(1, 501))))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids']);
        // 代休はグループの指定を受けない(user_idsが必須)。
        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants/bulk', ['request_id' => (string) Str::uuid(), 'group_id' => (string) Str::uuid(), 'work_date' => '2026-08-08'])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_ids']);
    }

    public function test_single_grant_with_request_id_is_idempotent(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);
        $hr = $this->hr();
        $workStyle = $this->makeCompensatoryWorkStyle();
        $user = User::factory()->create();
        $this->workedHoliday($user, $workStyle);
        $payload = ['user_id' => $user->id, 'work_date' => '2026-08-08', 'request_id' => (string) Str::uuid()];

        $first = $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', $payload)->assertCreated()->json('id');
        $second = $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', $payload)->assertCreated()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->manualGrantCount($user));
    }

    public function test_employee_without_leave_manage_is_forbidden(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/compensatory-leave/grants/bulk', $this->body([$employee->id]))->assertForbidden();
    }
}
