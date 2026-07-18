<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 勤務形態区分(docs/07-usecases-attendance.md「勤務形態区分」)。日次編集で
 * work_location_typeを送信しなかった場合に既存の値が消えないことを確認する
 * (セルフレビューで見つかった不具合の回帰テスト)。
 */
class AttendanceWorkLocationTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_day_without_sending_work_location_type_preserves_the_existing_value(): void
    {
        $user = User::factory()->create();
        $create = $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id,
            'work_date' => '2026-07-01',
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'breaks' => [],
            'work_location_type' => 'remote',
            'reason' => '新規作成',
        ]);
        $create->assertCreated();
        $this->assertSame('remote', $create->json('work_location_type'));

        $dayId = $create->json('id');

        // work_location_typeを含まないリクエストで、note等の別項目だけを編集する。
        $edit = $this->actingAs($user)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'breaks' => [],
            'note' => '別件の修正',
            'reason' => '備考のみ修正',
        ]);
        $edit->assertSuccessful();

        $day = AttendanceDay::query()->findOrFail($dayId);
        $this->assertSame('remote', $day->work_location_type);
    }

    public function test_editing_a_day_with_an_explicit_work_location_type_updates_it(): void
    {
        $user = User::factory()->create();
        $create = $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id,
            'work_date' => '2026-07-01',
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'breaks' => [],
            'work_location_type' => 'remote',
            'reason' => '新規作成',
        ]);
        $dayId = $create->json('id');

        $edit = $this->actingAs($user)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'breaks' => [],
            'work_location_type' => 'office',
            'reason' => '出社に変更',
        ]);
        $edit->assertSuccessful();
        $this->assertSame('office', $edit->json('work_location_type'));
    }

    public function test_editing_a_day_with_an_explicit_null_work_location_type_clears_it(): void
    {
        $user = User::factory()->create();
        $create = $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id,
            'work_date' => '2026-07-01',
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'breaks' => [],
            'work_location_type' => 'remote',
            'reason' => '新規作成',
        ]);
        $dayId = $create->json('id');

        $edit = $this->actingAs($user)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-07-01T09:00:00+09:00',
            'actual_end_at' => '2026-07-01T18:00:00+09:00',
            'breaks' => [],
            'work_location_type' => null,
            'reason' => '未設定に戻す',
        ]);
        $edit->assertSuccessful();

        $day = AttendanceDay::query()->findOrFail($dayId);
        $this->assertNull($day->work_location_type);
    }
}
