<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Migration\StoredEventHistoryNormalizer;
use App\Domain\EventSourcing\Migration\AttendanceBackOfficeTaskHistoryBackfiller;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class StoredEventHistoryNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_replaces_legacy_user_roles_with_registration_and_membership_history(): void
    {
        $registeredAt = '2026-07-20 09:00:00';
        $user = User::factory()->create([
            'entra_user_id' => 'entra-history-user',
            'name' => 'History User',
            'email' => 'history@example.test',
            'created_at' => $registeredAt,
        ]);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);

        $this->insertEvent($user->id, 1, 'user.migrated_from_legacy', [
            'attributes' => [
                'entra_user_id' => $user->entra_user_id,
                'name' => $user->name,
                'email' => $user->email,
                'employment_status' => 'active',
            ],
        ], $registeredAt);
        $this->insertEvent($user->id, 2, 'user.logged_in', [
            'wasFirstLogin' => true,
            'loggedInAt' => $registeredAt,
        ], $registeredAt);
        $this->insertEvent($user->id, 3, 'user.roles_migrated_from_legacy', [
            'roleCodes' => ['employee', 'admin'],
        ], '2026-08-02 09:00:00');

        $legacyExportId = (string) Str::uuid();
        DB::table('legacy_stored_events')->insert([
            'event_id' => (string) Str::uuid(),
            'aggregate_type' => 'export',
            'aggregate_id' => $legacyExportId,
            'version' => 1,
            'event_type' => 'export.created',
            'payload' => json_encode([
                'export_type' => 'attendance_csv', 'params' => [],
                'requested_by_user_id' => $user->id, 'row_count' => 1,
            ], JSON_THROW_ON_ERROR),
            'metadata' => '{}',
            'occurred_at' => '2026-08-03 09:00:00',
            'created_at' => '2026-08-03 09:00:00',
            'updated_at' => '2026-08-03 09:00:00',
        ]);

        $normalizer = app(StoredEventHistoryNormalizer::class);
        $before = $normalizer->inspect();
        $this->assertSame(2, $before['legacy_user_events']);

        $after = $normalizer->apply('stored_events_backup_test');

        $this->assertSame(0, $after['legacy_user_events']);
        $this->assertDatabaseCount('stored_events_backup_test', 3);
        $this->assertDatabaseHas('stored_events', [
            'aggregate_uuid' => $user->id,
            'aggregate_version' => 1,
            'event_class' => 'user.created_from_sso_login',
        ]);
        $this->assertDatabaseMissing('stored_events', ['event_class' => 'user.roles_migrated_from_legacy']);
        $this->assertDatabaseHas('stored_events', ['event_class' => 'export.created', 'aggregate_uuid' => $legacyExportId]);
        $this->assertDatabaseCount('legacy_stored_events', 0);

        $membershipStream = UserManagementStreamId::for('user-membership', $user->id);
        $this->assertSame(2, DB::table('stored_events')->where('aggregate_uuid', $membershipStream)
            ->where('event_class', 'membership.added')->count());
        $this->assertSame(2, DB::table('memberships')->where('user_id', $user->id)->count());

        EloquentStoredEvent::query()->orderBy('id')->each(fn (EloquentStoredEvent $event) => $event->toStoredEvent());
    }

    public function test_it_appends_the_current_back_office_task_event_after_historical_attendance_approval(): void
    {
        $approvedAt = '2026-08-04 09:00:00';
        $user = User::factory()->create(['name' => '履歴利用者']);
        $monthId = (string) Str::uuid();
        DB::table('attendance_months')->insert([
            'id' => $monthId,
            'user_id' => $user->id,
            'year_month' => '2026-07',
            'status' => 'approved',
            'approver_user_id' => $user->id,
            'submitted_at' => '2026-08-04 08:00:00',
            'approved_at' => $approvedAt,
            'created_at' => '2026-08-04 08:00:00',
            'updated_at' => $approvedAt,
        ]);
        $this->insertEvent($monthId, 1, 'attendance_month.approved', [
            'approvedByUserId' => $user->id,
        ], $approvedAt);

        $backfiller = app(AttendanceBackOfficeTaskHistoryBackfiller::class);
        $this->assertSame(1, $backfiller->countMissing());
        $this->assertSame(1, $backfiller->apply());
        $this->assertSame(0, $backfiller->apply());

        $task = DB::table('backoffice_tasks')->where('source_type', 'attendance_month')->where('source_id', $monthId)->first();
        $this->assertNotNull($task);
        $this->assertSame([
            'source_type' => 'attendance_month',
            'source_id' => $monthId,
            'task_type' => 'attendance_month_confirmation',
            'title' => '月次勤怠確認: 履歴利用者 (2026-07)',
        ], [
            'source_type' => $task->source_type,
            'source_id' => $task->source_id,
            'task_type' => $task->task_type,
            'title' => $task->title,
        ]);
        $this->assertSame('2026-08-11', \Illuminate\Support\Carbon::parse($task->due_on)->toDateString());
        $this->assertDatabaseHas('stored_events', [
            'event_class' => 'backoffice_task.created',
            'created_at' => $approvedAt,
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function insertEvent(string $aggregateUuid, int $version, string $eventClass, array $properties, string $createdAt): void
    {
        DB::table('stored_events')->insert([
            'aggregate_uuid' => $aggregateUuid,
            'aggregate_version' => $version,
            'event_version' => 1,
            'event_class' => $eventClass,
            'event_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregateUuid,
                'aggregate-root-version' => $version,
                'legacy_migration' => ['legacy_id' => $version, 'aggregate_type' => 'user'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ]);
    }
}
