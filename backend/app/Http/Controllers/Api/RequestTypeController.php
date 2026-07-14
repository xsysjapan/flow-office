<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequestTypeResource;
use App\Models\RequestType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-W001 / UC-M002: 申請種別を管理する。
 */
#[OA\Tag(name: '申請種別', description: '汎用申請の種別マスタ管理')]
class RequestTypeController extends Controller
{
    #[OA\Get(
        path: '/request-types',
        operationId: 'requestTypes.index',
        summary: '申請種別一覧を取得する',
        tags: ['申請種別'],
        parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RequestType::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return RequestTypeResource::collection($query->get());
    }

    #[OA\Post(
        path: '/request-types',
        operationId: 'requestTypes.store',
        summary: '申請種別を作成する',
        tags: ['申請種別'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name', 'form_schema'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'form_schema', type: 'object'), new OA\Property(property: 'requires_attachment', type: 'boolean'), new OA\Property(property: 'attachment_max_size_kb', type: 'integer', nullable: true), new OA\Property(property: 'attachment_allowed_extensions', type: 'array', nullable: true, items: new OA\Items(type: 'string')), new OA\Property(property: 'eligible_role_codes', type: 'array', nullable: true, items: new OA\Items(type: 'string')), new OA\Property(property: 'requires_backoffice_task', type: 'boolean'), new OA\Property(property: 'backoffice_task_type', type: 'string', nullable: true), new OA\Property(property: 'backoffice_department', type: 'string', nullable: true), new OA\Property(property: 'export_amount_field', type: 'string', nullable: true), new OA\Property(property: 'allowed_status_transitions', type: 'array', nullable: true, items: new OA\Items(type: 'object')), new OA\Property(property: 'is_active', type: 'boolean')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $requestType = RequestType::query()->create($data);

        return (new RequestTypeResource($requestType))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/request-types/{requestType}',
        operationId: 'requestTypes.update',
        summary: '申請種別を更新する',
        tags: ['申請種別'],
        parameters: [new OA\Parameter(name: 'requestType', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name', 'form_schema'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'form_schema', type: 'object'), new OA\Property(property: 'requires_attachment', type: 'boolean'), new OA\Property(property: 'attachment_max_size_kb', type: 'integer', nullable: true), new OA\Property(property: 'attachment_allowed_extensions', type: 'array', nullable: true, items: new OA\Items(type: 'string')), new OA\Property(property: 'eligible_role_codes', type: 'array', nullable: true, items: new OA\Items(type: 'string')), new OA\Property(property: 'requires_backoffice_task', type: 'boolean'), new OA\Property(property: 'backoffice_task_type', type: 'string', nullable: true), new OA\Property(property: 'backoffice_department', type: 'string', nullable: true), new OA\Property(property: 'export_amount_field', type: 'string', nullable: true), new OA\Property(property: 'allowed_status_transitions', type: 'array', nullable: true, items: new OA\Items(type: 'object')), new OA\Property(property: 'is_active', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
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
            'requires_attachment' => ['boolean'],
            'attachment_max_size_kb' => ['nullable', 'integer', 'min:1'],
            'attachment_allowed_extensions' => ['nullable', 'array'],
            'attachment_allowed_extensions.*' => ['string', 'max:20'],
            'eligible_role_codes' => ['nullable', 'array'],
            'eligible_role_codes.*' => ['string', 'max:100'],
            'requires_backoffice_task' => ['boolean'],
            'backoffice_task_type' => ['nullable', 'string', 'max:100'],
            'backoffice_department' => ['nullable', 'string', 'max:100'],
            'export_amount_field' => ['nullable', 'string', 'max:100'],
            'allowed_status_transitions' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);
    }
}
