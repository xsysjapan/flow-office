<?php

namespace App\Http\Controllers\Api\Asset;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RelocateAsset;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * 一括QR操作API(spec「一括QR操作API」/論点8)。スキャン都度の検証は
 * AssetController::loanEligibility 等の軽量GET APIで行い、対象一覧はフロント側の
 * ローカル状態に留める。確定時にこのAPIへ対象asset_id配列をまとめて送信し、
 * バックエンドは1備品=1Aggregateとしてループでコマンドを発行する(1件の失敗が
 * 他の備品の処理を止めない部分成功を許容する。spec 論点11)。
 */
#[OA\Tag(name: '備品管理', description: '備品の一括QR操作')]
class AssetBulkOperationController extends Controller
{
    private const OPERATIONS = ['self_loan', 'self_return', 'backoffice_lend', 'return', 'relocate'];

    #[OA\Post(
        path: '/assets/bulk',
        operationId: 'assets.bulk',
        summary: '備品を一括操作する(QR一括貸出/一括返却/一括貸与/一括移設)',
        tags: ['備品管理'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['operation', 'asset_ids'], properties: [new OA\Property(property: 'operation', type: 'string'), new OA\Property(property: 'asset_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response(部分成功を含む)'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', Rule::in(self::OPERATIONS)],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['string'],
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

        $results = [];

        foreach ($data['asset_ids'] as $assetId) {
            try {
                $asset = Asset::query()->findOrFail($assetId);
                $results[] = [
                    'asset_id' => $assetId,
                    'success' => true,
                    'result' => $this->applyOperation($commandBus, $operation, $asset, $user->id, $data),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'asset_id' => $assetId,
                    'success' => false,
                    'error' => $e instanceof DomainRuleException || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                        ? $e->getMessage()
                        : '処理中にエラーが発生しました。',
                ];
            }
        }

        return response()->json([
            'operation' => $operation,
            'results' => $results,
            'succeeded_count' => count(array_filter($results, fn ($r) => $r['success'])),
            'failed_count' => count(array_filter($results, fn ($r) => ! $r['success'])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyOperation(CommandBus $commandBus, string $operation, Asset $asset, string $actingUserId, array $data): string
    {
        switch ($operation) {
            case 'self_loan':
                if ($asset->lending_method !== AssetLendingMethod::SELF_SERVICE) {
                    throw new DomainRuleException('セルフサービス方式の備品のみセルフ貸出できます。');
                }
                $commandBus->dispatch(new LendAsset(
                    assetId: $asset->id,
                    borrowerUserId: $actingUserId,
                    lentByUserId: $actingUserId,
                    expectedReturnAt: $data['expected_return_at'] ?? null,
                ));

                return 'loaned';

            case 'self_return':
                if ($asset->current_loan_id === null || $asset->current_loan_id !== $this->currentLoanIdFor($asset, $actingUserId)) {
                    throw new DomainRuleException('自分が借用中の備品のみ返却できます。');
                }
                $commandBus->dispatch(new ReturnAsset(
                    assetId: $asset->id,
                    loanId: $asset->current_loan_id,
                    returnedByUserId: $actingUserId,
                    returnNote: $data['return_note'] ?? null,
                ));

                return 'returned';

            case 'backoffice_lend':
                $borrowerUserId = $data['borrower_user_id'] ?? null;
                if ($borrowerUserId === null) {
                    throw new DomainRuleException('借用者を指定してください。');
                }

                $loanRequestId = null;
                if ($asset->lending_method === AssetLendingMethod::APPROVAL) {
                    $loanRequestId = AssetLoanRequest::query()
                        ->where('asset_id', $asset->id)
                        ->where('applicant_user_id', $borrowerUserId)
                        ->where('status', AssetLoanRequestStatus::APPROVED)
                        ->latest('approved_at')
                        ->value('id');
                }

                $commandBus->dispatch(new LendAsset(
                    assetId: $asset->id,
                    borrowerUserId: $borrowerUserId,
                    lentByUserId: $actingUserId,
                    expectedReturnAt: $data['expected_return_at'] ?? null,
                    loanRequestId: $loanRequestId,
                ));

                return 'loaned';

            case 'return':
                if ($asset->current_loan_id === null) {
                    throw new DomainRuleException('現在アクティブな貸出がありません。');
                }
                $commandBus->dispatch(new ReturnAsset(
                    assetId: $asset->id,
                    loanId: $asset->current_loan_id,
                    returnedByUserId: $actingUserId,
                    returnNote: $data['return_note'] ?? null,
                ));

                return 'returned';

            case 'relocate':
                $locationText = $data['location_text'] ?? null;
                if ($locationText === null) {
                    throw new DomainRuleException('移設先を指定してください。');
                }
                $commandBus->dispatch(new RelocateAsset(
                    assetId: $asset->id,
                    locationText: $locationText,
                    relocatedByUserId: $actingUserId,
                ));

                return 'relocated';

            default:
                throw new DomainRuleException('未対応の操作です。');
        }
    }

    private function currentLoanIdFor(Asset $asset, string $userId): ?string
    {
        return $asset->loans()
            ->whereNull('returned_at')
            ->where('user_id', $userId)
            ->value('id');
    }

    private function authorizeAssetManage(Request $request): void
    {
        $hasPermission = app(EffectiveAccessResolver::class)->hasGlobalPermission($request->user(), 'asset.manage');

        abort_unless($hasPermission, 403, 'この操作を行う権限がありません。');
    }
}
