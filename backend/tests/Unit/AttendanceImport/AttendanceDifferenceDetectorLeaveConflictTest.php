<?php

namespace Tests\Unit\AttendanceImport;

use App\Domain\AttendanceImport\Services\AttendanceDifferenceDetector;
use App\Models\PaidLeaveUsage;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEAVE_CONFLICT(出勤時刻のある日に有給・特別休暇の消化がある)の判定。
 * 取消済みの有給(is_cancelled=true)は衝突として扱わず、申請中と確定済みは文を書き分けて警告する。
 */
class AttendanceDifferenceDetectorLeaveConflictTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-14';

    /**
     * @return array<int, array{code: string, severity: string, message: string}>
     */
    private function leaveConflicts(User $user, ?string $startTime = '09:00'): array
    {
        $result = app(AttendanceDifferenceDetector::class)->detect($user->id, '2026-07', [
            'date' => self::DATE,
            'startTime' => $startTime,
            'endTime' => $startTime === null ? null : '18:00',
            'breaks' => [],
        ]);

        return array_values(array_filter($result['differences'], fn ($d) => $d['code'] === 'LEAVE_CONFLICT'));
    }

    private function paidUsage(User $user, bool $confirmed, bool $cancelled): void
    {
        PaidLeaveUsage::query()->create([
            'user_id' => $user->id,
            'used_on' => self::DATE,
            'used_days' => 1.0,
            'usage_type' => 'full',
            'is_confirmed' => $confirmed,
            'is_cancelled' => $cancelled,
        ]);
    }

    private function specialUsage(User $user, bool $confirmed): void
    {
        $type = SpecialLeaveType::query()->create(['name' => '慶弔休暇']);
        $grant = SpecialLeaveGrant::query()->create([
            'user_id' => $user->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-01-01',
            'granted_days' => 5.0,
            'remaining_days' => 5.0,
        ]);
        SpecialLeaveUsage::query()->create([
            'user_id' => $user->id,
            'special_leave_grant_id' => $grant->id,
            'special_leave_request_id' => (string) Str::uuid(),
            'used_on' => self::DATE,
            'used_days' => 1.0,
            'usage_type' => 'full',
            'is_confirmed' => $confirmed,
        ]);
    }

    public function test_cancelled_paid_leave_is_not_a_conflict(): void
    {
        $user = User::factory()->create();
        $this->paidUsage($user, confirmed: true, cancelled: true);

        $this->assertSame([], $this->leaveConflicts($user));
    }

    public function test_cancelled_pending_paid_leave_is_not_a_conflict(): void
    {
        $user = User::factory()->create();
        $this->paidUsage($user, confirmed: false, cancelled: true);

        $this->assertSame([], $this->leaveConflicts($user));
    }

    public function test_confirmed_paid_leave_warns_with_confirmed_message(): void
    {
        $user = User::factory()->create();
        $this->paidUsage($user, confirmed: true, cancelled: false);

        $this->assertSame([[
            'code' => 'LEAVE_CONFLICT',
            'severity' => 'warning',
            'message' => '有給休暇の取得日と勤務時間が重なっています。',
        ]], $this->leaveConflicts($user));
    }

    public function test_pending_paid_leave_warns_with_pending_message(): void
    {
        $user = User::factory()->create();
        $this->paidUsage($user, confirmed: false, cancelled: false);

        $this->assertSame([[
            'code' => 'LEAVE_CONFLICT',
            'severity' => 'warning',
            'message' => '申請中の有給休暇と勤務時間が重なっています。',
        ]], $this->leaveConflicts($user));
    }

    public function test_no_start_time_means_no_conflict(): void
    {
        $user = User::factory()->create();
        $this->paidUsage($user, confirmed: true, cancelled: false);
        $this->specialUsage($user, confirmed: true);

        $this->assertSame([], $this->leaveConflicts($user, null));
    }

    public function test_confirmed_special_leave_warns_with_confirmed_message(): void
    {
        $user = User::factory()->create();
        $this->specialUsage($user, confirmed: true);

        $this->assertSame([[
            'code' => 'LEAVE_CONFLICT',
            'severity' => 'warning',
            'message' => '特別休暇の取得日と勤務時間が重なっています。',
        ]], $this->leaveConflicts($user));
    }

    public function test_pending_special_leave_warns_with_pending_message(): void
    {
        $user = User::factory()->create();
        $this->specialUsage($user, confirmed: false);

        $this->assertSame([[
            'code' => 'LEAVE_CONFLICT',
            'severity' => 'warning',
            'message' => '申請中の特別休暇と勤務時間が重なっています。',
        ]], $this->leaveConflicts($user));
    }

    public function test_other_users_and_other_dates_are_ignored(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->paidUsage($other, confirmed: true, cancelled: false);

        $this->assertSame([], $this->leaveConflicts($user));
    }
}
