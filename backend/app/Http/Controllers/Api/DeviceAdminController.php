<?php

namespace App\Http\Controllers\Api;

use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\DeviceAdminSession\Commands\EndDeviceAdminSession;
use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSession;
use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSessionBootstrap;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticationKeyResource;
use App\Http\Resources\UserResource;
use App\Models\AuthenticationKeyType;
use App\Models\Device;
use App\Models\DeviceAdminSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-D006: Android端末を管理者モードにする(docs/23-usecases-devices.md)。社員証NFCの
 * IDは端末でスキャンするまで判明しないため、Web管理画面からの入力ではなく、Android端末上で
 * 管理者ICカードをかざして管理者モードに入り、その場で対象社員を選んでNFCを登録できるようにする。
 */
#[OA\Tag(name: '端末管理者モード', description: 'Android端末の管理者モード(社員証NFCの現地登録)')]
class DeviceAdminController extends Controller
{
    /**
     * UC-D006: 端末アクティベーション直後、管理者ICカードの初回登録経路を判定する。
     * ペアリングを行った管理者本人に紐づく場合は自分自身用(self)、紐づかない場合は
     * 管理者を選択させる(select)。
     */
    #[OA\Get(path: '/devices/me/admin-bootstrap', operationId: 'devices.admin.bootstrapEligibility', summary: '管理者ICカードの初回登録経路を取得する(UC-D006)', tags: ['端末管理者モード'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function bootstrapEligibility(Request $request): JsonResponse
    {
        $device = $this->currentDevice($request);
        $device->loadMissing('activatedByUser.roles');

        $activatedBy = $device->activatedByUser;
        if ($activatedBy !== null && $activatedBy->hasRole(Role::ADMIN)) {
            return response()->json([
                'mode' => 'self',
                'admin_user' => new UserResource($activatedBy),
            ]);
        }

        $adminUsers = User::query()->whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->orderBy('name')->get();

        return response()->json([
            'mode' => 'select',
            'admin_users' => UserResource::collection($adminUsers),
        ]);
    }

    /**
     * UC-D006: ブートストラップ経路で管理者ICカード(社員証NFC等)を登録する。登録と同時に
     * この端末を管理者モードにする。
     */
    #[OA\Post(
        path: '/devices/me/admin-bootstrap/authentication-keys',
        operationId: 'devices.admin.bootstrapRegisterKey',
        summary: 'ブートストラップ経路で管理者ICカードを登録する(UC-D006)',
        tags: ['端末管理者モード'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key_type', 'display_name', 'raw_key_value'], properties: [new OA\Property(property: 'admin_user_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'key_type', type: 'string'), new OA\Property(property: 'display_name', type: 'string'), new OA\Property(property: 'raw_key_value', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function bootstrapRegisterKey(Request $request, CommandBus $commandBus): JsonResponse
    {
        $device = $this->currentDevice($request);

        $data = $request->validate([
            'admin_user_id' => ['nullable', 'string', 'exists:users,id'],
            'key_type' => ['required', Rule::in(AuthenticationKeyType::values())],
            'display_name' => ['required', 'string', 'max:255'],
            'raw_key_value' => ['required', 'string'],
        ]);

        $session = $commandBus->dispatch(new StartDeviceAdminSessionBootstrap(
            deviceId: $device->id,
            targetAdminUserId: $data['admin_user_id'] ?? null,
        ));

        $key = $commandBus->dispatch(new IssueAuthenticationKey(
            userId: $session->admin_user_id,
            keyType: $data['key_type'],
            displayName: $data['display_name'],
            rawKeyValue: $data['raw_key_value'],
            validFrom: null,
            validUntil: null,
            metadata: null,
            registeredByUserId: $session->admin_user_id,
        ));

        return response()->json([
            'admin_session' => $this->sessionResponse($session),
            'authentication_key' => new AuthenticationKeyResource($key),
        ], 201);
    }

    /**
     * UC-D006: 管理者本人のICカード(認証キー)をかざして管理者モードにする。
     */
    #[OA\Post(
        path: '/devices/me/admin-sessions',
        operationId: 'devices.admin.startSession',
        summary: '管理者ICカードをかざして管理者モードにする(UC-D006)',
        tags: ['端末管理者モード'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['raw_key_value'], properties: [new OA\Property(property: 'raw_key_value', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function startSession(Request $request, CommandBus $commandBus): JsonResponse
    {
        $device = $this->currentDevice($request);

        $data = $request->validate(['raw_key_value' => ['required', 'string']]);

        $session = $commandBus->dispatch(new StartDeviceAdminSession(
            deviceId: $device->id,
            rawKeyValue: $data['raw_key_value'],
        ));

        return response()->json(['admin_session' => $this->sessionResponse($session)], 201);
    }

    /**
     * UC-D006: 端末の管理者モードを終了する。
     */
    #[OA\Post(path: '/devices/me/admin-sessions/current/end', operationId: 'devices.admin.endSession', summary: '管理者モードを終了する(UC-D006)', tags: ['端末管理者モード'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function endSession(Request $request, CommandBus $commandBus): JsonResponse
    {
        $device = $this->currentDevice($request);

        $commandBus->dispatch(new EndDeviceAdminSession(deviceId: $device->id));

        return response()->json(['ended' => true]);
    }

    /**
     * UC-D006: 管理者モード中、社員証NFC登録の対象社員を選ぶための一覧を取得する。
     */
    #[OA\Get(
        path: '/devices/me/admin/users',
        operationId: 'devices.admin.users',
        summary: '管理者モード中に社員一覧を取得する(UC-D006)',
        tags: ['端末管理者モード'],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function users(Request $request): AnonymousResourceCollection
    {
        $device = $this->currentDevice($request);
        $this->requireActiveAdminSession($device);

        $users = User::query()
            ->when($request->query('q'), fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(50);

        return UserResource::collection($users);
    }

    /**
     * UC-D006: 管理者モード中、対象社員の既存の認証キー(NFC等)一覧を取得する。
     */
    #[OA\Get(path: '/devices/me/admin/users/{user}/authentication-keys', operationId: 'devices.admin.userAuthenticationKeys', summary: '管理者モード中に対象社員の認証キー一覧を取得する(UC-D006)', tags: ['端末管理者モード'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function userAuthenticationKeys(Request $request, User $user): AnonymousResourceCollection
    {
        $device = $this->currentDevice($request);
        $this->requireActiveAdminSession($device);

        $keys = $user->authenticationKeys()->orderByDesc('registered_at')->get();

        return AuthenticationKeyResource::collection($keys);
    }

    /**
     * UC-D006: 管理者モード中、対象社員の社員証NFCを登録する。
     */
    #[OA\Post(
        path: '/devices/me/admin/users/{user}/authentication-keys',
        operationId: 'devices.admin.registerUserAuthenticationKey',
        summary: '管理者モード中に対象社員の社員証NFCを登録する(UC-D006)',
        tags: ['端末管理者モード'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key_type', 'display_name', 'raw_key_value'], properties: [new OA\Property(property: 'key_type', type: 'string'), new OA\Property(property: 'display_name', type: 'string'), new OA\Property(property: 'raw_key_value', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function registerUserAuthenticationKey(Request $request, User $user, CommandBus $commandBus): JsonResponse
    {
        $device = $this->currentDevice($request);
        $session = $this->requireActiveAdminSession($device);

        $data = $request->validate([
            'key_type' => ['required', Rule::in(AuthenticationKeyType::values())],
            'display_name' => ['required', 'string', 'max:255'],
            'raw_key_value' => ['required', 'string'],
        ]);

        $key = $commandBus->dispatch(new IssueAuthenticationKey(
            userId: $user->id,
            keyType: $data['key_type'],
            displayName: $data['display_name'],
            rawKeyValue: $data['raw_key_value'],
            validFrom: null,
            validUntil: null,
            metadata: null,
            registeredByUserId: $session->admin_user_id,
        ));

        return (new AuthenticationKeyResource($key))->response()->setStatusCode(201);
    }

    private function currentDevice(Request $request): Device
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        return $device;
    }

    private function requireActiveAdminSession(Device $device): DeviceAdminSession
    {
        $session = DeviceAdminSession::activeForDevice($device->id);
        abort_if($session === null, 403, 'この端末は現在管理者モードではありません。管理者ICカードをかざしてください。');

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionResponse(DeviceAdminSession $session): array
    {
        $session->loadMissing('adminUser');

        return [
            'id' => $session->id,
            'admin_user' => new UserResource($session->adminUser),
            'source' => $session->source,
            'started_at' => $session->started_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }
}
