<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\MigratePaidLeaveAccount;
use App\Models\PaidLeaveBalance;
use App\Models\PaidLeaveGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 最終Phase(データ移行)。`MigratePaidLeaveAccount` Command→Handler→Aggregate→Event→
 * Projectorの一連のパイプラインを、CommandBus経由(Feature test)で検証する
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md「既存データ移行」参照)。
 */
class PaidLeaveAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    public function test_migration_creates_grant_row_marked_as_migration_source(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new MigratePaidLeaveAccount(
            userId: $user->id,
            cutoverDate: '2026-04-01',
            grants: [
                [
                    'originalGrantedOn' => '2025-04-01',
                    'originalGrantedDays' => 10.0,
                    'remainingDaysAtCutover' => 6.0,
                    'expiresOn' => '2027-04-01',
                    'mode' => 'A',
                    'notes' => '旧システムGrant#1',
                ],
            ],
        ));

        $grant = PaidLeaveGrant::query()->where('user_id', $user->id)->sole();

        // 監査上、通常のGrantPaidLeave経由(source='manual')とは区別できる。
        $this->assertEquals('migration', $grant->source);
        $this->assertEquals(6.0, (float) $grant->granted_days);
        $this->assertEquals(6.0, (float) $grant->remaining_days);
        $this->assertEquals(10.0, (float) $grant->original_granted_days);
        $this->assertEquals('A', $grant->cutover_metadata['mode']);
        $this->assertEquals('旧システムGrant#1', $grant->cutover_metadata['notes']);

        $balance = PaidLeaveBalance::query()->findOrFail($user->id);
        $this->assertEquals(6.0, (float) $balance->available_days);
    }

    public function test_migration_mode_c_grant_row_has_null_original_granted_days(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new MigratePaidLeaveAccount(
            userId: $user->id,
            cutoverDate: '2026-04-01',
            grants: [
                [
                    'originalGrantedOn' => null,
                    'originalGrantedDays' => null,
                    'remainingDaysAtCutover' => 4.0,
                    'expiresOn' => '2027-04-01',
                    'mode' => 'C',
                ],
            ],
        ));

        $grant = PaidLeaveGrant::query()->where('user_id', $user->id)->sole();

        $this->assertNull($grant->original_granted_days);
        $this->assertEquals('migration', $grant->source);
        $this->assertEquals(4.0, (float) $grant->granted_days);
    }

    public function test_normal_grant_and_usage_confirm_work_correctly_after_migration(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new MigratePaidLeaveAccount(
            userId: $user->id,
            cutoverDate: '2026-04-01',
            grants: [
                [
                    'originalGrantedOn' => '2025-04-01',
                    'originalGrantedDays' => 10.0,
                    'remainingDaysAtCutover' => 6.0,
                    'expiresOn' => '2027-04-01',
                    'mode' => 'A',
                ],
            ],
        ));

        // 移行後の通常GrantPaidLeaveは、移行Grantの日付より後であれば成功する。
        $grantId = $this->bus()->dispatch(new GrantPaidLeave(
            userId: $user->id,
            grantedOn: '2026-04-02',
            expiresOn: '2028-04-02',
            grantedDays: 11.0,
            grantReason: null,
        ));
        $this->assertNotEmpty($grantId);

        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id,
            workflowRequestId: null,
            attendanceDayId: null,
            usedOn: '2026-05-01',
            usedDays: 6.0,
            usageType: 'full',
        ));
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: null));

        // 移行Grant(6.0)へ全量充当される(通常のAllocationPlannerルール通り)。
        $migratedGrant = PaidLeaveGrant::query()->where('user_id', $user->id)->where('source', 'migration')->sole();
        $this->assertEquals(6.0, (float) $migratedGrant->allocated_days);
        $this->assertEquals(0.0, (float) $migratedGrant->remaining_days);
    }

    public function test_migration_is_rejected_on_second_call_for_same_user(): void
    {
        $user = User::factory()->create();

        $this->bus()->dispatch(new MigratePaidLeaveAccount(
            userId: $user->id,
            cutoverDate: '2026-04-01',
            grants: [
                [
                    'originalGrantedOn' => null,
                    'originalGrantedDays' => null,
                    'remainingDaysAtCutover' => 1.0,
                    'expiresOn' => '2027-04-01',
                    'mode' => 'C',
                ],
            ],
        ));

        $this->expectException(DomainRuleException::class);

        $this->bus()->dispatch(new MigratePaidLeaveAccount(
            userId: $user->id,
            cutoverDate: '2026-04-01',
            grants: [
                [
                    'originalGrantedOn' => null,
                    'originalGrantedDays' => null,
                    'remainingDaysAtCutover' => 2.0,
                    'expiresOn' => '2027-04-01',
                    'mode' => 'C',
                ],
            ],
        ));
    }
}
