<?php

namespace Tests\Feature\Support;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Commands\UpdateUserDetails;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

class RecordingCommandBusTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    public function test_top_level_and_nested_commands_are_recorded_separately(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $target = User::factory()->create(['name' => '変更前', 'hire_date' => '2024-04-01']);

        $bus = $this->recordCommands();

        $this->actingAs($hr)->putJson("/api/users/{$target->id}/details", [
            'profile' => ['name' => '変更後'],
            'hire_date' => '2024-04-02',
            'usage_start_date' => '2024-05-01',
        ])->assertOk();

        // 入口が直接dispatchするのは UpdateUserDetails の1件。全体は同期Reactorの連鎖の分を含めて1件より多い。
        $this->assertSingleTopLevelCommand($bus, UpdateUserDetails::class);
        $this->assertGreaterThan(1, count($bus->allCommands()));
        $this->assertContains(UpdateUserDetails::class, array_map(fn ($c) => $c::class, $bus->allCommands()));
    }

    public function test_depth_is_restored_after_a_handler_throws(): void
    {
        config()->set('domain.command_handlers.'.RecordingBusFailingCommand::class, RecordingBusFailingHandler::class);
        config()->set('domain.command_handlers.'.RecordingBusOkCommand::class, RecordingBusOkHandler::class);
        $bus = $this->recordCommands();

        try {
            $bus->dispatch(new RecordingBusFailingCommand);
            $this->fail('例外が投げられるはず');
        } catch (RuntimeException) {
        }
        $bus->dispatch(new RecordingBusOkCommand);

        $this->assertSame(
            [RecordingBusFailingCommand::class, RecordingBusOkCommand::class],
            array_map(fn ($c) => $c::class, $bus->topLevelCommands()),
        );
        $this->assertSingleTopLevelCommandCount(2, $bus);
    }

    private function assertSingleTopLevelCommandCount(int $expected, $bus): void
    {
        $this->assertCount($expected, $bus->allCommands());
    }
}

class RecordingBusFailingCommand implements Command {}
class RecordingBusOkCommand implements Command {}

class RecordingBusFailingHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        throw new RuntimeException('failed');
    }
}

class RecordingBusOkHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        return null;
    }
}
