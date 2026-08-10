<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class AttendanceMonthWorkflowLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_lock_event_keeps_the_originating_workflow_request_id(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $monthId = (string) Str::uuid();
        $workflowRequestId = (string) Str::uuid();

        AttendanceMonthAggregate::retrieve($monthId)->submit(
            userId: $employee->id,
            yearMonth: '2026-07',
            approverUserId: $approver->id,
            snapshot: ['day_count' => 0],
            periodStartDate: '2026-07-01',
            periodEndDate: '2026-07-31',
            workflowRequestId: $workflowRequestId,
        )->persist();

        $event = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $monthId)
            ->where('event_class', 'attendance_month.locked')
            ->firstOrFail();

        $this->assertSame($workflowRequestId, $event->event_properties['workflowRequestId']);
        $this->assertDatabaseHas('attendance_locks', [
            'user_id' => $employee->id,
            'workflow_request_id' => $workflowRequestId,
        ]);
    }
}
