<?php

namespace Tests\Feature\Workflow;

use App\Models\RequestType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「申請センター」画面向け: GET /api/workflow-requests/mine (docs/10-usecases-workflow.md)。
 * 認証ユーザー本人が申請者(applicant_user_id)である申請を、ステータス・申請種別
 * (subject_type)で絞り込んで一覧取得できることを検証する。専用のrequest_center_items
 * Projectionは持たず、既存のworkflow_requestsをそのまま参照する設計。
 */
class WorkflowRequestMineTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_own_requests_regardless_of_status(): void
    {
        $applicant = User::factory()->create();
        $otherUser = User::factory()->create();
        $approver = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'business_card',
            'name' => '名刺申請',
            'form_schema' => [['key' => 'amount', 'label' => '金額', 'type' => 'number', 'required' => true]],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        // 自分の下書き
        $mine = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => '自分の申請',
            'form_data' => ['amount' => 1000],
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        // 他人の申請(一覧に含まれてはいけない)
        $this->actingAs($otherUser)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => '他人の申請',
            'form_data' => ['amount' => 2000],
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        $response = $this->actingAs($applicant)->getJson('/api/workflow-requests/mine');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertSame(['自分の申請'], $titles);
        $this->assertSame($mine, $response->json('data.0.id'));
    }

    public function test_filters_by_status_and_subject_type(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'business_card',
            'name' => '名刺申請',
            'form_schema' => [['key' => 'amount', 'label' => '金額', 'type' => 'number', 'required' => true]],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        $draftId = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => '下書きのまま',
            'form_data' => ['amount' => 500],
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $submittedId = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => '提出済み',
            'form_data' => ['amount' => 800],
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($applicant)->postJson("/api/workflow-requests/{$submittedId}/submit")->assertOk();

        // status=submittedで絞り込むと提出済みのみ
        $response = $this->actingAs($applicant)->getJson('/api/workflow-requests/mine?status=submitted');
        $response->assertOk();
        $this->assertSame([$submittedId], collect($response->json('data'))->pluck('id')->all());

        // status=draftで絞り込むと下書きのみ
        $response = $this->actingAs($applicant)->getJson('/api/workflow-requests/mine?status=draft');
        $response->assertOk();
        $this->assertSame([$draftId], collect($response->json('data'))->pluck('id')->all());

        // subject_typeを持たない申請種別なので、存在しないsubject_typeで絞ると0件
        $response = $this->actingAs($applicant)->getJson('/api/workflow-requests/mine?subject_type=expense_claim');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));

        // 絞り込みなしでは2件とも返る
        $response = $this->actingAs($applicant)->getJson('/api/workflow-requests/mine');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }
}
