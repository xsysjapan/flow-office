<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PaidLeaveScheduleEntry::scopeOverdue()`の単体テスト(spec.md 論点8)。
 * 判定条件: `scheduled_on <= 今日`かつ状態がScheduled/AssessmentPending/Eligible/NeedsReview。
 */
class PaidLeaveScheduleEntryOverdueScopeTest extends TestCase
{
    use RefreshDatabase;

    private function createRow(string $userId, string $scheduledOn, string $status): string
    {
        $id = (string) Str::uuid();

        PaidLeaveScheduleEntry::query()->create([
            'id' => $id,
            'user_id' => $userId,
            'scheduled_on' => $scheduledOn,
            'category' => 'normal',
            'candidate_grant_days' => 10.0,
            'status' => $status,
        ]);

        return $id;
    }

    /**
     * @return array<int, string>
     */
    private function overdueIds(Carbon $today): array
    {
        return PaidLeaveScheduleEntry::query()->overdue($today)->pluck('id')->all();
    }

    public function test_includes_unfinalized_statuses_scheduled_on_or_before_today(): void
    {
        $user = User::factory()->create();
        $today = Carbon::parse('2026-10-20');

        $included = [
            $this->createRow($user->id, '2026-10-20', PaidLeaveScheduleAggregate::STATUS_SCHEDULED),
            $this->createRow($user->id, '2026-10-20', PaidLeaveScheduleAggregate::STATUS_ASSESSMENT_PENDING),
            $this->createRow($user->id, '2026-10-20', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            $this->createRow($user->id, '2026-10-20', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW),
            $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_SCHEDULED),
            $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_ASSESSMENT_PENDING),
            $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW),
        ];

        $this->assertEqualsCanonicalizing($included, $this->overdueIds($today));
    }

    public function test_excludes_entries_scheduled_after_today(): void
    {
        $user = User::factory()->create();
        $today = Carbon::parse('2026-10-20');

        $this->createRow($user->id, '2026-10-21', PaidLeaveScheduleAggregate::STATUS_SCHEDULED);
        $this->createRow($user->id, '2026-10-21', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW);

        $this->assertSame([], $this->overdueIds($today));
    }

    public function test_excludes_not_eligible_granted_and_cancelled_entries(): void
    {
        $user = User::factory()->create();
        $today = Carbon::parse('2026-10-20');

        $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE);
        $this->createRow($user->id, '2026-10-20', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE);
        $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_GRANTED);
        $this->createRow($user->id, '2026-09-01', PaidLeaveScheduleAggregate::STATUS_CANCELLED);

        $this->assertSame([], $this->overdueIds($today));
    }
}
