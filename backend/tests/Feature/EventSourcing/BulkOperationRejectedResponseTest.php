<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\BulkOperationRejected;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BulkOperationRejectedResponseTest extends TestCase
{
    public function test_is_rendered_as_422_with_items_and_errors(): void
    {
        Route::post('/api/__bulk_rejected_test', function () {
            throw new BulkOperationRejected([
                ['id' => 3, 'label' => 'タスクC', 'message' => '理由C'],
                ['id' => 'x-1', 'message' => '理由X1'],
                ['id' => 3, 'label' => 'タスクC', 'message' => '理由C2'],
            ]);
        });

        $this->postJson('/api/__bulk_rejected_test')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => '選んだ対象のうち、処理できないものがあります。',
                'code' => 'bulk_operation_rejected',
                'errors' => [
                    'items.3' => ['理由C', '理由C2'],
                    'items.x-1' => ['理由X1'],
                ],
                'items' => [
                    ['id' => 3, 'label' => 'タスクC', 'message' => '理由C'],
                    ['id' => 'x-1', 'message' => '理由X1'],
                    ['id' => 3, 'label' => 'タスクC', 'message' => '理由C2'],
                ],
            ]);
    }
}
