<?php

namespace Tests\Feature\UserManagement;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use App\Models\User;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserRegistrationMembershipEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_sso_login_records_default_group_membership_as_an_event(): void
    {
        $this->seed(UserManagementSeeder::class);

        $user = app(CommandBus::class)->dispatch(new RecordSsoLogin(
            entraUserId: 'entra-new-user',
            name: 'New User',
            email: 'new-user@example.test',
        ));

        $allUsersGroupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'group_id' => $allUsersGroupId]);
        $this->assertDatabaseHas('stored_events', [
            'aggregate_uuid' => UserManagementStreamId::for('user-membership', $user->id),
            'event_class' => 'membership.added',
        ]);
        $this->assertSame(1, User::query()->where('entra_user_id', 'entra-new-user')->count());
    }
}
