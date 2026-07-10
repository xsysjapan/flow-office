<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequestTypeResource;
use App\Models\RequestType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * UC-W001 / UC-M002: 申請種別を管理する。
 */
class RequestTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RequestType::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return RequestTypeResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $requestType = RequestType::query()->create($data);

        return (new RequestTypeResource($requestType))->response()->setStatusCode(201);
    }

    public function update(Request $request, RequestType $requestType): RequestTypeResource
    {
        $data = $this->validated($request, $requestType);
        $requestType->update($data);

        return new RequestTypeResource($requestType);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?RequestType $requestType = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:request_types,code,'.($requestType?->id ?? 'NULL')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'form_schema' => ['present', 'array'],
            'requires_backoffice_task' => ['boolean'],
            'backoffice_task_type' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);
    }
}
