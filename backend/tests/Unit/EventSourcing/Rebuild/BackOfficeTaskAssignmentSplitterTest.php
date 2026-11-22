<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentSplitter;
use PHPUnit\Framework\TestCase;

/**
 * 旧形式の backoffice_task.assigned の作り直し(純粋な変換)の規則。DBは使わない。
 * 期待値の根拠: 旧 BackOfficeTaskProjector::onBackOfficeTaskAssigned は assigned_user_id を設定し status を常に in_review にしていた。
 */
class BackOfficeTaskAssignmentSplitterTest extends TestCase
{
    private const T = 'task-1';

    private BackOfficeTaskAssignmentSplitter $splitter;

    private int $id = 0;

    /** @var array<string, int> */
    private array $versions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new BackOfficeTaskAssignmentSplitter;
    }

    public function test_assigned_after_created_gets_previous_null_and_a_status_changed(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', ['title' => 't']),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M'], '2026-09-02 09:00:00'),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame([], $result->violations);
        $this->assertSame(1, $result->targetCount);
        $this->assertSame(1, $result->insertedStatusChanged);
        $this->assertSame(['not_started' => 1], $result->previousStatusCounts);
        $this->assertSame([1, 2, 3], array_column($result->rows, 'id'));
        $this->assertSame([1, 2, 3], array_column($result->rows, 'aggregate_version'));
        $this->assertSame(
            ['backoffice_task.created', 'backoffice_task.assigned', 'backoffice_task.status_changed'],
            array_column($result->rows, 'event_class'),
        );
        $this->assertSame(['assignedUserId' => 'A', 'assignedByUserId' => 'M', 'previousAssignedUserId' => null], $result->rows[1]['event_properties']);
        $this->assertSame(
            ['previousStatus' => 'not_started', 'newStatus' => 'in_review', 'changedByUserId' => 'M', 'comment' => null],
            $result->rows[2]['event_properties'],
        );

        $inserted = $result->rows[2];
        $this->assertSame(self::T, $inserted['aggregate_uuid']);
        $this->assertSame(1, $inserted['event_version']);
        $this->assertSame('2026-09-02 09:00:00', $inserted['created_at']);
        $this->assertSame(self::T, $inserted['meta_data']['aggregate-root-uuid']);
        $this->assertSame('2026-09-02 09:00:00', $inserted['meta_data']['created-at']);
        $this->assertSame(3, $inserted['meta_data']['stored-event-id']);
        $this->assertSame(3, $inserted['meta_data']['aggregate-root-version']);
        $this->assertSame(2, $result->rows[1]['meta_data']['stored-event-id']);
        $this->assertSame(2, $result->rows[1]['meta_data']['aggregate-root-version']);
    }

    public function test_reassign_while_in_review_inserts_no_status_changed(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'B', 'assignedByUserId' => 'M']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame(2, $result->targetCount);
        $this->assertSame(1, $result->insertedStatusChanged);
        $this->assertSame(['not_started' => 1, 'in_review' => 1], $result->previousStatusCounts);
        $this->assertSame(
            ['backoffice_task.created', 'backoffice_task.assigned', 'backoffice_task.status_changed', 'backoffice_task.assigned'],
            array_column($result->rows, 'event_class'),
        );
        $this->assertSame('A', $result->rows[3]['event_properties']['previousAssignedUserId']);
        $this->assertSame('B', $result->rows[3]['event_properties']['assignedUserId']);
    }

    public function test_assigned_while_processing_restores_in_review_like_the_old_projector(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row(self::T, 'backoffice_task.status_changed', ['previousStatus' => 'in_review', 'newStatus' => 'processing', 'changedByUserId' => 'A', 'comment' => null]),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'B', 'assignedByUserId' => 'N']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame(['not_started' => 1, 'processing' => 1], $result->previousStatusCounts);
        $this->assertSame(2, $result->insertedStatusChanged);
        $classes = array_column($result->rows, 'event_class');
        $this->assertSame('backoffice_task.status_changed', $classes[5]);
        $this->assertSame(['previousStatus' => 'processing', 'newStatus' => 'in_review', 'changedByUserId' => 'N', 'comment' => null], $result->rows[5]['event_properties']);
        $this->assertSame('A', $result->rows[4]['event_properties']['previousAssignedUserId']);
        $this->assertSame(range(1, 6), array_column($result->rows, 'aggregate_version'));
    }

    public function test_assigned_after_status_change_from_not_started(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.status_changed', ['previousStatus' => 'not_started', 'newStatus' => 'processing', 'changedByUserId' => 'A', 'comment' => null]),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame(['processing' => 1], $result->previousStatusCounts);
        $this->assertSame('processing', $result->rows[3]['event_properties']['previousStatus']);
        $this->assertSame('in_review', $result->rows[3]['event_properties']['newStatus']);
        $this->assertNull($result->rows[2]['event_properties']['previousAssignedUserId']);
    }

    public function test_assigned_after_completed_is_reported_with_the_aggregate(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.completed', ['completedByUserId' => 'A', 'comment' => null]),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'B', 'assignedByUserId' => 'M']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame(['completed' => 1], $result->previousStatusCounts);
        $this->assertSame(['completed' => [self::T]], $result->terminalTransitions);
        $this->assertSame('completed', $result->rows[3]['event_properties']['previousStatus']);
        $this->assertSame('in_review', $result->rows[3]['event_properties']['newStatus']);
    }

    public function test_other_aggregates_are_unchanged_and_keep_relative_order(): void
    {
        $rows = [
            $this->row('wf-1', 'workflow_request.submitted', ['approverUserId' => 'x']),
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row('wf-1', 'workflow_request.approved', ['approvedByUserId' => 'x']),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row('wf-1', 'workflow_request.cancelled', ['reason' => 'r']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame(6, count($result->rows));
        $this->assertSame(
            ['workflow_request.submitted', 'backoffice_task.created', 'workflow_request.approved', 'backoffice_task.assigned', 'backoffice_task.status_changed', 'workflow_request.cancelled'],
            array_column($result->rows, 'event_class'),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($result->rows, 'id'));

        $wf = array_values(array_filter($result->rows, fn (array $r) => $r['aggregate_uuid'] === 'wf-1'));
        $this->assertSame([1, 2, 3], array_column($wf, 'aggregate_version'));
        foreach ([[$rows[0], $wf[0], 1], [$rows[2], $wf[1], 3], [$rows[4], $wf[2], 6]] as [$before, $after, $newId]) {
            $this->assertSame($before['event_properties'], $after['event_properties']);
            $this->assertSame($newId, $after['id']);
            $this->assertSame($newId, $after['meta_data']['stored-event-id']);
            $expectedMeta = $before['meta_data'];
            unset($expectedMeta['stored-event-id']);
            $actualMeta = $after['meta_data'];
            unset($actualMeta['stored-event-id']);
            $this->assertSame($expectedMeta, $actualMeta);
        }
    }

    public function test_converted_and_legacy_assigned_can_mix(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M', 'previousAssignedUserId' => null]),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'B', 'assignedByUserId' => 'M']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame(1, $result->targetCount);
        $this->assertSame(1, $result->skippedConverted);
        // 変換済みの assigned は status を変えないので、直前は not_started のまま。
        $this->assertSame(['not_started' => 1], $result->previousStatusCounts);
        $this->assertSame($rows[1]['event_properties'], $result->rows[1]['event_properties']);
        $this->assertSame('A', $result->rows[2]['event_properties']['previousAssignedUserId']);
        $this->assertSame('backoffice_task.status_changed', $result->rows[3]['event_class']);
    }

    public function test_second_run_has_no_target_and_returns_the_input(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row('wf-1', 'workflow_request.submitted', []),
        ];

        $first = $this->splitter->split($rows);
        $second = $this->splitter->split($first->rows);

        $this->assertSame(0, $second->targetCount);
        $this->assertSame(0, $second->insertedStatusChanged);
        $this->assertSame(1, $second->skippedConverted);
        $this->assertSame($first->rows, $second->rows);
    }

    public function test_legacy_assigned_with_no_inserted_status_changed_still_gets_previous_assigned_user_id(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.status_changed', ['previousStatus' => 'not_started', 'newStatus' => 'in_review', 'changedByUserId' => 'M', 'comment' => null]),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'B', 'assignedByUserId' => 'M']),
        ];

        $result = $this->splitter->split($rows);

        $this->assertSame([], $result->violations);
        $this->assertSame(2, $result->targetCount);
        $this->assertSame(0, $result->insertedStatusChanged);
        $this->assertSame(['in_review' => 2], $result->previousStatusCounts);
        $this->assertCount(4, $result->rows);
        $this->assertSame(['assignedUserId' => 'A', 'assignedByUserId' => 'M', 'previousAssignedUserId' => null], $result->rows[2]['event_properties']);
        $this->assertSame(['assignedUserId' => 'B', 'assignedByUserId' => 'M', 'previousAssignedUserId' => 'A'], $result->rows[3]['event_properties']);
        $this->assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 4], $result->idMap);
        $this->assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 4], $result->versionMap);
    }

    public function test_id_and_version_maps_and_meta_source_event_id_follow_the_new_ids(): void
    {
        $rows = [
            $this->row('wf-1', 'workflow_request.submitted', []),
            $this->row(self::T, 'backoffice_task.created', []),
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row(self::T, 'backoffice_task.completed', []),
        ];
        // 欠番(補正で削除された想定)と、meta が旧idを指す行。
        $rows[3]['id'] = 10;
        $rows[2]['meta_data']['history-normalization'] = ['source' => 'x', 'source_event_id' => 1];
        $rows[1]['meta_data']['history-normalization'] = ['source' => 'x', 'source_event_id' => 999];

        $result = $this->splitter->split($rows);

        $this->assertSame([1 => 1, 2 => 2, 3 => 3, 10 => 5], $result->idMap);
        $this->assertSame([1 => 1, 2 => 1, 3 => 2, 10 => 4], $result->versionMap);
        $this->assertSame(1, $result->rows[2]['meta_data']['history-normalization']['source_event_id']);
        $this->assertSame(999, $result->rows[1]['meta_data']['history-normalization']['source_event_id'], '対応の無いidは変えない');
    }

    public function test_assigned_before_created_is_a_violation_and_rows_are_empty(): void
    {
        $rows = [
            $this->row(self::T, 'backoffice_task.assigned', ['assignedUserId' => 'A', 'assignedByUserId' => 'M']),
            $this->row(self::T, 'backoffice_task.created', []),
        ];

        $result = $this->splitter->split($rows);

        $this->assertCount(1, $result->violations);
        $this->assertSame([], $result->rows);
    }

    /** @param array<string, mixed> $payload */
    private function row(string $aggregate, string $class, array $payload, string $at = '2026-09-01 10:00:00'): array
    {
        $this->id++;
        $this->versions[$aggregate] = ($this->versions[$aggregate] ?? 0) + 1;

        return [
            'id' => $this->id,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $this->versions[$aggregate],
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => $payload,
            'meta_data' => ['aggregate-root-uuid' => $aggregate, 'aggregate-root-version' => $this->versions[$aggregate], 'stored-event-id' => $this->id, 'created-at' => $at],
            'created_at' => $at,
        ];
    }
}
