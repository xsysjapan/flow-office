<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\Commands\ClaimDevicePairing;
use App\Domain\Device\Commands\DeleteDevice;
use App\Domain\Device\Commands\DisableDevice;
use App\Domain\Device\Commands\GrantDeviceScope;
use App\Domain\Device\Commands\IssueDevicePairingClaim;
use App\Domain\Device\Commands\RegisterDevice;
use App\Domain\Device\Commands\RevokeDevice;
use App\Domain\Device\Commands\UpdateDeviceRoles;
use App\Domain\Device\Commands\UpdateDeviceSettings;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceRoleType;
use App\Models\DeviceScopeType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkLocationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-D001〜UC-D005: 端末管理(docs/23-usecases-devices.md)。共有Android打刻リーダー・
 * 個人端末・外部端末を共通のモデルで扱う。
 */
#[OA\Tag(name: '端末管理', description: '共有端末・個人端末・外部端末の登録・ペアリング・停止/失効')]
class DeviceController extends Controller
{
    #[OA\Get(
        path: '/devices',
        operationId: 'devices.index',
        summary: '端末一覧を取得する(管理者)',
        tags: ['端末管理'],
        parameters: [
            new OA\Parameter(name: 'owner_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, description: '削除済みの端末も含めて取得する', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = Device::query()
            ->when($request->boolean('with_trashed'), fn ($q) => $q->withTrashed())
            ->when($request->query('owner_type'), fn ($q, $ownerType) => $q->where('owner_type', $ownerType))
            ->with(['roles', 'scopes'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return DeviceResource::collection($devices);
    }

    #[OA\Get(path: '/devices/{device}', operationId: 'devices.show', summary: '端末詳細を取得する(管理者)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function show(Device $device): DeviceResource
    {
        return new DeviceResource($device->load(['roles', 'scopes']));
    }

    #[OA\Post(path: '/devices', operationId: 'devices.store', summary: '共有端末を登録する(UC-D001)', tags: ['端末管理'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $this->validateRegistration($request, requireRoles: true);

        $result = $commandBus->dispatch(new RegisterDevice(
            ownerType: DeviceOwnerType::ORGANIZATION_SHARED,
            ownerUserId: null,
            name: $data['name'],
            deviceType: $data['device_type'],
            roleTypes: $data['role_types'],
            siteId: $data['site_id'] ?? null,
            locationName: $data['location_name'] ?? null,
            defaultWorkLocationType: $data['default_work_location_type'] ?? null,
            timezone: $data['timezone'] ?? null,
            allowedPunchTypes: $data['allowed_punch_types'] ?? null,
            allowOffline: $data['allow_offline'] ?? true,
            requireLocation: $data['require_location'] ?? false,
            autoDetectPunchType: $data['auto_detect_punch_type'] ?? false,
            registeredByUserId: $request->user()->id,
        ));

        return (new DeviceResource($result['device']))->response()->setStatusCode(201);
    }

    /**
     * UC-D003: 個人端末を本人が登録する。
     */
    #[OA\Post(path: '/users/me/devices', operationId: 'devices.storePersonal', summary: '個人端末を登録する(UC-D003)', tags: ['端末管理'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function storePersonal(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $this->validateRegistration($request, requireRoles: false);

        $result = $commandBus->dispatch(new RegisterDevice(
            ownerType: DeviceOwnerType::PERSONAL,
            ownerUserId: $request->user()->id,
            name: $data['name'],
            deviceType: $data['device_type'],
            roleTypes: [DeviceRoleType::PERSONAL_OPERATION],
            siteId: null,
            locationName: null,
            defaultWorkLocationType: null,
            timezone: null,
            allowedPunchTypes: null,
            allowOffline: true,
            requireLocation: false,
            autoDetectPunchType: true,
            registeredByUserId: $request->user()->id,
        ));

        return response()->json([
            'device' => new DeviceResource($result['device']),
            'token' => $result['plainTextToken'],
        ], 201);
    }

    #[OA\Get(path: '/users/me/devices', operationId: 'devices.indexMine', summary: '自分の個人端末一覧を取得する', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $devices = Device::query()
            ->where('owner_type', DeviceOwnerType::PERSONAL)
            ->where('owner_user_id', $request->user()->id)
            ->with(['roles', 'scopes'])
            ->orderByDesc('created_at')
            ->get();

        return DeviceResource::collection($devices);
    }

    /**
     * UC-D002: 共有端末の一時ペアリングトークン(claim token)を発行する。管理者の
     * 認証済みトークンだけを認可根拠にする(匿名のコード発行APIは持たない)。管理者
     * トークンをそのまま端末に渡すのではなく、`device:claim-pairing`abilityのみを持つ
     * 5分間有効な一時トークンを新たに発行し、それをQRコードとして端末アプリへ渡す。
     * 端末アプリは`POST /devices/pairing/claim`へこの一時トークンを提示し、業務用の
     * 本トークンに交換する。
     */
    #[OA\Post(path: '/devices/{device}/pairing', operationId: 'devices.issuePairingClaim', summary: '端末の一時ペアリングトークンを発行する(UC-D002)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function issuePairingClaim(Device $device, CommandBus $commandBus, Request $request): JsonResponse
    {
        $result = $commandBus->dispatch(new IssueDevicePairingClaim(
            deviceId: $device->id,
            issuedByUserId: $request->user()->id,
        ));

        return response()->json([
            'device' => new DeviceResource($result['device']),
            'claim_token' => $result['claimToken'],
            'claim_url' => route('devices.pairing.claim'),
        ]);
    }

    /**
     * UC-D002: 端末アプリが一時ペアリングトークン(claim token)を業務用の本トークンに
     * 交換する。ルートは`auth:sanctum`+`ability:device:claim-pairing`で保護されており、
     * ここに到達した時点で`$request->user()`は一時トークンの持ち主(Device自身)であることが
     * 確認済み。
     */
    #[OA\Post(path: '/devices/pairing/claim', operationId: 'devices.claimPairing', summary: '一時ペアリングトークンを本トークンに交換する(UC-D002)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function claimPairing(Request $request, CommandBus $commandBus): JsonResponse
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        $result = $commandBus->dispatch(new ClaimDevicePairing(deviceId: $device->id));

        $base = rtrim(config('app.url'), '/');
        $base = rtrim("$base/".config('app.api_prefix', ''), '/');

        return response()->json([
            'device' => new DeviceResource($result['device']),
            'token' => $result['plainTextToken'],
            // ペアリング完了後、端末アプリが以後のAPIコール(heartbeat・打刻等)に使う
            // ベースURL。claim_urlと同じくAPP_URL・APP_API_PREFIXを踏まえて確定させる。
            'api_base_url' => $base,
        ]);
    }

    /**
     * 端末の設置場所・自動反映する勤務形態区分などの設定を変更する(管理者)。
     */
    #[OA\Patch(path: '/devices/{device}', operationId: 'devices.update', summary: '端末の設定を変更する', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function update(Request $request, Device $device, CommandBus $commandBus): DeviceResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_id' => ['nullable', 'string'],
            'location_name' => ['nullable', 'string'],
            'default_work_location_type' => ['nullable', Rule::in(WorkLocationType::values())],
            'timezone' => ['nullable', 'string'],
            'allowed_punch_types' => ['nullable', 'array'],
            'allow_offline' => ['nullable', 'boolean'],
            'require_location' => ['nullable', 'boolean'],
            'auto_detect_punch_type' => ['nullable', 'boolean'],
        ]);

        $device = $commandBus->dispatch(new UpdateDeviceSettings(
            deviceId: $device->id,
            name: $data['name'],
            siteId: $data['site_id'] ?? null,
            locationName: $data['location_name'] ?? null,
            defaultWorkLocationType: $data['default_work_location_type'] ?? null,
            timezone: $data['timezone'] ?? null,
            allowedPunchTypes: $data['allowed_punch_types'] ?? null,
            allowOffline: $data['allow_offline'] ?? true,
            requireLocation: $data['require_location'] ?? false,
            autoDetectPunchType: $data['auto_detect_punch_type'] ?? false,
            updatedByUserId: $request->user()->id,
        ));

        return new DeviceResource($device);
    }

    /**
     * 共有端末の役割(device_roles)を、登録時と同じ選択肢の集合に入れ替える。
     */
    #[OA\Patch(path: '/devices/{device}/roles', operationId: 'devices.updateRoles', summary: '端末の役割を変更する', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function updateRoles(Request $request, Device $device, CommandBus $commandBus): DeviceResource
    {
        $data = $request->validate([
            'role_types' => ['required', 'array', 'min:1'],
            'role_types.*' => [Rule::in(DeviceRoleType::values())],
        ]);

        $device = $commandBus->dispatch(new UpdateDeviceRoles(
            deviceId: $device->id,
            roleTypes: $data['role_types'],
            updatedByUserId: $request->user()->id,
        ));

        return new DeviceResource($device);
    }

    /**
     * UC-D005: 端末を一時停止する。
     */
    #[OA\Post(path: '/devices/{device}/disable', operationId: 'devices.disable', summary: '端末を停止する(UC-D005)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function disable(Device $device, CommandBus $commandBus, Request $request): DeviceResource
    {
        $this->abortUnlessDeviceOwnerOrAdmin($request, $device);

        $device = $commandBus->dispatch(new DisableDevice(
            deviceId: $device->id,
            disabledByUserId: $request->user()->id,
        ));

        return new DeviceResource($device);
    }

    /**
     * UC-D005: 端末を紛失・盗難等により失効させる。
     */
    #[OA\Post(path: '/devices/{device}/revoke', operationId: 'devices.revoke', summary: '端末を失効させる(UC-D005)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function revoke(Device $device, CommandBus $commandBus, Request $request): DeviceResource
    {
        $this->abortUnlessDeviceOwnerOrAdmin($request, $device);

        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $device = $commandBus->dispatch(new RevokeDevice(
            deviceId: $device->id,
            revokedByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        return new DeviceResource($device);
    }

    /**
     * UC-D005: 停止・失効済みの端末を一覧から論理削除する(管理者)。監査証跡
     * (stored_events、UC-M003)は残すため物理削除はしない。
     */
    #[OA\Delete(path: '/devices/{device}', operationId: 'devices.destroy', summary: '端末を削除する(UC-D005)', tags: ['端末管理'], responses: [new OA\Response(response: 204, description: 'No Content')])]
    public function destroy(Device $device, CommandBus $commandBus, Request $request): Response
    {
        $commandBus->dispatch(new DeleteDevice(
            deviceId: $device->id,
            deletedByUserId: $request->user()->id,
        ));

        return response()->noContent();
    }

    /**
     * UC-D004: 外部端末にAPIスコープを付与する(管理者)。
     */
    #[OA\Post(path: '/devices/{device}/scopes', operationId: 'devices.grantScope', summary: '端末にAPIスコープを付与する(UC-D004)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function grantScope(Request $request, Device $device, CommandBus $commandBus): DeviceResource
    {
        $data = $request->validate(['scope' => ['required', Rule::in(DeviceScopeType::values())]]);

        $device = $commandBus->dispatch(new GrantDeviceScope(
            deviceId: $device->id,
            scope: $data['scope'],
            grantedByUserId: $request->user()->id,
        ));

        return new DeviceResource($device);
    }

    /**
     * 端末アプリからの疎通確認。last_seen_at/app_versionを更新するだけの高頻度な
     * 運用テレメトリのため、Command/EventStoreを経由しない(docs/23-usecases-devices.md参照)。
     */
    #[OA\Post(path: '/devices/heartbeat', operationId: 'devices.heartbeat', summary: '端末の疎通確認を記録する', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function heartbeat(Request $request): DeviceResource
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        $data = $request->validate(['app_version' => ['nullable', 'string']]);

        $device->last_seen_at = now();
        if (isset($data['app_version'])) {
            $device->app_version = $data['app_version'];
        }
        $device->save();

        return new DeviceResource($device);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRegistration(Request $request, bool $requireRoles): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'device_type' => ['required', Rule::in(DeviceType::values())],
            'role_types' => [$requireRoles ? 'required' : 'sometimes', 'array'],
            'role_types.*' => [Rule::in(DeviceRoleType::values())],
            'site_id' => ['nullable', 'string'],
            'location_name' => ['nullable', 'string'],
            'default_work_location_type' => ['nullable', Rule::in(WorkLocationType::values())],
            'timezone' => ['nullable', 'string'],
            'allowed_punch_types' => ['nullable', 'array'],
            'allow_offline' => ['nullable', 'boolean'],
            'require_location' => ['nullable', 'boolean'],
            'auto_detect_punch_type' => ['nullable', 'boolean'],
        ]);
    }

    private function abortUnlessDeviceOwnerOrAdmin(Request $request, Device $device): void
    {
        $user = $request->user();
        if ($user instanceof User && $user->hasRole(Role::ADMIN)) {
            return;
        }

        abort_unless(
            $device->owner_type === DeviceOwnerType::PERSONAL && $user instanceof User && $device->owner_user_id === $user->id,
            403,
            'この端末を操作する権限がありません。'
        );
    }
}
