<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\EventRestorabilityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 現行のイベントクラスへ復元できない古いpayloadの行を、種別ごとに洗い出せること。 */
class EventRestorabilityCheckerTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = ['userId' => 'u1', 'entryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'x', 'candidateGrantDays' => 10];

    private const CURRENT = ['userId' => 'u1', 'scheduleEntryId' => 'e1', 'scheduledOn' => '2026-10-01', 'category' => 'x', 'candidateGrantDays' => 10, 'isDeterminate' => true];

    public function test_old_format_rows_are_reported_by_class_with_count_and_first_id(): void
    {
        $this->insert(1, 'a1', 1, self::CURRENT);
        $this->insert(2, 'a2', 1, self::OLD);
        $this->insert(3, 'a3', 1, self::OLD);

        $failures = (new EventRestorabilityChecker)->checkTable('stored_events');

        $this->assertSame(['paid_leave_schedule.entry_created'], array_keys($failures));
        $this->assertSame(2, $failures['paid_leave_schedule.entry_created']['count']);
        $this->assertSame(2, $failures['paid_leave_schedule.entry_created']['first_id']);
        $this->assertNotSame('', $failures['paid_leave_schedule.entry_created']['message']);
        $this->assertStringNotContainsString("\n", $failures['paid_leave_schedule.entry_created']['message']);
    }

    public function test_current_format_rows_pass(): void
    {
        $this->insert(1, 'a1', 1, self::CURRENT);

        $this->assertSame([], (new EventRestorabilityChecker)->checkTable('stored_events'));
    }

    public function test_in_memory_rows_are_checked_without_touching_the_database(): void
    {
        $row = fn (int $id, array $payload) => [
            'id' => $id, 'aggregate_uuid' => 'a'.$id, 'aggregate_version' => 1, 'event_version' => 1,
            'event_class' => 'paid_leave_schedule.entry_created', 'event_properties' => $payload, 'meta_data' => [], 'created_at' => '2026-09-01 10:00:00',
        ];

        $failures = (new EventRestorabilityChecker)->checkRows([$row(1, self::CURRENT), $row(2, self::OLD)]);

        $this->assertSame(1, $failures['paid_leave_schedule.entry_created']['count']);
        $this->assertSame(2, $failures['paid_leave_schedule.entry_created']['first_id']);
        $this->assertSame(0, DB::table('stored_events')->count());
    }

    /** @param  array<string, mixed>  $payload */
    private function insert(int $id, string $aggregate, int $version, array $payload): void
    {
        DB::table('stored_events')->insert([
            'id' => $id, 'aggregate_uuid' => $aggregate, 'aggregate_version' => $version, 'event_version' => 1,
            'event_class' => 'paid_leave_schedule.entry_created',
            'event_properties' => json_encode($payload), 'meta_data' => json_encode([]), 'created_at' => '2026-09-01 10:00:00',
        ]);
    }
}
