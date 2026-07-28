<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「経費精算機能 設計・実装指示書」5.2: 申請タイトルは任意項目で、後から設定・変更できる。
 */
class UpdateExpenseClaimTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_set_and_change_the_title_of_their_own_draft(): void
    {
        $employee = User::factory()->create();
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}")->assertJsonPath('title', null);

        $this->actingAs($employee)->patchJson("/api/expense-claims/{$claimId}/title", [
            'title' => '大阪出張分',
        ])->assertOk()->assertJsonPath('title', '大阪出張分');

        $this->actingAs($employee)->patchJson("/api/expense-claims/{$claimId}/title", [
            'title' => '大阪出張分(修正)',
        ])->assertOk()->assertJsonPath('title', '大阪出張分(修正)');
    }

    public function test_another_employee_cannot_change_the_title(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($other)->patchJson("/api/expense-claims/{$claimId}/title", [
            'title' => '改ざん',
        ])->assertForbidden();
    }
}
