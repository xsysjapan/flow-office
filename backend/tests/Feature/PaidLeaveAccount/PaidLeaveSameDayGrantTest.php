<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 同じ社員に同じ付与日の付与を複数記録できる(docs/changesets/20261021-paid-leave-same-day-grants/spec.md)。
 */
class PaidLeaveSameDayGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_grant_api_accepts_a_second_grant_on_the_same_day(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        foreach ([10, 11] as $days) {
            $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
                'user_id' => $employee->id,
                'granted_on' => '2026-04-01',
                'expires_on' => '2028-03-31',
                'granted_days' => $days,
            ])->assertCreated();
        }

        $this->assertSame(2, PaidLeaveGrant::query()->where('user_id', $employee->id)->whereDate('granted_on', '2026-04-01')->count());

        // 過去の日付(最新の付与日より前)は今までどおり拒否される。
        $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => '2026-03-31',
            'expires_on' => '2028-03-31',
            'granted_days' => 5,
        ])->assertUnprocessable();
    }

    public function test_grant_command_uses_the_given_grant_id_and_rejects_the_same_id_again(): void
    {
        $employee = User::factory()->create();
        $grantId = '6f1c7c0e-3b0e-5a43-9d9a-0d6b1b1f2a10';

        $returned = app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-04-01', '2028-03-31', 10.0, null, 'auto', $grantId));

        $this->assertSame($grantId, $returned);
        $this->assertEquals(10.0, (float) PaidLeaveGrant::query()->findOrFail($grantId)->granted_days);

        try {
            app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-04-01', '2028-03-31', 10.0, null, 'auto', $grantId));
            $this->fail('同じ付与IDの付与は拒否される。');
        } catch (DomainRuleException) {
        }

        $this->assertSame(1, PaidLeaveGrant::query()->where('user_id', $employee->id)->count());
    }
}
