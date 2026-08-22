<?php

namespace Tests\Feature\RequestCenter;

use App\Models\RequestCenterItem;
use App\Models\RequestCenterItemType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 「申請センター」画面向け参照専用API(GET /api/request-center/items)。
 * request_center_items(Projection)のみを参照し、認証ユーザー本人の申請のみを返す。
 */
class RequestCenterItemsTest extends TestCase
{
    use RefreshDatabase;

    private function createItem(string $requesterId, string $requestType, string $status, ?string $submittedAt = '2026-08-01 10:00:00'): RequestCenterItem
    {
        return RequestCenterItem::query()->create([
            'id' => (string) Str::uuid(),
            'request_type' => $requestType,
            'source_id' => (string) Str::uuid(),
            'status' => $status,
            'requester_id' => $requesterId,
            'approver_id' => null,
            'title' => 'テスト申請',
            'submitted_at' => $submittedAt,
        ]);
    }

    public function test_only_own_items_are_returned(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->createItem($me->id, RequestCenterItemType::PAID_LEAVE, 'submitted');
        $this->createItem($other->id, RequestCenterItemType::PAID_LEAVE, 'submitted');

        $response = $this->actingAs($me)->getJson('/api/request-center/items')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$mine->id], $ids->all());
    }

    public function test_filters_by_status_and_request_type(): void
    {
        $me = User::factory()->create();
        $paidLeaveSubmitted = $this->createItem($me->id, RequestCenterItemType::PAID_LEAVE, 'submitted');
        $this->createItem($me->id, RequestCenterItemType::PAID_LEAVE, 'approved');
        $this->createItem($me->id, RequestCenterItemType::EXPENSE_CLAIM, 'submitted');

        $response = $this->actingAs($me)
            ->getJson('/api/request-center/items?status=submitted&request_type=paid_leave')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$paidLeaveSubmitted->id], $ids->all());
    }

    public function test_paginates_results(): void
    {
        $me = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->createItem($me->id, RequestCenterItemType::WORKFLOW, 'draft');
        }

        $response = $this->actingAs($me)
            ->getJson('/api/request-center/items?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
