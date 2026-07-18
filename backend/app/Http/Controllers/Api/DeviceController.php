<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\Commands\DisableDevice;
use App\Domain\Device\Commands\IssueDevicePairingCode;
use App\Domain\Device\Commands\RegisterDevice;
use App\Domain\Device\Commands\RevokeDevice;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceRoleType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkLocationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-D001〜UC-D005: 端末管理(docs/23-usecases-devices.md)。共有Android打刻リーダー・
 * 個人端末・外部端末を共通のモデルで扱う。
 */
#[OA\Tag(name: '端末管理', description: '共有端末・個人端末・外部端末の登録・ペアリング・停止/失効')]
class DeviceController extends Controller
{
    #[OA\Get(path: '/devices', operationId: 'devices.index', summary: '端末一覧を取得する(管理者)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = Device::query()
            ->when($request->query('owner_type'), fn ($q, $ownerType) => $q->where('owner_type', $ownerType))
            ->with('roles')
            ->orderByDesc('created_at')
            ->get();

        return DeviceResource::collection($devices);
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
            ->with('roles')
            ->orderByDesc('created_at')
            ->get();

        return DeviceResource::collection($devices);
    }

    /**
     * UC-D002: 共有端末のペアリングコードを発行する。
     */
    #[OA\Post(path: '/devices/{device}/pairing', operationId: 'devices.issuePairingCode', summary: 'ペアリングコードを発行する(UC-D002)', tags: ['端末管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function issuePairingCode(Device $device, CommandBus $commandBus, Request $request): JsonResponse
    {
        $result = $commandBus->dispatch(new IssueDevicePairingCode(
            deviceId: $device->id,
            issuedByUserId: $request->user()->id,
        ));

        return response()->json([
            'device' => new DeviceResource($result['device']),
            'pairing_code' => $result['pairingCode'],
        ]);
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
