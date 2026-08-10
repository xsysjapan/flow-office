<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseEntryPresetResource;
use App\Models\ExpenseEntryPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * 「経費精算機能 設計・実装指示書」9〜10: 入力プリセット管理。personalは本人のみ、
 * company/systemは経理・管理者のみが書き込みできる(expense-route-templatesと同じ方針)。
 * プリセット自体は経費項目1件以上の下書き定義(definition)を持つだけで、任意のコードや
 * 外部API呼び出しは持たせない。
 */
#[OA\Tag(name: '経費入力プリセット', description: 'よく使う経費入力パターンのプリセット管理')]
class ExpenseEntryPresetController extends Controller
{
    #[OA\Get(
        path: '/expense-entry-presets',
        operationId: 'expenseEntryPresets.index',
        summary: 'プリセット一覧を取得する(本人のpersonal + 全社のcompany/systemをマージ)',
        tags: ['経費入力プリセット'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $presets = ExpenseEntryPreset::query()
            ->where('is_active', true)
            ->where(function ($query) use ($userId) {
                $query->whereIn('visibility', [ExpenseEntryPreset::VISIBILITY_COMPANY, ExpenseEntryPreset::VISIBILITY_SYSTEM])
                    ->orWhere(function ($query) use ($userId) {
                        $query->where('visibility', ExpenseEntryPreset::VISIBILITY_PERSONAL)
                            ->where('owner_user_id', $userId);
                    });
            })
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get();

        return ExpenseEntryPresetResource::collection($presets);
    }

    #[OA\Post(
        path: '/expense-entry-presets',
        operationId: 'expenseEntryPresets.store',
        summary: 'プリセットを作成する',
        tags: ['経費入力プリセット'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['visibility', 'name', 'preset_type', 'definition'], properties: [new OA\Property(property: 'visibility', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'preset_type', type: 'string'), new OA\Property(property: 'definition', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        if ($data['visibility'] !== ExpenseEntryPreset::VISIBILITY_PERSONAL) {
            abort_unless(app(EffectiveAccessResolver::class)->hasGlobalPermission($user, 'expense_preset.manage'), Response::HTTP_FORBIDDEN,
                '全社共有・システム標準プリセットは経理・管理者のみ登録できます。');
            $data['owner_user_id'] = null;
        } else {
            $data['owner_user_id'] = $user->id;
        }

        $data['created_by'] = $user->id;

        $preset = ExpenseEntryPreset::query()->create($data);

        return (new ExpenseEntryPresetResource($preset))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/expense-entry-presets/{expenseEntryPreset}',
        operationId: 'expenseEntryPresets.update',
        summary: 'プリセットを更新する',
        tags: ['経費入力プリセット'],
        parameters: [new OA\Parameter(name: 'expenseEntryPreset', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'preset_type', 'definition'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'preset_type', type: 'string'), new OA\Property(property: 'definition', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, ExpenseEntryPreset $expenseEntryPreset): ExpenseEntryPresetResource
    {
        $this->authorizeWrite($request, $expenseEntryPreset);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'preset_type' => ['required', 'string', 'in:single_item,multiple_items'],
            'definition' => ['required', 'array', 'min:1'],
            ...$this->definitionRules(),
            'is_active' => ['boolean'],
        ]);

        $expenseEntryPreset->update($data);

        return new ExpenseEntryPresetResource($expenseEntryPreset);
    }

    #[OA\Delete(
        path: '/expense-entry-presets/{expenseEntryPreset}',
        operationId: 'expenseEntryPresets.destroy',
        summary: 'プリセットを削除する',
        tags: ['経費入力プリセット'],
        parameters: [new OA\Parameter(name: 'expenseEntryPreset', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function destroy(Request $request, ExpenseEntryPreset $expenseEntryPreset): Response
    {
        $this->authorizeWrite($request, $expenseEntryPreset);

        $expenseEntryPreset->delete();

        return response()->noContent();
    }

    #[OA\Post(
        path: '/expense-entry-presets/{expenseEntryPreset}/apply',
        operationId: 'expenseEntryPresets.apply',
        summary: 'プリセットを適用する(利用回数を記録し、明細下書きの定義をそのまま返す)',
        tags: ['経費入力プリセット'],
        parameters: [new OA\Parameter(name: 'expenseEntryPreset', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function apply(ExpenseEntryPreset $expenseEntryPreset): ExpenseEntryPresetResource
    {
        DB::transaction(function () use ($expenseEntryPreset) {
            $expenseEntryPreset->increment('usage_count');
            $expenseEntryPreset->update(['last_used_at' => now()]);
        });

        return new ExpenseEntryPresetResource($expenseEntryPreset->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'visibility' => ['required', 'string', 'in:personal,company,system'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'preset_type' => ['required', 'string', 'in:single_item,multiple_items'],
            'definition' => ['required', 'array', 'min:1'],
            ...$this->definitionRules(),
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function definitionRules(): array
    {
        return [
            'definition.*.category_id' => ['required', 'exists:expense_categories,id'],
            'definition.*.description' => ['nullable', 'string', 'max:1000'],
            'definition.*.amount' => ['nullable', 'integer', 'min:0'],
            'definition.*.payment_bearer' => ['nullable', 'string', 'in:employee,company,corporate_card,customer,other'],
            'definition.*.attributes' => ['nullable', 'array'],
        ];
    }

    private function authorizeWrite(Request $request, ExpenseEntryPreset $preset): void
    {
        $user = $request->user();

        if ($preset->visibility !== ExpenseEntryPreset::VISIBILITY_PERSONAL) {
            abort_unless(app(EffectiveAccessResolver::class)->hasGlobalPermission($user, 'expense_preset.manage'), Response::HTTP_FORBIDDEN,
                '全社共有・システム標準プリセットは経理・管理者のみ編集できます。');

            return;
        }

        abort_unless($preset->owner_user_id === $user->id, Response::HTTP_FORBIDDEN,
            '本人の個人用プリセットのみ編集できます。');
    }
}
