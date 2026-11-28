<?php

namespace App\Http\Controllers\Api\Asset;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\Asset\Commands\ApplyAssetBulkOperation;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * 一括QR操作API(spec「一括QR操作API」/論点8)。スキャン都度の検証は
 * AssetController::loanEligibility 等の軽量GET APIで行い、対象一覧はフロント側の
 * ローカル状態に留める。確定時にこのAPIへ対象asset_id配列をまとめて送信し、
 * バックエンドは1つのCommand(`ApplyAssetBulkOperation`)で全件を処理する。全部か無しか:
 * 1件でも失敗したら何も記録せず、422(code: bulk_operation_rejected)で失敗した備品と理由を返す
 * (docs/changesets/20261128-bulk-operations)。
 */
#[OA\Tag(name: '備品管理', description: '備品の一括QR操作')]
class AssetBulkOperationController extends Controller
{
    #[OA\Post(
        path: '/assets/bulk',
        operationId: 'assets.bulk',
        summary: '備品を一括操作する(QR一括貸出/一括返却/一括貸与/一括移設)',
        tags: ['備品管理'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['operation', 'asset_ids'], properties: [new OA\Property(property: 'operation', type: 'string'), new OA\Property(property: 'asset_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response(全件成功)'), new OA\Response(response: 422, description: '処理できない備品がある(何も記録しない。code: bulk_operation_rejected)'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', Rule::in(ApplyAssetBulkOperation::OPERATIONS)],
            'asset_ids' => ['required', 'array', 'min:1', 'max:200'],
            'asset_ids.*' => ['string', 'distinct'],
            'borrower_user_id' => ['nullable', 'string', 'exists:users,id'],
            'expected_return_at' => ['nullable', 'date'],
            'location_text' => ['nullable', 'string'],
            'return_note' => ['nullable', 'string'],
        ]);

        $operation = $data['operation'];
        $user = $request->user();

        // backoffice_lend/return(他人分)/relocateはasset.manage必須。self_loan/self_returnは
        // 本人操作のみを許容する(Controller側で本人性を検証する)。
        if ($operation === 'backoffice_lend' || $operation === 'relocate') {
            $this->authorizeAssetManage($request);
        }

        if ($operation === 'self_loan' && ($data['borrower_user_id'] ?? $user->id) !== $user->id) {
            abort(403, 'セルフ貸出は自分自身に対してのみ実行できます。');
        }

        $commandBus->dispatch(new ApplyAssetBulkOperation(
            operation: $operation,
            assetIds: array_values($data['asset_ids']),
            actingUserId: $user->id,
            borrowerUserId: $data['borrower_user_id'] ?? null,
            expectedReturnAt: $data['expected_return_at'] ?? null,
            locationText: $data['location_text'] ?? null,
            returnNote: $data['return_note'] ?? null,
        ));

        $result = ['self_loan' => 'loaned', 'backoffice_lend' => 'loaned', 'self_return' => 'returned', 'return' => 'returned', 'relocate' => 'relocated'][$operation];

        return response()->json([
            'operation' => $operation,
            'data' => array_map(fn (string $assetId) => ['asset_id' => $assetId, 'result' => $result], array_values($data['asset_ids'])),
        ]);
    }

    private function authorizeAssetManage(Request $request): void
    {
        $hasPermission = app(EffectiveAccessResolver::class)->hasGlobalPermission($request->user(), 'asset.manage');

        abort_unless($hasPermission, 403, 'この操作を行う権限がありません。');
    }
}
