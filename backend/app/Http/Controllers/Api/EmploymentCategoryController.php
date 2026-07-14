<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmploymentCategoryResource;
use App\Models\EmploymentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * 雇用区分マスタ(正社員/契約社員/パート/アルバイト/嘱託等)を管理する。
 * work_styles(労働時間制度)とは独立した軸として扱う。
 */
#[OA\Tag(name: '雇用区分', description: '雇用区分マスタ')]
class EmploymentCategoryController extends Controller
{
    #[OA\Get(
        path: '/employment-categories',
        operationId: 'employmentCategories.index',
        summary: '雇用区分一覧を取得する',
        tags: ['雇用区分'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return EmploymentCategoryResource::collection(EmploymentCategory::query()->orderBy('name')->get());
    }

    #[OA\Post(
        path: '/employment-categories',
        operationId: 'employmentCategories.store',
        summary: '雇用区分を作成する',
        tags: ['雇用区分'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:employment_categories,code'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $employmentCategory = EmploymentCategory::query()->create($data);

        return (new EmploymentCategoryResource($employmentCategory))->response()->setStatusCode(201);
    }
}
