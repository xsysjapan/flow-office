<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\BackfillAttendanceMonthLockShare;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceLock;
use App\Models\AttendanceMonth;
use App\Models\EntityShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AttendanceMonthLocked/AttendanceMonthSharedが導入される前に提出済みだった月次勤怠に対する
 * 事後的なロック・共有の補完(BackfillAttendanceMonthLockShareHandler)。
 */
class BackfillAttendanceMonthLockShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_lock_and_share_for_a_submitted_month_missing_both(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $month = AttendanceMonth::query()->create([
            'user_id' => $applicant->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $approver->id,
        ]);

        $count = app(CommandBus::class)->dispatch(new BackfillAttendanceMonthLockShare);

        $this->assertSame(1, $count);

        $this->assertTrue(AttendanceLock::query()
            ->where('scope_type', AttendanceLock::SCOPE_MONTH)
            ->where('user_id', $applicant->id)
            ->whereDate('period_start_date', '2026-06-01')
            ->whereDate('period_end_date', '2026-06-30')
            ->whereNull('unlocked_at')
            ->exists());

        $this->assertTrue(EntityShare::query()
            ->where('shareable_type', 'attendance_month')
            ->where('shareable_id', $month->id)
            ->where('shared_with_user_id', $approver->id)
            ->exists());
    }

    public function test_does_not_duplicate_lock_or_share_for_a_month_that_already_has_both(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $month = AttendanceMonth::query()->create([
            'user_id' => $applicant->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $approver->id,
        ]);

        AttendanceLock::query()->create([
            'scope_type' => AttendanceLock::SCOPE_MONTH,
            'user_id' => $applicant->id,
            'period_start_date' => '2026-06-01',
            'period_end_date' => '2026-06-30',
            'locked_at' => now(),
        ]);
        EntityShare::query()->create([
            'shareable_type' => 'attendance_month',
            'shareable_id' => $month->id,
            'shared_with_user_id' => $approver->id,
            'shared_by_user_id' => $applicant->id,
            'shared_at' => now(),
        ]);

        $count = app(CommandBus::class)->dispatch(new BackfillAttendanceMonthLockShare);

        $this->assertSame(0, $count);
        $this->assertSame(1, AttendanceLock::query()->where('scope_type', AttendanceLock::SCOPE_MONTH)->where('user_id', $applicant->id)->count());
        $this->assertSame(1, EntityShare::query()->where('shareable_type', 'attendance_month')->where('shareable_id', $month->id)->count());
    }

    public function test_ignores_months_that_are_not_submitted_or_beyond(): void
    {
        $applicant = User::factory()->create();
        AttendanceMonth::query()->create([
            'user_id' => $applicant->id,
            'year_month' => '2026-06',
            'status' => 'not_submitted',
        ]);

        $count = app(CommandBus::class)->dispatch(new BackfillAttendanceMonthLockShare);

        $this->assertSame(0, $count);
    }
}
