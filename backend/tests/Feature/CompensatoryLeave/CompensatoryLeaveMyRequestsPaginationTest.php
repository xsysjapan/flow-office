<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\CompensatoryLeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 自分の代休申請一覧(GET /compensatory-leave/requests/mine)のページング(対象日の降順・20件ずつ・target_dateで絞り込み)。
 */
class CompensatoryLeaveMyRequestsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(User $user, User $approver, string $targetDate): CompensatoryLeaveRequest
    {
        return CompensatoryLeaveRequest::query()->create([
            'user_id' => $user->id,
            'approver_user_id' => $approver->id,
            'status' => 'submitted',
            'leave_type' => 'full',
            'target_date' => $targetDate,
            'requested_days' => 1,
            'submitted_at' => now(),
        ]);
    }

    public function test_requests_are_ordered_by_target_date_desc_then_id_desc(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $a = $this->makeRequest($employee, $approver, '2026-08-10');
        $b = $this->makeRequest($employee, $approver, '2026-08-12');
        $c = $this->makeRequest($employee, $approver, '2026-08-10');

        $response = $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine')->assertOk();

        $this->assertSame([$b->id, $c->id, $a->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 1);
    }

    public function test_the_21st_request_goes_to_the_second_page(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        foreach (range(1, 21) as $day) {
            $this->makeRequest($employee, $approver, sprintf('2026-09-%02d', $day));
        }

        $first = $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine')->assertOk();
        $first->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 21);
        $this->assertSame('2026-09-21', substr($first->json('data.0.target_date'), 0, 10));

        $second = $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine?page=2')->assertOk();
        $second->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        $this->assertSame('2026-09-01', substr($second->json('data.0.target_date'), 0, 10));
    }

    public function test_target_date_filters_to_that_day_only(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->makeRequest($employee, $approver, '2026-08-10');
        $target = $this->makeRequest($employee, $approver, '2026-08-11');

        $response = $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine?target_date=2026-08-11')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->assertSame($target->id, $response->json('data.0.id'));
        $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine?target_date=2026-08-12')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_invalid_target_date_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine?target_date=2026/08/11')->assertStatus(422);
    }

    public function test_other_users_requests_are_not_returned(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $approver = User::factory()->create();
        $mine = $this->makeRequest($employee, $approver, '2026-08-10');
        $this->makeRequest($other, $approver, '2026-08-10');

        $response = $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine')->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }
}
