<?php

namespace App\Http\Controllers\Api\Asset;

use App\Domain\Asset\Commands\ChangeAssetLendingMethod;
use App\Domain\Asset\Commands\ChangeAssetManagementType;
use App\Domain\Asset\Commands\CompleteAssetRepair;
use App\Domain\Asset\Commands\DeleteAsset;
use App\Domain\Asset\Commands\DisposeAsset;
use App\Domain\Asset\Commands\InstallAsset;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RecoverAssetFromLost;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Commands\ReissueAssetQrCode;
use App\Domain\Asset\Commands\RelocateAsset;
use App\Domain\Asset\Commands\RemoveAssetFromInstallation;
use App\Domain\Asset\Commands\ReportAssetLost;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\Asset\Commands\SetAssetDefaultLocation;
use App\Domain\Asset\Commands\StartAssetRepair;
use App\Domain\Asset\Commands\UpdateAssetDetails;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetLoanRequestResource;
use App\Http\Resources\AssetLoanResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\StoredEventResource;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetLendingStatus;
use App\Models\AssetLoan;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;
use App\Models\AssetManagementType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * 備品管理フェーズ3(API層)。docs/changesets/20260830-equipment-management/spec.md
 * 「実装対象」節参照。検索・詳細・QR参照・履歴・貸与可否検証はPermission不要
 * (認証済みユーザーなら誰でも)、登録・編集・削除・各種業務操作はasset.manage必須
 * (spec 論点10)。貸与(lend)のみ、self_service方式で本人へ貸与する場合に限り
 * Permission不要とする(spec「貸出方式(lending_method)とLendAsset呼び出し条件」)。
 */
#[OA\Tag(name: '備品管理', description: '備品の登録・検索・貸出・返却・設置・修理・紛失・廃棄')]
class AssetController extends Controller
{
    #[OA\Get(
        path: '/assets',
        operationId: 'assets.index',
        summary: '備品を検索する',
        tags: ['備品管理'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string'],
            'asset_no' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'serial_number' => ['nullable', 'string'],
            'management_type' => ['nullable', Rule::in([AssetManagementType::LENDING, AssetManagementType::INSTALLATION])],
            'lending_status' => ['nullable', 'string'],
            'installation_status' => ['nullable', 'string'],
            'borrower_user_id' => ['nullable', 'string', 'exists:users,id'],
            'default_location_text' => ['nullable', 'string'],
            'current_location_text' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Asset::query()->with(['loans' => fn ($q) => $q->whereNull('returned_at')]);

        if (isset($data['q'])) {
            $keyword = $data['q'];
            $query->where(function ($sub) use ($keyword) {
                $sub->where('asset_no', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('category', 'like', "%{$keyword}%")
                    ->orWhere('serial_number', 'like', "%{$keyword}%");
            });
        }

        $query
            ->when($data['asset_no'] ?? null, fn ($q, $v) => $q->where('asset_no', 'like', "%{$v}%"))
            ->when($data['name'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($data['category'] ?? null, fn ($q, $v) => $q->where('category', 'like', "%{$v}%"))
            ->when($data['serial_number'] ?? null, fn ($q, $v) => $q->where('serial_number', 'like', "%{$v}%"))
            ->when($data['management_type'] ?? null, fn ($q, $v) => $q->where('management_type', $v))
            ->when($data['lending_status'] ?? null, fn ($q, $v) => $q->where('lending_status', $v))
            ->when($data['installation_status'] ?? null, fn ($q, $v) => $q->where('installation_status', $v))
            ->when($data['default_location_text'] ?? null, fn ($q, $v) => $q->where('default_location_text', 'like', "%{$v}%"))
            ->when($data['current_location_text'] ?? null, function ($q, $v) {
                $q->whereHas('placements', fn ($p) => $p->whereNull('ended_at')->where('location_text', 'like', "%{$v}%"));
            })
            ->when($data['borrower_user_id'] ?? null, function ($q, $v) {
                $q->whereHas('loans', fn ($l) => $l->whereNull('returned_at')->where('user_id', $v));
            });

        $assets = $query->latest('created_at')->paginate($data['per_page'] ?? 20);

        return AssetResource::collection($assets);
    }

    #[OA\Get(
        path: '/assets/{asset}',
        operationId: 'assets.show',
        summary: '備品詳細を取得する(現在の貸出/設置状況を含む)',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(Asset $asset): AssetResource
    {
        $asset->load(['loans' => fn ($q) => $q->whereNull('returned_at')->with('borrower'), 'placements']);

        return new AssetResource($asset);
    }

    #[OA\Get(
        path: '/assets/by-qr/{qrToken}',
        operationId: 'assets.byQrToken',
        summary: 'QRトークンから備品を1件取得する(QRスキャン用)',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'qrToken', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 404, description: 'Not Found')],
    )]
    public function showByQrToken(string $qrToken): AssetResource
    {
        $asset = Asset::query()->where('qr_token', $qrToken)->firstOrFail();
        $asset->load(['loans' => fn ($q) => $q->whereNull('returned_at')->with('borrower'), 'placements']);

        return new AssetResource($asset);
    }

    /**
     * `stored_events`をAssetAggregateのUUID(aggregate_uuid)で直接検索する
     * (spec「実装対象」/既存AuditLogControllerと同じくstored_eventsを正として直接参照)。
     */
    #[OA\Get(
        path: '/assets/{asset}/history',
        operationId: 'assets.history',
        summary: '備品の操作履歴を取得する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function history(Asset $asset): AnonymousResourceCollection
    {
        $events = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $asset->id)
            ->orderBy('aggregate_version')
            ->get()
            ->map(fn (EloquentStoredEvent $event) => (object) [
                'id' => $event->id,
                'event_id' => (string) $event->id,
                'aggregate_type' => 'asset',
                'aggregate_id' => $event->aggregate_uuid,
                'version' => $event->aggregate_version,
                'event_type' => $event->event_class,
                'payload' => $event->event_properties,
                'occurred_at' => Carbon::parse($event->created_at),
            ]);

        return StoredEventResource::collection($events);
    }

    /**
     * 一括QR操作の確定前に、対象1件をスキャンした時点で貸出可否・方式・承認要否を
     * 検証する軽量API(spec「一括QR操作API」)。サーバー側には何も保存しない
     * (フロント側リストに追加してよいかの判定材料を返すのみ)。
     */
    #[OA\Get(
        path: '/assets/{asset}/loan-eligibility',
        operationId: 'assets.loanEligibility',
        summary: '貸出可否をスキャン時点で検証する',
        tags: ['備品管理'],
        parameters: [
            new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'borrower_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function loanEligibility(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate([
            'borrower_user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);
        $borrowerUserId = $data['borrower_user_id'] ?? null;

        $result = [
            'asset_id' => $asset->id,
            'management_type' => $asset->management_type,
            'lending_method' => $asset->lending_method,
            'lending_status' => $asset->lending_status,
            'eligible' => false,
            'requires_approval' => $asset->lending_method === AssetLendingMethod::APPROVAL,
            'approved_loan_request_id' => null,
            'reason' => null,
        ];

        if ($asset->management_type !== AssetManagementType::LENDING) {
            $result['reason'] = '貸出品ではありません。';

            return response()->json($result);
        }

        if ($asset->lending_status !== AssetLendingStatus::AVAILABLE) {
            $result['reason'] = '貸出可能な状態ではありません。';

            return response()->json($result);
        }

        if ($asset->lending_method === AssetLendingMethod::APPROVAL) {
            if ($borrowerUserId === null) {
                $result['reason'] = '借用者を指定してください。';

                return response()->json($result);
            }

            $approvedLoanRequest = AssetLoanRequest::query()
                ->where('asset_id', $asset->id)
                ->where('applicant_user_id', $borrowerUserId)
                ->where('status', AssetLoanRequestStatus::APPROVED)
                ->latest('approved_at')
                ->first();

            if ($approvedLoanRequest === null) {
                $result['reason'] = '承認済みの貸出申請がありません。';

                return response()->json($result);
            }

            $result['approved_loan_request_id'] = $approvedLoanRequest->id;
        }

        $result['eligible'] = true;

        return response()->json($result);
    }

    /**
     * spec 論点2-3(貸与時の申請選択UI): 対象資産・借用者に紐づく貸出申請一覧を返す。
     * バックオフィス貸与画面(承認済み・未貸与の申請から1件選ばせる)向けの軽量参照API。
     * asset.manage権限保有者のみ(貸与操作自体と同じ入口)。
     */
    #[OA\Get(
        path: '/assets/{asset}/loan-requests',
        operationId: 'assets.loanRequests',
        summary: '備品に対する貸出申請一覧を取得する',
        tags: ['備品管理'],
        parameters: [
            new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'borrower_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function loanRequests(Request $request, Asset $asset): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'string'],
            'borrower_user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $this->authorizeAssetManage($request);

        $query = AssetLoanRequest::query()
            ->with(['applicant', 'approver'])
            ->where('asset_id', $asset->id);

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['borrower_user_id'])) {
            $query->where('applicant_user_id', $data['borrower_user_id']);
        }

        $loanRequests = $query->orderByDesc('submitted_at')->get();

        return AssetLoanRequestResource::collection($loanRequests);
    }

    #[OA\Post(
        path: '/assets',
        operationId: 'assets.store',
        summary: '備品を登録する',
        tags: ['備品管理'],
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'asset_no' => ['required', 'string', 'max:255', 'unique:assets,asset_no'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'management_type' => ['required', Rule::in([AssetManagementType::LENDING, AssetManagementType::INSTALLATION])],
            'lending_method' => ['nullable', Rule::in([AssetLendingMethod::SELF_SERVICE, AssetLendingMethod::BACKOFFICE, AssetLendingMethod::APPROVAL])],
            'default_location_text' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $asset = $commandBus->dispatch(new RegisterAsset(
            assetNo: $data['asset_no'],
            name: $data['name'],
            category: $data['category'],
            serialNumber: $data['serial_number'] ?? null,
            managementType: $data['management_type'],
            lendingMethod: $data['lending_method'] ?? null,
            defaultLocationText: $data['default_location_text'] ?? null,
            notes: $data['notes'] ?? null,
            registeredByUserId: $request->user()->id,
        ));

        return (new AssetResource($asset))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/assets/{asset}',
        operationId: 'assets.update',
        summary: '備品の詳細を編集する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function update(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $commandBus->dispatch(new UpdateAssetDetails(
            assetId: $asset->id,
            name: $data['name'],
            category: $data['category'],
            serialNumber: $data['serial_number'] ?? null,
            notes: $data['notes'] ?? null,
            updatedByUserId: $request->user()->id,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Delete(
        path: '/assets/{asset}',
        operationId: 'assets.destroy',
        summary: '備品を削除する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'No Content'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function destroy(Request $request, Asset $asset, CommandBus $commandBus): JsonResponse
    {
        $commandBus->dispatch(new DeleteAsset(assetId: $asset->id, deletedByUserId: $request->user()->id));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/assets/{asset}/management-type',
        operationId: 'assets.changeManagementType',
        summary: '管理区分を変更する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function changeManagementType(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate([
            'management_type' => ['required', Rule::in([AssetManagementType::LENDING, AssetManagementType::INSTALLATION])],
        ]);

        $commandBus->dispatch(new ChangeAssetManagementType(
            assetId: $asset->id,
            managementType: $data['management_type'],
            changedByUserId: $request->user()->id,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/lending-method',
        operationId: 'assets.changeLendingMethod',
        summary: '貸出方式を変更する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function changeLendingMethod(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate([
            'lending_method' => ['required', Rule::in([AssetLendingMethod::SELF_SERVICE, AssetLendingMethod::BACKOFFICE, AssetLendingMethod::APPROVAL])],
        ]);

        $commandBus->dispatch(new ChangeAssetLendingMethod(
            assetId: $asset->id,
            lendingMethod: $data['lending_method'],
            changedByUserId: $request->user()->id,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/qr-code/reissue',
        operationId: 'assets.reissueQrCode',
        summary: 'QRコードを再発行する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function reissueQrCode(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $commandBus->dispatch(new ReissueAssetQrCode(assetId: $asset->id, reissuedByUserId: $request->user()->id));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/default-location',
        operationId: 'assets.setDefaultLocation',
        summary: '通常配置場所を設定する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function setDefaultLocation(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['location_text' => ['required', 'string']]);

        $commandBus->dispatch(new SetAssetDefaultLocation(
            assetId: $asset->id,
            locationText: $data['location_text'],
            setByUserId: $request->user()->id,
        ));

        return new AssetResource($asset->refresh());
    }

    /**
     * self_service方式で「本人が本人へ」貸与する場合のみPermission不要(spec「貸出方式
     * (lending_method)とLendAsset呼び出し条件」)。それ以外(他者への貸与、backoffice/
     * approval方式)はasset.manage必須。
     */
    #[OA\Post(
        path: '/assets/{asset}/lend',
        operationId: 'assets.lend',
        summary: '備品を貸与する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function lend(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate([
            'borrower_user_id' => ['required', 'string', 'exists:users,id'],
            'expected_return_at' => ['nullable', 'date'],
            'loan_request_id' => ['nullable', 'string', 'exists:asset_loan_requests,id'],
        ]);

        $user = $request->user();
        $isSelfServiceForSelf = $asset->lending_method === AssetLendingMethod::SELF_SERVICE
            && $data['borrower_user_id'] === $user->id;

        if (! $isSelfServiceForSelf) {
            $this->authorizeAssetManage($request);
        }

        $commandBus->dispatch(new LendAsset(
            assetId: $asset->id,
            borrowerUserId: $data['borrower_user_id'],
            lentByUserId: $user->id,
            expectedReturnAt: $data['expected_return_at'] ?? null,
            loanRequestId: $data['loan_request_id'] ?? null,
        ));

        return new AssetResource($asset->refresh());
    }

    /**
     * Permission不要(セルフ返却・他人による返却を許容)。
     */
    #[OA\Post(
        path: '/assets/{asset}/return',
        operationId: 'assets.return',
        summary: '備品を返却する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function returnAsset(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate([
            'loan_id' => ['nullable', 'string'],
            'return_note' => ['nullable', 'string'],
        ]);

        $loanId = $data['loan_id'] ?? $asset->current_loan_id;

        if ($loanId === null) {
            throw new DomainRuleException('現在アクティブな貸出がありません。');
        }

        $commandBus->dispatch(new ReturnAsset(
            assetId: $asset->id,
            loanId: $loanId,
            returnedByUserId: $request->user()->id,
            returnNote: $data['return_note'] ?? null,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/install',
        operationId: 'assets.install',
        summary: '備品を設置する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function install(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['location_text' => ['required', 'string']]);

        $commandBus->dispatch(new InstallAsset(
            assetId: $asset->id,
            locationText: $data['location_text'],
            installedByUserId: $request->user()->id,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/relocate',
        operationId: 'assets.relocate',
        summary: '備品を移設する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function relocate(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['location_text' => ['required', 'string']]);

        $commandBus->dispatch(new RelocateAsset(
            assetId: $asset->id,
            locationText: $data['location_text'],
            relocatedByUserId: $request->user()->id,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/remove-from-installation',
        operationId: 'assets.removeFromInstallation',
        summary: '備品を撤去する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function removeFromInstallation(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $commandBus->dispatch(new RemoveAssetFromInstallation(assetId: $asset->id, removedByUserId: $request->user()->id));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/repair/start',
        operationId: 'assets.startRepair',
        summary: '修理を開始する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function startRepair(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['note' => ['nullable', 'string']]);

        $commandBus->dispatch(new StartAssetRepair(
            assetId: $asset->id,
            startedByUserId: $request->user()->id,
            note: $data['note'] ?? null,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/repair/complete',
        operationId: 'assets.completeRepair',
        summary: '修理を完了する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function completeRepair(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['note' => ['nullable', 'string']]);

        $commandBus->dispatch(new CompleteAssetRepair(
            assetId: $asset->id,
            completedByUserId: $request->user()->id,
            note: $data['note'] ?? null,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/lost',
        operationId: 'assets.reportLost',
        summary: '紛失を登録する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function reportLost(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['note' => ['nullable', 'string']]);

        $commandBus->dispatch(new ReportAssetLost(
            assetId: $asset->id,
            reportedByUserId: $request->user()->id,
            note: $data['note'] ?? null,
        ));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/recover',
        operationId: 'assets.recover',
        summary: '紛失した備品を発見する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function recover(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $commandBus->dispatch(new RecoverAssetFromLost(assetId: $asset->id, recoveredByUserId: $request->user()->id));

        return new AssetResource($asset->refresh());
    }

    #[OA\Post(
        path: '/assets/{asset}/dispose',
        operationId: 'assets.dispose',
        summary: '備品を廃棄する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function dispose(Request $request, Asset $asset, CommandBus $commandBus): AssetResource
    {
        $data = $request->validate(['note' => ['nullable', 'string']]);

        $commandBus->dispatch(new DisposeAsset(
            assetId: $asset->id,
            disposedByUserId: $request->user()->id,
            note: $data['note'] ?? null,
        ));

        return new AssetResource($asset->refresh());
    }

    /**
     * 指定ユーザーの現在の貸与品一覧(spec 35〜36番)。自分自身の分は誰でも、他人の分は
     * asset.manage必須。
     */
    #[OA\Get(
        path: '/users/{user}/asset-loans',
        operationId: 'users.assetLoans',
        summary: '指定ユーザーの現在の貸与品一覧を取得する',
        tags: ['備品管理'],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function loansForUser(Request $request, User $user): AnonymousResourceCollection
    {
        if ($request->user()->id !== $user->id) {
            $this->authorizeAssetManage($request);
        }

        $loans = AssetLoan::query()
            ->with(['asset', 'borrower'])
            ->where('user_id', $user->id)
            ->whereNull('returned_at')
            ->latest('loaned_at')
            ->get();

        return AssetLoanResource::collection($loans);
    }

    private function authorizeAssetManage(Request $request): void
    {
        $hasPermission = app(\App\Domain\AccessControl\Services\EffectiveAccessResolver::class)
            ->hasGlobalPermission($request->user(), 'asset.manage');

        abort_unless($hasPermission, 403, 'この操作を行う権限がありません。');
    }
}
