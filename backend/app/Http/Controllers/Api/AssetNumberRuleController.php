<?php

namespace App\Http\Controllers\Api;

use App\Domain\AssetNumbering\Commands\ConfigureAssetNumberRule;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetNumberRuleResource;
use App\Models\Asset;
use App\Models\AssetNumberRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * 備品の管理番号自動採番ルール(カテゴリ別 or デフォルト)の管理画面用API
 * (docs/changesets/20260831-asset-management-refinement/spec.md 実装対象6)。
 */
#[OA\Tag(name: '備品管理番号採番ルール')]
class AssetNumberRuleController extends Controller
{
    #[OA\Get(
        path: '/asset-number-rules',
        operationId: 'assetNumberRules.index',
        summary: '採番ルール一覧を取得する(デフォルト行を含む)',
        tags: ['備品管理番号採番ルール'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function index(): AnonymousResourceCollection
    {
        $rules = AssetNumberRule::query()->orderByDesc('is_default')->orderBy('category')->get();

        return AssetNumberRuleResource::collection($rules);
    }

    #[OA\Get(
        path: '/asset-number-rules/categories',
        operationId: 'assetNumberRules.categories',
        summary: '登録済みカテゴリ候補一覧を取得する(assets.category と asset_number_rules.category のUNION)',
        tags: ['備品管理番号採番ルール'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function categories(): array
    {
        $fromAssets = Asset::query()->distinct()->pluck('category');
        $fromRules = AssetNumberRule::query()->whereNotNull('category')->pluck('category');

        $categories = $fromAssets->merge($fromRules)->unique()->sort()->values();

        return ['data' => $categories];
    }

    #[OA\Put(
        path: '/asset-number-rules/default',
        operationId: 'assetNumberRules.updateDefault',
        summary: 'デフォルトルールを作成・更新する',
        tags: ['備品管理番号採番ルール'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['prefix'], properties: [new OA\Property(property: 'prefix', type: 'string'), new OA\Property(property: 'digit_count', type: 'integer'), new OA\Property(property: 'enabled', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function updateDefault(Request $request, CommandBus $commandBus): AssetNumberRuleResource
    {
        return $this->configure($request, null, $commandBus);
    }

    #[OA\Put(
        path: '/asset-number-rules/{category}',
        operationId: 'assetNumberRules.update',
        summary: 'カテゴリ別ルールを作成・更新する',
        tags: ['備品管理番号採番ルール'],
        parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['prefix'], properties: [new OA\Property(property: 'prefix', type: 'string'), new OA\Property(property: 'digit_count', type: 'integer'), new OA\Property(property: 'enabled', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, string $category, CommandBus $commandBus): AssetNumberRuleResource
    {
        return $this->configure($request, $category, $commandBus);
    }

    private function configure(Request $request, ?string $category, CommandBus $commandBus): AssetNumberRuleResource
    {
        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:255'],
            'digit_count' => ['integer', 'min:1', 'max:20'],
            'enabled' => ['boolean'],
        ]);

        $rule = $commandBus->dispatch(new ConfigureAssetNumberRule(
            category: $category,
            prefix: $data['prefix'],
            digitCount: $data['digit_count'] ?? 5,
            enabled: $data['enabled'] ?? true,
            actorUserId: $request->user()->id,
        ));

        return new AssetNumberRuleResource($rule);
    }
}
