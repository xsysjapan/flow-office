<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmploymentCategoryResource;
use App\Models\EmploymentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 雇用区分マスタ(正社員/契約社員/パート/アルバイト/嘱託等)を管理する。
 * work_styles(労働時間制度)とは独立した軸として扱う。
 */
class EmploymentCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmploymentCategoryResource::collection(EmploymentCategory::query()->orderBy('name')->get());
    }

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
