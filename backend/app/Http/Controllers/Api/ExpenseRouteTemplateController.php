<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseRouteTemplateResource;
use App\Models\ExpenseRouteTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * UC-X002/UC-X003: 個人・全社共有の移動区間テンプレート管理。
 * personalは本人のみ、companyは経理・管理者のみが書き込みできる
 * (docs/30-usecases-expense.md)。
 */
#[OA\Tag(name: '経費移動区間テンプレート', description: '個人・全社共有の移動区間テンプレート管理')]
class ExpenseRouteTemplateController extends Controller
{
    #[OA\Get(
        path: '/expense-route-templates',
        operationId: 'expenseRouteTemplates.index',
        summary: '移動区間テンプレート一覧を取得する(本人のpersonal + 全社のcompanyをマージ)',
        tags: ['経費移動区間テンプレート'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $templates = ExpenseRouteTemplate::query()
            ->where('is_active', true)
            ->where(function ($query) use ($userId) {
                $query->where('scope', ExpenseRouteTemplate::SCOPE_COMPANY)
                    ->orWhere(function ($query) use ($userId) {
                        $query->where('scope', ExpenseRouteTemplate::SCOPE_PERSONAL)
                            ->where('employee_id', $userId);
                    });
            })
            ->orderBy('name')
            ->get();

        return ExpenseRouteTemplateResource::collection($templates);
    }

    #[OA\Post(
        path: '/expense-route-templates',
        operationId: 'expenseRouteTemplates.store',
        summary: '移動区間テンプレートを作成する',
        tags: ['経費移動区間テンプレート'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['scope', 'name', 'origin', 'destination', 'transport_type', 'amount', 'category_id'], properties: [new OA\Property(property: 'scope', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'origin', type: 'string'), new OA\Property(property: 'destination', type: 'string'), new OA\Property(property: 'transport_type', type: 'string'), new OA\Property(property: 'amount', type: 'integer'), new OA\Property(property: 'category_id', type: 'integer')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        if ($data['scope'] === ExpenseRouteTemplate::SCOPE_COMPANY) {
            abort_unless($user->hasRole('accounting_staff') || $user->hasRole('admin'), Response::HTTP_FORBIDDEN,
                '全社共有テンプレートは経理・管理者のみ登録できます。');
            $data['employee_id'] = null;
        } else {
            $data['employee_id'] = $user->id;
        }

        $data['created_by'] = $user->id;

        $template = ExpenseRouteTemplate::query()->create($data);

        return (new ExpenseRouteTemplateResource($template))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/expense-route-templates/{expenseRouteTemplate}',
        operationId: 'expenseRouteTemplates.update',
        summary: '移動区間テンプレートを更新する',
        tags: ['経費移動区間テンプレート'],
        parameters: [new OA\Parameter(name: 'expenseRouteTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'origin', 'destination', 'transport_type', 'amount', 'category_id'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'origin', type: 'string'), new OA\Property(property: 'destination', type: 'string'), new OA\Property(property: 'transport_type', type: 'string'), new OA\Property(property: 'amount', type: 'integer'), new OA\Property(property: 'category_id', type: 'integer')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, ExpenseRouteTemplate $expenseRouteTemplate): ExpenseRouteTemplateResource
    {
        $this->authorizeWrite($request, $expenseRouteTemplate);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'origin' => ['required', 'string', 'max:100'],
            'destination' => ['required', 'string', 'max:100'],
            'transport_type' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'exists:expense_categories,id'],
            'is_active' => ['boolean'],
        ]);

        $expenseRouteTemplate->update($data);

        return new ExpenseRouteTemplateResource($expenseRouteTemplate);
    }

    #[OA\Delete(
        path: '/expense-route-templates/{expenseRouteTemplate}',
        operationId: 'expenseRouteTemplates.destroy',
        summary: '移動区間テンプレートを削除する',
        tags: ['経費移動区間テンプレート'],
        parameters: [new OA\Parameter(name: 'expenseRouteTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function destroy(Request $request, ExpenseRouteTemplate $expenseRouteTemplate): Response
    {
        $this->authorizeWrite($request, $expenseRouteTemplate);

        $expenseRouteTemplate->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'scope' => ['required', 'string', 'in:personal,company'],
            'name' => ['required', 'string', 'max:100'],
            'origin' => ['required', 'string', 'max:100'],
            'destination' => ['required', 'string', 'max:100'],
            'transport_type' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'exists:expense_categories,id'],
            'is_active' => ['boolean'],
        ]);
    }

    private function authorizeWrite(Request $request, ExpenseRouteTemplate $template): void
    {
        $user = $request->user();

        if ($template->scope === ExpenseRouteTemplate::SCOPE_COMPANY) {
            abort_unless($user->hasRole('accounting_staff') || $user->hasRole('admin'), Response::HTTP_FORBIDDEN,
                '全社共有テンプレートは経理・管理者のみ編集できます。');

            return;
        }

        abort_unless($template->employee_id === $user->id, Response::HTTP_FORBIDDEN,
            '本人の個人用テンプレートのみ編集できます。');
    }
}
