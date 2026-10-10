<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Projectors\SpecialLeaveUsageProjector;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 旧Projector(SpecialLeaveUsageProjector)が、新しい流れの申請の取消に反応して口座の消化記録を書き換えないことの確認。
 * 旧来の行(usage_idを持たない)だけが取り消され、口座Projectorが作った行(usage_idあり)は残る。
 */
class SpecialLeaveLegacyProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_projector_cancel_touches_only_legacy_usage_rows(): void
    {
        $user = User::factory()->create();
        SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $requestId = (string) Str::uuid();

        SpecialLeaveUsage::query()->create([
            'user_id' => $user->id, 'attendance_day_id' => null, 'special_leave_grant_id' => null,
            'special_leave_request_id' => $requestId, 'used_on' => '2026-08-10', 'used_days' => 1,
            'used_minutes' => null, 'usage_type' => 'full', 'is_confirmed' => false,
        ]);
        $accountUsageId = (string) Str::uuid();
        SpecialLeaveUsage::query()->create([
            'usage_id' => $accountUsageId, 'user_id' => $user->id, 'attendance_day_id' => null, 'special_leave_grant_id' => null,
            'special_leave_request_id' => $requestId, 'used_on' => '2026-08-10', 'used_days' => 1,
            'used_minutes' => null, 'usage_type' => 'full', 'is_confirmed' => false,
        ]);

        (new SpecialLeaveUsageProjector)->onSpecialLeaveRequestCancelled(
            (new SpecialLeaveRequestCancelled(cancelledByUserId: (string) $user->id))->setAggregateRootUuid($requestId),
        );

        $this->assertSame(1, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());
        $this->assertNotNull(SpecialLeaveUsage::query()->where('usage_id', $accountUsageId)->first());
    }
}
