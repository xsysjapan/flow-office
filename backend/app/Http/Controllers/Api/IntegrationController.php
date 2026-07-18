<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Integration\Commands\RegisterIntegration;
use App\Domain\Integration\Commands\ReissueIntegrationToken;
use App\Domain\Integration\Commands\RevokeIntegration;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationIntegrationResource;
use App\Models\ApplicationIntegration;
use App\Models\IntegrationClientType;
use App\Models\IntegrationScopeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-I001〜UC-I003: 個人API・MCP連携(docs/25-usecases-integrations-mcp.md)。
 * ClaudeなどのAIアプリはMCPサーバーを経由し、ここで発行したトークンで勤怠管理APIを呼び出す。
 */
#[OA\Tag(name: 'API・MCP連携', description: '個人API・MCP連携の登録・停止/削除')]
class IntegrationController extends Controller
{
    #[OA\Get(path: '/users/me/integrations', operationId: 'integrations.indexMine', summary: '自分のAPI・MCP連携一覧を取得する', tags: ['API・MCP連携'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $integrations = ApplicationIntegration::query()
            ->where('owner_user_id', $request->user()->id)
            ->with('scopes')
            ->orderByDesc('created_at')
            ->get();

        return ApplicationIntegrationResource::collection($integrations);
    }

    /**
     * UC-I001: 個人API・MCP連携を登録する。
     */
    #[OA\Post(
        path: '/users/me/integrations',
        operationId: 'integrations.store',
        summary: '個人API・MCP連携を登録する(UC-I001)',
        tags: ['API・MCP連携'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['client_type', 'client_name', 'scopes'], properties: [new OA\Property(property: 'client_type', type: 'string'), new OA\Property(property: 'client_name', type: 'string'), new OA\Property(property: 'purpose', type: 'string', nullable: true), new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'client_type' => ['required', Rule::in(IntegrationClientType::values())],
            'client_name' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(IntegrationScopeType::values())],
        ]);

        $result = $commandBus->dispatch(new RegisterIntegration(
            ownerUserId: $request->user()->id,
            clientType: $data['client_type'],
            clientName: $data['client_name'],
            purpose: $data['purpose'] ?? null,
            scopes: $data['scopes'],
            registeredByUserId: $request->user()->id,
        ));

        return response()->json([
            'integration' => new ApplicationIntegrationResource($result['integration']),
            'token' => $result['plainTextToken'],
        ], 201);
    }

    /**
     * UC-I003: アクセストークンを再発行する。
     */
    #[OA\Post(path: '/users/me/integrations/{integration}/reissue', operationId: 'integrations.reissue', summary: 'アクセストークンを再発行する(UC-I003)', tags: ['API・MCP連携'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function reissue(Request $request, ApplicationIntegration $integration, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $integration->owner_user_id, '他の社員の連携を操作する権限がありません。');

        $result = $commandBus->dispatch(new ReissueIntegrationToken(
            integrationId: $integration->id,
            reissuedByUserId: $request->user()->id,
        ));

        return response()->json([
            'integration' => new ApplicationIntegrationResource($result['integration']),
            'token' => $result['plainTextToken'],
        ]);
    }

    /**
     * UC-I003: 連携を停止・削除する。
     */
    #[OA\Post(path: '/users/me/integrations/{integration}/revoke', operationId: 'integrations.revoke', summary: '連携を停止する(UC-I003)', tags: ['API・MCP連携'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function revoke(Request $request, ApplicationIntegration $integration, CommandBus $commandBus): ApplicationIntegrationResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $integration->owner_user_id, '他の社員の連携を操作する権限がありません。');

        $integration = $commandBus->dispatch(new RevokeIntegration(
            integrationId: $integration->id,
            revokedByUserId: $request->user()->id,
        ));

        return new ApplicationIntegrationResource($integration);
    }
}
