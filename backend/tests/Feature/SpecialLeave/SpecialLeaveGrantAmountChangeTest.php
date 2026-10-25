<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeaveAccount\Commands\ConfirmSpecialLeaveUsage;
use App\Domain\SpecialLeaveAccount\Commands\DesignateSpecialLeaveUsage;
use App\Models\Role;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 管理者による特別休暇付与の付与日数の変更(PUT /special-leave/grants/{grant}/granted-days)。
 */
class SpecialLeaveGrantAmountChangeTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    private function grantFor(User $hr, User $employee): string
    {
        $type = SpecialLeaveType::query()->create(['name' => '慶弔休暇', 'is_active' => true, 'requires_grant' => true]);

        return $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $employee->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'granted_days' => 5,
            'grant_reason' => '慶弔',
        ])->assertCreated()->json('id');
    }

    private function useDays(User $employee, string $date, float $days): void
    {
        $requestId = (string) Str::uuid();
        $bus = app(CommandBus::class);
        $bus->dispatch(new DesignateSpecialLeaveUsage(
            userId: (string) $employee->id,
            requestId: $requestId,
            specialLeaveTypeId: (int) SpecialLeaveType::query()->value('id'),
            usedOn: $date,
            usageType: 'full',
            usedDays: $days,
            usedMinutes: null,
        ));
        $bus->dispatch(new ConfirmSpecialLeaveUsage(userId: (string) $employee->id, requestId: $requestId, requiresGrant: true));
    }

    private function amountEventCount(): int
    {
        return DB::table('stored_events')->where('event_class', 'special_leave_account.grant_amount_changed')->count();
    }

    public function test_the_grant_amount_can_be_increased_and_reduced(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);

        $response = $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 7, 'reason' => '訂正']);
        $response->assertOk();
        $this->assertEquals(7.0, $response->json('granted_days'));
        $this->assertEquals(7.0, $response->json('remaining_days'));
        $this->assertEquals(7.0, (float) SpecialLeaveGrant::query()->findOrFail($grantId)->remaining_days);

        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 2.5])->assertOk();
        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(2.5, (float) $grant->granted_days);
        $this->assertEquals(2.5, (float) $grant->remaining_days);
    }

    public function test_the_remaining_days_follow_the_used_days(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);
        $this->useDays($employee, '2026-08-10', 2.0);

        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 6])->assertOk();

        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(6.0, (float) $grant->granted_days);
        $this->assertEquals(2.0, (float) $grant->used_days);
        $this->assertEquals(4.0, (float) $grant->remaining_days);
    }

    public function test_reducing_below_the_used_days_is_rejected_and_changes_nothing(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);
        $this->useDays($employee, '2026-08-10', 2.0);

        $response = $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 1.5]);

        $response->assertStatus(422);
        $this->assertStringContainsString('使用済みの日数より少なくはできません。', (string) $response->json('message'));
        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(5.0, (float) $grant->granted_days);
        $this->assertEquals(3.0, (float) $grant->remaining_days);
        $this->assertSame(0, $this->amountEventCount());
    }

    public function test_a_revoked_grant_cannot_be_changed(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);
        $this->actingAs($hr)->postJson("/api/special-leave/grants/{$grantId}/revoke")->assertOk();

        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 7])->assertStatus(422);
        $this->assertEquals(5.0, (float) SpecialLeaveGrant::query()->findOrFail($grantId)->granted_days);
    }

    public function test_the_same_days_record_no_event_and_return_200(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);

        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 5])->assertOk();

        $this->assertSame(0, $this->amountEventCount());
    }

    public function test_a_user_without_leave_manage_gets_403(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);

        $this->actingAs($employee)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 7])->assertForbidden();
        $this->assertEquals(5.0, (float) SpecialLeaveGrant::query()->findOrFail($grantId)->granted_days);
    }

    public function test_less_than_half_a_day_is_a_validation_error(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);

        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 0.4])->assertStatus(422);
        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", [])->assertStatus(422);
    }

    public function test_the_change_event_appears_in_the_history(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantFor($hr, $employee);
        $this->actingAs($hr)->putJson("/api/special-leave/grants/{$grantId}/granted-days", ['granted_days' => 7, 'reason' => '訂正'])->assertOk();

        $history = $this->actingAs($hr)->getJson("/api/special-leave/history/user/{$employee->id}")->assertOk()->json();

        $types = array_column($history, 'event_type');
        $this->assertContains('special_leave_account.grant_amount_changed', $types);
    }
}
