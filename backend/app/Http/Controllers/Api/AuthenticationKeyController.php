<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\AuthenticationKey\Commands\DisableAuthenticationKey;
use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticationKeyResource;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-K001〜UC-K003: 認証キー管理(docs/24-usecases-authentication-keys.md)。
 * NFCカード専用にせず、生体認証端末の外部ID・QR・FIDO等も同じエンドポイントで扱う。
 */
#[OA\Tag(name: '認証キー管理', description: 'NFC/生体認証等の認証キーの登録・無効化')]
class AuthenticationKeyController extends Controller
{
    public function __construct(private readonly EffectiveAccessResolver $access) {}

    #[OA\Get(path: '/users/me/authentication-keys', operationId: 'authenticationKeys.indexMine', summary: '自分の認証キー一覧を取得する', tags: ['認証キー管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        return $this->indexForUser($request, $request->user()->id);
    }

    #[OA\Get(path: '/users/{user}/authentication-keys', operationId: 'authenticationKeys.indexForUser', summary: '指定社員の認証キー一覧を取得する(管理者)', tags: ['認証キー管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function indexForUser(Request $request, string $userId): AnonymousResourceCollection
    {
        $targetUserId = $this->resolveTargetUserIdForPermission(
            $request,
            $userId,
            'authentication_key.view',
            '他の社員の認証キーを閲覧する権限がありません。',
        );

        $keys = AuthenticationKey::query()->where('user_id', $targetUserId)->orderByDesc('registered_at')->get();

        return AuthenticationKeyResource::collection($keys);
    }

    /**
     * UC-K001/UC-K002: 認証キーを登録する(本人または管理者代理)。
     */
    #[OA\Post(
        path: '/users/me/authentication-keys',
        operationId: 'authenticationKeys.store',
        summary: '認証キーを登録する(UC-K001/UC-K002)',
        tags: ['認証キー管理'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key_type', 'display_name', 'raw_key_value'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'key_type', type: 'string'), new OA\Property(property: 'display_name', type: 'string'), new OA\Property(property: 'raw_key_value', type: 'string'), new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'valid_until', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'key_type' => ['required', Rule::in(AuthenticationKeyType::values())],
            'display_name' => ['required', 'string', 'max:255'],
            'raw_key_value' => ['required', 'string'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        $targetUserId = $this->resolveTargetUserIdForPermission(
            $request,
            $data['user_id'] ?? null,
            'authentication_key.manage',
            '他の社員の認証キーを登録する権限がありません。',
        );

        $key = $commandBus->dispatch(new IssueAuthenticationKey(
            userId: $targetUserId,
            keyType: $data['key_type'],
            displayName: $data['display_name'],
            rawKeyValue: $data['raw_key_value'],
            validFrom: $data['valid_from'] ?? null,
            validUntil: $data['valid_until'] ?? null,
            metadata: $data['metadata'] ?? null,
            registeredByUserId: $request->user()->id,
        ));

        return (new AuthenticationKeyResource($key))->response()->setStatusCode(201);
    }

    /**
     * UC-K003: 認証キーを無効化する。
     */
    #[OA\Post(path: '/authentication-keys/{authenticationKey}/disable', operationId: 'authenticationKeys.disable', summary: '認証キーを無効化する(UC-K003)', tags: ['認証キー管理'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function disable(Request $request, AuthenticationKey $authenticationKey, CommandBus $commandBus): AuthenticationKeyResource
    {
        $this->resolveTargetUserIdForPermission(
            $request,
            $authenticationKey->user_id,
            'authentication_key.manage',
            '他の社員の認証キーを無効化する権限がありません。',
        );

        $key = $commandBus->dispatch(new DisableAuthenticationKey(
            authenticationKeyId: $authenticationKey->id,
            disabledByUserId: $request->user()->id,
        ));

        return new AuthenticationKeyResource($key);
    }

    private function resolveTargetUserIdForPermission(
        Request $request,
        ?string $requestedUserId,
        string $permission,
        string $message,
    ): string {
        $user = $request->user();
        $userId = $requestedUserId ?? $user->id;
        if ($userId === $user->id) {
            return $userId;
        }

        $canManageOthers = $this->currentTokenHasFullAccess($request)
            && $this->access->hasGlobalPermission($user, $permission);

        abort_unless($canManageOthers, 403, $message);

        return $userId;
    }
}
