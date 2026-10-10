<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\CompensatoryLeaveGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代休の移行コマンド(compensatory-leave:migrate-to-account)のテスト。本変更前の付与(旧テーブル)が
 * 利用者単位の代休口座へ引き継がれ、既定の試し実行では何も変わらず、再実行しても二重に引き継がないことを確かめる。
 */
class CompensatoryLeaveAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function legacyGrant(User $user, string $workDate = '2026-08-08'): string
    {
        $grantId = (string) Str::uuid();

        CompensatoryLeaveGrant::query()->create([
            'id' => $grantId,
            'user_id' => $user->id,
            'source' => 'manual',
            'attendance_day_id' => null,
            'work_date' => $workDate,
            'granted_days' => 1.0,
            'granted_minutes' => null,
            'used_days' => 0,
            'used_minutes' => null,
            'remaining_days' => 1.0,
            'remaining_minutes' => null,
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'expires_on' => null,
            'grant_reason' => null,
        ]);

        return $grantId;
    }

    public function test_dry_run_changes_nothing_and_apply_moves_the_legacy_grant_into_the_account_once(): void
    {
        $employee = User::factory()->create();
        $grantId = $this->legacyGrant($employee);

        $this->artisan('compensatory-leave:migrate-to-account')->assertSuccessful();
        $this->assertFalse($this->compensatoryAccountOf($employee)->isMigrated());

        $this->artisan('compensatory-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();

        $account = $this->compensatoryAccountOf($employee);
        $this->assertTrue($account->isMigrated());
        $this->assertSame('confirmed', $account->grantStatus($grantId));
        $this->assertEquals(1.0, (float) CompensatoryLeaveGrant::query()->whereKey($grantId)->value('remaining_days'));

        // 再実行は移行済みの利用者として対象外(二重に引き継がない)。
        $this->artisan('compensatory-leave:migrate-to-account', ['--apply' => true])->assertSuccessful();
        $this->assertTrue($this->compensatoryAccountOf($employee)->isMigrated());
    }
}
