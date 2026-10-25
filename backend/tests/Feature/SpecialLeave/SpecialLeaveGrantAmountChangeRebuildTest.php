<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\SpecialLeaveAccount\Commands\ChangeSpecialLeaveGrantAmount;
use App\Domain\SpecialLeaveAccount\Commands\ConfirmSpecialLeaveUsage;
use App\Domain\SpecialLeaveAccount\Commands\DesignateSpecialLeaveUsage;
use App\Domain\SpecialLeaveAccount\Commands\RegisterSpecialLeaveGrant;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 特別休暇の付与日数の変更後に、ReadModelを空にして再生(作り直し)しても同じ値になること。
 * 実際に全テーブルを空にして再生するので`DatabaseMigrations`(後始末はReadModelRebuilderTestと同じ)。
 */
class SpecialLeaveGrantAmountChangeRebuildTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->runRebuildDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRebuildHeartbeat();
        $this->seedAccessControl();
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    public function test_the_changed_amount_and_remaining_days_are_the_same_after_a_rebuild(): void
    {
        $admin = $this->createEventAdmin();
        $employee = $this->createEventUser('Employee S');
        $type = SpecialLeaveType::query()->create(['name' => '慶弔休暇', 'is_active' => true, 'requires_grant' => true]);
        $bus = app(CommandBus::class);
        $grantId = (string) Str::uuid();
        $bus->dispatch(new RegisterSpecialLeaveGrant((string) $employee->id, $grantId, $type->id, '2026-07-01', null, 5.0, null));

        $requestId = (string) Str::uuid();
        $bus->dispatch(new DesignateSpecialLeaveUsage((string) $employee->id, $requestId, $type->id, '2026-08-10', 'full', 1.0, null));
        $bus->dispatch(new ConfirmSpecialLeaveUsage((string) $employee->id, $requestId, true));
        $bus->dispatch(new ChangeSpecialLeaveGrantAmount((string) $employee->id, $grantId, 8.0, (string) $admin->id, '訂正'));

        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(8.0, (float) $grant->granted_days);
        $this->assertEquals(7.0, (float) $grant->remaining_days);

        (new ReadModelRebuilder)->rebuild(false);

        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(8.0, (float) $grant->granted_days);
        $this->assertEquals(1.0, (float) $grant->used_days);
        $this->assertEquals(7.0, (float) $grant->remaining_days);
    }
}
