<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Commands\AddExpenseItem;
use App\Domain\ExpenseClaim\Commands\ApproveExpenseClaim;
use App\Domain\ExpenseClaim\Commands\CancelExpenseClaim;
use App\Domain\ExpenseClaim\Commands\DeleteExpenseClaim;
use App\Domain\ExpenseClaim\Commands\DraftExpenseClaim;
use App\Domain\ExpenseClaim\Commands\RemoveExpenseItem;
use App\Domain\ExpenseClaim\Commands\ReturnExpenseClaim;
use App\Domain\ExpenseClaim\Commands\SubmitExpenseClaim;
use App\Domain\ExpenseClaim\Commands\UpdateExpenseItem;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseClaimHistoryEntryResource;
use App\Http\Resources\ExpenseClaimResource;
use App\Http\Resources\ExpenseItemResource;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimHistoryEntry;
use App\Models\ExpenseItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * UC-X010〜UC-X012: 経費精算の作成・明細編集・申請・承認・差戻し・取消。
 * 通勤費・業務交通費・その他経費すべてを単一ドメインで扱う(docs/30-usecases-expense.md)。
 */
#[OA\Tag(name: '経費精算', description: '経費精算の作成・明細編集・申請・承認')]
class ExpenseClaimController extends Controller
{
    #[OA\Get(
        path: '/expense-claims/mine',
        operationId: 'expenseClaims.mine',
        summary: '自分の経費精算一覧を取得する',
        tags: ['経費精算'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $claims = ExpenseClaim::query()
            ->with(['employee', 'approver', 'items'])
            ->where('employee_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return ExpenseClaimResource::collection($claims);
    }

    #[OA\Get(
        path: '/expense-claims/to-approve',
        operationId: 'expenseClaims.toApprove',
        summary: '承認待ちの経費精算一覧を取得する',
        tags: ['経費精算'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexToApprove(Request $request): AnonymousResourceCollection
    {
        $claims = ExpenseClaim::query()
            ->with(['employee', 'approver', 'items'])
            ->where('approver_user_id', $request->user()->id)
            ->where('status', 'in_review')
            ->latest()
            ->paginate(20);

        return ExpenseClaimResource::collection($claims);
    }

    #[OA\Get(
        path: '/expense-claims/{expenseClaim}',
        operationId: 'expenseClaims.show',
        summary: '経費精算詳細を取得する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function show(Request $request, ExpenseClaim $expenseClaim): ExpenseClaimResource
    {
        $this->authorizeAccess($request, $expenseClaim);

        return new ExpenseClaimResource(
            $expenseClaim->load(['employee', 'approver', 'items.category', 'items.attachments'])
        );
    }

    #[OA\Post(
        path: '/expense-claims',
        operationId: 'expenseClaims.store',
        summary: '経費精算の下書きを作成する(UC-X004: 対象期間は聞かない。空のボディで下書きのみ作成する)',
        tags: ['経費精算'],
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $claim = $commandBus->dispatch(new DraftExpenseClaim(
            employeeId: $request->user()->id,
        ));

        return (new ExpenseClaimResource($claim))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/expense-claims/{expenseClaim}/items',
        operationId: 'expenseClaims.addItem',
        summary: '経費明細を1件追加する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['category_id', 'amount'], properties: [new OA\Property(property: 'category_id', type: 'integer'), new OA\Property(property: 'amount', type: 'integer')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function addItem(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): JsonResponse
    {
        $this->authorizeOwnership($request, $expenseClaim);

        $data = $this->validatedItem($request);

        $item = $commandBus->dispatch($this->makeAddCommand($expenseClaim->id, $request->user()->id, $data));

        return (new ExpenseItemResource($item))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/expense-claims/{expenseClaim}/items/bulk',
        operationId: 'expenseClaims.bulkAddItems',
        summary: '複数の経費明細を一括追加する(表形式入力・移動経路一括入力・テンプレート適用共通)',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['items'], properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function bulkAddItems(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): JsonResponse
    {
        $this->authorizeOwnership($request, $expenseClaim);

        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1'],
        ]);

        $items = DB::transaction(function () use ($payload, $expenseClaim, $request, $commandBus) {
            $created = [];
            foreach ($payload['items'] as $itemInput) {
                $data = $this->validatedItemArray($itemInput);
                $created[] = $commandBus->dispatch(
                    $this->makeAddCommand($expenseClaim->id, $request->user()->id, $data)
                );
            }

            return $created;
        });

        return ExpenseItemResource::collection(collect($items))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/expense-claims/{expenseClaim}/items/{item}',
        operationId: 'expenseClaims.updateItem',
        summary: '経費明細を修正する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['category_id', 'amount'], properties: [new OA\Property(property: 'category_id', type: 'integer'), new OA\Property(property: 'amount', type: 'integer')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function updateItem(Request $request, ExpenseClaim $expenseClaim, ExpenseItem $item, CommandBus $commandBus): ExpenseItemResource
    {
        $this->authorizeOwnership($request, $expenseClaim);

        $data = $this->validatedItem($request);

        $updated = $commandBus->dispatch(new UpdateExpenseItem(
            claimId: $expenseClaim->id,
            itemId: $item->id,
            updatedByUserId: $request->user()->id,
            categoryId: $data['category_id'],
            usageDate: $data['usage_date'] ?? null,
            description: $data['description'] ?? null,
            amount: $data['amount'],
            projectId: $data['project_id'] ?? null,
            evidenceType: $data['evidence_type'] ?? null,
            factReferenceType: $data['fact_reference_type'] ?? null,
            factReferenceId: $data['fact_reference_id'] ?? null,
            commutingDeductionAmount: $data['commuting_deduction_amount'] ?? 0,
        ));

        return new ExpenseItemResource($updated);
    }

    #[OA\Delete(
        path: '/expense-claims/{expenseClaim}/items/{item}',
        operationId: 'expenseClaims.removeItem',
        summary: '経費明細を削除する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function removeItem(Request $request, ExpenseClaim $expenseClaim, ExpenseItem $item, CommandBus $commandBus): Response
    {
        $this->authorizeOwnership($request, $expenseClaim);

        $commandBus->dispatch(new RemoveExpenseItem(
            claimId: $expenseClaim->id,
            itemId: $item->id,
            removedByUserId: $request->user()->id,
        ));

        return response()->noContent();
    }

    #[OA\Post(
        path: '/expense-claims/{expenseClaim}/submit',
        operationId: 'expenseClaims.submit',
        summary: '経費精算を申請する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['approver_user_id'], properties: [new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function submit(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): ExpenseClaimResource
    {
        $this->authorizeOwnership($request, $expenseClaim);

        $data = $request->validate([
            'approver_user_id' => ['required', 'string', 'exists:users,id'],
        ]);

        $commandBus->dispatch(new SubmitExpenseClaim(
            claimId: $expenseClaim->id,
            approverUserId: $data['approver_user_id'],
            submittedByUserId: $request->user()->id,
        ));

        return new ExpenseClaimResource($expenseClaim->refresh()->load(['employee', 'approver']));
    }

    #[OA\Post(
        path: '/expense-claims/{expenseClaim}/approve',
        operationId: 'expenseClaims.approve',
        summary: '経費精算を承認する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function approve(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): ExpenseClaimResource
    {
        $commandBus->dispatch(new ApproveExpenseClaim(
            claimId: $expenseClaim->id,
            approvedByUserId: $request->user()->id,
        ));

        return new ExpenseClaimResource($expenseClaim->refresh()->load(['employee', 'approver']));
    }

    #[OA\Post(
        path: '/expense-claims/{expenseClaim}/return',
        operationId: 'expenseClaims.return',
        summary: '経費精算を差し戻す',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function return(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): ExpenseClaimResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnExpenseClaim(
            claimId: $expenseClaim->id,
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new ExpenseClaimResource($expenseClaim->refresh()->load(['employee', 'approver']));
    }

    #[OA\Post(
        path: '/expense-claims/{expenseClaim}/cancel',
        operationId: 'expenseClaims.cancel',
        summary: '経費精算を取り消す',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function cancel(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): ExpenseClaimResource
    {
        $data = $request->validate(['reason' => ['required', 'string']]);

        $commandBus->dispatch(new CancelExpenseClaim(
            claimId: $expenseClaim->id,
            cancelledByUserId: $request->user()->id,
            reason: $data['reason'],
        ));

        return new ExpenseClaimResource($expenseClaim->refresh()->load(['employee', 'approver']));
    }

    #[OA\Delete(
        path: '/expense-claims/{expenseClaim}',
        operationId: 'expenseClaims.destroy',
        summary: '不要な下書きを削除する(下書き状態のみ)',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function destroy(Request $request, ExpenseClaim $expenseClaim, CommandBus $commandBus): Response
    {
        $this->authorizeOwnership($request, $expenseClaim);

        $commandBus->dispatch(new DeleteExpenseClaim(
            claimId: $expenseClaim->id,
            deletedByUserId: $request->user()->id,
        ));

        return response()->noContent();
    }

    #[OA\Get(
        path: '/expense-claims/{expenseClaim}/history',
        operationId: 'expenseClaims.history',
        summary: '経費精算の履歴を取得する',
        tags: ['経費精算'],
        parameters: [new OA\Parameter(name: 'expenseClaim', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function history(Request $request, ExpenseClaim $expenseClaim): AnonymousResourceCollection
    {
        $this->authorizeAccess($request, $expenseClaim);

        $entries = ExpenseClaimHistoryEntry::query()
            ->where('expense_claim_id', $expenseClaim->id)
            ->orderBy('occurred_at')
            ->get();

        return ExpenseClaimHistoryEntryResource::collection($entries);
    }

    private function authorizeOwnership(Request $request, ExpenseClaim $expenseClaim): void
    {
        abort_unless($expenseClaim->employee_id === $request->user()->id, Response::HTTP_FORBIDDEN,
            '自分の経費精算のみ操作できます。');
    }

    private function authorizeAccess(Request $request, ExpenseClaim $expenseClaim): void
    {
        $user = $request->user();

        abort_unless(
            $user->id === $expenseClaim->employee_id
                || $user->id === $expenseClaim->approver_user_id
                || $user->hasRole('admin'),
            Response::HTTP_FORBIDDEN,
            'この経費精算を閲覧する権限がありません。'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedItem(Request $request): array
    {
        return $this->validatedItemArray($request->all());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validatedItemArray(array $input): array
    {
        return validator($input, [
            'category_id' => ['required', 'exists:expense_categories,id'],
            'usage_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'integer', 'min:0'],
            'project_id' => ['nullable', 'string', 'max:100'],
            'evidence_type' => ['nullable', 'string', 'in:fact_reference_available,receipt_required,receipt_optional'],
            'fact_reference_type' => ['nullable', 'string', 'max:100'],
            'fact_reference_id' => ['nullable', 'string', 'max:100'],
            'commuting_deduction_amount' => ['nullable', 'integer', 'min:0'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeAddCommand(string $claimId, string $addedByUserId, array $data): AddExpenseItem
    {
        return new AddExpenseItem(
            claimId: $claimId,
            addedByUserId: $addedByUserId,
            categoryId: $data['category_id'],
            usageDate: $data['usage_date'] ?? null,
            description: $data['description'] ?? null,
            amount: $data['amount'],
            projectId: $data['project_id'] ?? null,
            evidenceType: $data['evidence_type'] ?? null,
            factReferenceType: $data['fact_reference_type'] ?? null,
            factReferenceId: $data['fact_reference_id'] ?? null,
            commutingDeductionAmount: $data['commuting_deduction_amount'] ?? 0,
        );
    }
}
