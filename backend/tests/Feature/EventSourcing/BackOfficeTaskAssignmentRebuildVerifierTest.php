<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentRebuildVerifier;
use App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentSplitter;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 作り直し後の表の検査(旧形式の残存・backoffice_task 以外の行の改変の検出)。
 */
class BackOfficeTaskAssignmentRebuildVerifierTest extends TestCase
{
    use RefreshDatabase;

    private int $id = 0;

    /** @var array<string, int> */
    private array $versions = [];

    private function seedAndRebuild(): void
    {
        $this->insertRaw('wf-1', 'workflow_request.submitted', ['approverUserId' => 'a', 'submittedByUserId' => 'b']);
        $this->insertRaw('t-1', 'backoffice_task.created', ['sourceType' => 'workflow_request', 'sourceId' => 'wf-1', 'taskType' => 'general', 'title' => 't', 'assignedDepartment' => null, 'dueOn' => null]);
        $this->insertRaw('t-1', 'backoffice_task.assigned', ['assignedUserId' => 'u1', 'assignedByUserId' => 'u2']);
        $this->insertRaw('wf-1', 'workflow_request.approved', ['approvedByUserId' => 'a']);

        $writer = new EventStoreRebuildWriter;
        $result = (new BackOfficeTaskAssignmentSplitter)->split($writer->readRowArrays());
        $this->assertSame(1, $result->targetCount);
        $writer->write($result->rows);
    }

    public function test_a_correct_rebuild_passes(): void
    {
        $this->seedAndRebuild();

        $this->assertSame([], (new BackOfficeTaskAssignmentRebuildVerifier)->verify());
    }

    public function test_remaining_legacy_assigned_is_detected(): void
    {
        $this->seedAndRebuild();
        DB::table(EventStoreRebuildWriter::TABLE)->where('event_class', 'backoffice_task.assigned')
            ->update(['event_properties' => '{"assignedUserId":"u1","assignedByUserId":"u2"}']);

        $problems = (new BackOfficeTaskAssignmentRebuildVerifier)->verify();

        $this->assertNotEmpty(array_filter($problems, fn (string $p) => str_starts_with($p, '(2)')));
    }

    public function test_changed_rows_of_other_aggregates_are_detected(): void
    {
        $this->seedAndRebuild();
        DB::table(EventStoreRebuildWriter::TABLE)->where('event_class', 'workflow_request.approved')
            ->update(['event_properties' => '{"approvedByUserId":"tampered"}']);

        $problems = (new BackOfficeTaskAssignmentRebuildVerifier)->verify();

        $this->assertNotEmpty(array_filter($problems, fn (string $p) => str_starts_with($p, '(3)')));
    }

    public function test_missing_rebuilt_table_is_reported(): void
    {
        $this->assertNotEmpty((new BackOfficeTaskAssignmentRebuildVerifier)->verify());
    }

    /** @param  array<string, mixed>  $payload */
    private function insertRaw(string $aggregate, string $class, array $payload): void
    {
        $this->id++;
        $this->versions[$aggregate] = ($this->versions[$aggregate] ?? 0) + 1;
        DB::table('stored_events')->insert([
            'id' => $this->id, 'aggregate_uuid' => $aggregate, 'aggregate_version' => $this->versions[$aggregate], 'event_version' => 1,
            'event_class' => $class, 'event_properties' => json_encode($payload),
            'meta_data' => json_encode(['aggregate-root-uuid' => $aggregate, 'aggregate-root-version' => $this->versions[$aggregate], 'stored-event-id' => $this->id, 'created-at' => '2026-09-01 10:00:00']),
            'created_at' => '2026-09-01 10:00:00',
        ]);
    }
}
