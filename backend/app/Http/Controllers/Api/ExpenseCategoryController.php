<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * UC-X001: 経費区分マスタ管理。書き込みはexpense_category.manage Permissionで制御する。
 */
#[OA\Tag(name: '経費区分', description: '経費精算の区分マスタ管理')]
class ExpenseCategoryController extends Controller
{
    #[OA\Get(
        path: '/expense-categories',
        operationId: 'expenseCategories.index',
        summary: '経費区分一覧を取得する',
        tags: ['経費区分'],
        parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ExpenseCategory::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return ExpenseCategoryResource::collection($query->get());
    }

    #[OA\Post(
        path: '/expense-categories',
        operationId: 'expenseCategories.store',
        summary: '経費区分を作成する',
        tags: ['経費区分'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $category = ExpenseCategory::query()->create($data);

        return (new ExpenseCategoryResource($category))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/expense-categories/{expenseCategory}',
        operationId: 'expenseCategories.update',
        summary: '経費区分を更新する',
        tags: ['経費区分'],
        parameters: [new OA\Parameter(name: 'expenseCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, ExpenseCategory $expenseCategory): ExpenseCategoryResource
    {
        $data = $this->validated($request, $expenseCategory);
        $expenseCategory->update($data);

        return new ExpenseCategoryResource($expenseCategory);
    }

    #[OA\Delete(
        path: '/expense-categories/{expenseCategory}',
        operationId: 'expenseCategories.destroy',
        summary: '経費区分を削除する',
        tags: ['経費区分'],
        parameters: [new OA\Parameter(name: 'expenseCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function destroy(ExpenseCategory $expenseCategory): Response
    {
        $expenseCategory->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ExpenseCategory $expenseCategory = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:expense_categories,code,'.($expenseCategory?->id ?? 'NULL')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'entry_mode' => ['required', 'string', 'in:batch,single'],
            'field_definitions' => ['nullable', 'array'],
            'field_definitions.*.key' => ['required_with:field_definitions', 'string', 'max:100'],
            'field_definitions.*.label' => ['required_with:field_definitions', 'string', 'max:100'],
            'field_definitions.*.type' => ['required_with:field_definitions', 'string', 'in:text,number,date,select,boolean'],
            'field_definitions.*.required' => ['nullable', 'boolean'],
            'field_definitions.*.options' => ['nullable', 'array'],
            'evidence_type_default' => ['nullable', 'string', 'in:fact_reference_available,receipt_required,receipt_optional'],
            'receipt_required_threshold' => ['nullable', 'integer', 'min:0'],
            'approval_skip_threshold' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
