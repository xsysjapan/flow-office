<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 休暇の stored_events の作り直しコマンド(leave:rebuild-event-store)の試し実行・--apply・--swap の検証。
 * 入力は旧系統の生の行を stored_events に直接 insert する(旧クラスには依存しない)。
 */
class LeaveEventStoreRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertOldPaidLeaveHistory();
    }

    public function test_trial_run_writes_nothing_and_succeeds(): void
    {
        $before = DB::table('stored_events')->count();

        $this->artisan('leave:rebuild-event-store')->assertExitCode(0);

        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
        $this->assertSame($before, DB::table('stored_events')->count());
    }

    public function test_apply_writes_the_rebuilt_table_in_the_new_structure(): void
    {
        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);

        $rebuilt = DB::table(EventStoreRebuildWriter::TABLE)->orderBy('id')->get();
        $classes = $rebuilt->pluck('event_class')->all();

        $this->assertSame(range(1, $rebuilt->count()), $rebuilt->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertContains('paid_leave_request.requested', $classes);
        $this->assertContains('paid_leave_account.usage_confirmed', $classes);
        $this->assertNotContains('paid_leave.requested', $classes);
        $this->assertNotContains('paid_leave.used', $classes);
    }

    public function test_swap_replaces_stored_events_and_keeps_the_original_table(): void
    {
        $originalCount = DB::table('stored_events')->count();
        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);

        $this->artisan('leave:rebuild-event-store', ['--swap' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('stored_events')->where('event_class', 'paid_leave.requested')->count());
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'paid_leave_request.requested')->count());
        $backups = collect(Schema::getTableListing(schemaQualified: false))->filter(fn (string $t) => str_starts_with($t, 'stored_events_before_rebuild_'));
        $this->assertCount(1, $backups);
        $this->assertSame($originalCount, DB::table($backups->first())->count());
    }

    public function test_apply_fails_when_the_rebuilt_table_already_exists(): void
    {
        Schema::create(EventStoreRebuildWriter::TABLE, fn ($table) => $table->id());

        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(1);
    }

    public function test_trial_fails_on_history_that_cannot_be_converted(): void
    {
        DB::table('stored_events')->insert([
            'id' => 900,
            'aggregate_uuid' => 'special-grant-1',
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => 'attendance_day.corrected',
            'event_properties' => json_encode(['userId' => 'u1']),
            'meta_data' => json_encode([]),
            'created_at' => '2026-08-01 10:00:00',
        ]);

        $this->artisan('leave:rebuild-event-store')->assertExitCode(1);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
    }

    private function insertOldPaidLeaveHistory(): void
    {
        $at = '2026-08-01 10:00:00';
        $rows = [
            [1, 'D1', 1, 'attendance_day.created', ['userId' => 'u1', 'workDate' => '2026-08-10', 'source' => 'manual', 'workType' => null], []],
            [2, 'G1', 1, 'paid_leave.granted', ['userId' => 'u1', 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null], []],
            [3, 'R1', 1, 'paid_leave.requested', ['userId' => 'u1', 'targetDate' => '2026-08-10', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null], []],
            [4, 'R1', 2, 'paid_leave.request_shared', ['workflowRequestId' => 'W1'], []],
            [5, 'W1', 1, 'workflow_request.drafted', ['applicantUserId' => 'u1'], []],
            [6, 'R1', 3, 'paid_leave.request_approved', ['approvedByUserId' => 'a1'], []],
            [7, 'G1', 2, 'paid_leave.used', ['userId' => 'u1', 'paidLeaveRequestId' => 'R1', 'attendanceDayId' => 'day-1', 'usedOn' => '2026-08-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full'], []],
            [8, 'D1', 2, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 480], 'userId' => 'u1'], []],
            [9, 'u1', 1, 'paid_leave_account.migrated', [
                'cutoverDate' => '2026-08-01',
                'grants' => [
                    ['grantId' => 'G2', 'originalGrantedOn' => null, 'originalGrantedDays' => 5.0, 'remainingDaysAtCutover' => 5.0, 'expiresOn' => '2027-03-31', 'source' => 'migration', 'cutoverMetadata' => ['mode' => 'carry', 'notes' => null]],
                ],
            ], []],
        ];

        foreach ($rows as [$id, $aggregate, $version, $class, $payload, $meta]) {
            DB::table('stored_events')->insert([
                'id' => $id,
                'aggregate_uuid' => $aggregate,
                'aggregate_version' => $version,
                'event_version' => 1,
                'event_class' => $class,
                'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'meta_data' => json_encode($meta),
                'created_at' => $at,
            ]);
        }
    }
}
