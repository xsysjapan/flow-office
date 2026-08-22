<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequestCenterItemResource;
use App\Models\RequestCenterItem;
use App\Models\RequestCenterItemType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * 「申請センター」画面: 自分の申請(有給・代休・経費精算・汎用申請)をステータス横断で
 * 一覧表示するための参照専用API。request_center_items(RequestCenterItemProjectorが
 * 再生成するProjection)のみを参照し、承認処理は行わない(対象外)。
 */
#[OA\Tag(name: '申請センター', description: '自分の申請をステータス横断で一覧表示する')]
class RequestCenterController extends Controller
{
    #[OA\Get(
        path: '/request-center/items',
        operationId: 'requestCenter.items.index',
        summary: '自分の申請一覧をステータス横断で取得する',
        tags: ['申請センター'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'request_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['paid_leave', 'compensatory_leave', 'expense_claim', 'workflow'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string'],
            'request_type' => ['sometimes', 'string', Rule::in(RequestCenterItemType::all())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $items = RequestCenterItem::query()
            ->where('requester_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['request_type'] ?? null, fn ($query, $requestType) => $query->where('request_type', $requestType))
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->paginate($data['per_page'] ?? 20, page: $data['page'] ?? null);

        return RequestCenterItemResource::collection($items);
    }
}
