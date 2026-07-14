<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\CreateRotationPattern;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RotationPatternResource;
use App\Models\EmployeeRotationAssignment;
use App\Models\RotationPattern;
use App\Models\WorkStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * 指示書 8.4節: 交代制勤務のローテーションパターン(A勤・B勤・C勤・休の繰り返し周期)。
 */
#[OA\Tag(name: 'ローテーションパターン', description: '交代制勤務のローテーション')]
class RotationPatternController extends Controller
{
    #[OA\Get(
        path: '/rotation-patterns',
        operationId: 'rotationPatterns.index',
        summary: 'ローテーションパターン一覧を取得する',
        tags: ['ローテーションパターン'],
        parameters: [new OA\Parameter(name: 'work_style_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'work_style_id' => ['nullable', 'integer', 'exists:work_styles,id'],
        ]);

        $query = RotationPattern::query()->with('items.shiftPattern')->orderBy('name');

        if (! empty($data['work_style_id'])) {
            $query->where('work_style_id', $data['work_style_id']);
        }

        return RotationPatternResource::collection($query->get());
    }

    #[OA\Post(
        path: '/rotation-patterns',
        operationId: 'rotationPatterns.store',
        summary: 'ローテーションパターンを作成する',
        tags: ['ローテーションパターン'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['work_style_id', 'name', 'items'], properties: [new OA\Property(property: 'work_style_id', type: 'integer'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'work_style_id' => ['required', 'integer', 'exists:work_styles,id'],
            'name' => ['required', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sequence' => ['required', 'integer', 'min:0'],
            'items.*.shift_pattern_id' => ['required', 'integer', 'exists:shift_patterns,id'],
        ]);

        $workStyle = WorkStyle::query()->findOrFail($data['work_style_id']);
        if (! $workStyle->is_shift_based) {
            throw ValidationException::withMessages(['work_style_id' => 'ローテーションはシフト制の勤務形態にのみ登録できます。']);
        }

        $sequences = collect($data['items'])->pluck('sequence')->sort()->values()->all();
        if ($sequences !== range(0, count($data['items']) - 1)) {
            throw ValidationException::withMessages(['items' => 'sequenceは0から始まる連番(重複なし)で指定してください。']);
        }

        $pattern = $commandBus->dispatch(new CreateRotationPattern(
            workStyleId: $data['work_style_id'],
            name: $data['name'],
            items: $data['items'],
            createdByUserId: $request->user()->id,
        ));

        return (new RotationPatternResource($pattern))->response()->setStatusCode(201);
    }

    /**
     * 指示書 8.9節 手順6・12.4節: 保存前・割当前に、開始日・開始位置から実際のカレンダーへ
     * 展開した結果をプレビューする(永続化しない)。
     */
    #[OA\Post(
        path: '/rotation-patterns/{rotationPattern}/preview',
        operationId: 'rotationPatterns.preview',
        summary: 'ローテーション展開をプレビューする',
        tags: ['ローテーションパターン'],
        parameters: [new OA\Parameter(name: 'rotationPattern', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['rotation_start_date', 'rotation_start_position', 'from', 'to'], properties: [new OA\Property(property: 'rotation_start_date', type: 'string', format: 'date'), new OA\Property(property: 'rotation_start_position', type: 'integer'), new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function preview(Request $request, RotationPattern $rotationPattern): JsonResponse
    {
        $data = $request->validate([
            'rotation_start_date' => ['required', 'date'],
            'rotation_start_position' => ['required', 'integer', 'min:0'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        if ($data['rotation_start_position'] >= $rotationPattern->cycle_length) {
            throw ValidationException::withMessages(['rotation_start_position' => '開始位置はローテーション周期内で指定してください。']);
        }

        $itemsBySequence = $rotationPattern->items()->with('shiftPattern')->get()->keyBy('sequence');
        $transientAssignment = new EmployeeRotationAssignment([
            'rotation_start_date' => $data['rotation_start_date'],
            'rotation_start_position' => $data['rotation_start_position'],
        ]);

        $period = Carbon::parse($data['from'])->toPeriod(Carbon::parse($data['to']));
        $days = [];

        foreach ($period as $date) {
            $sequenceIndex = $transientAssignment->sequenceIndexFor($date, $rotationPattern->cycle_length);
            $item = $itemsBySequence->get($sequenceIndex);

            $days[] = [
                'date' => $date->toDateString(),
                'sequence' => $sequenceIndex,
                'shift_pattern_id' => $item?->shift_pattern_id,
                'shift_pattern_name' => $item?->shiftPattern?->name,
                'shift_pattern_code' => $item?->shiftPattern?->code,
            ];
        }

        return response()->json(['days' => $days]);
    }
}
