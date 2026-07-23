<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\AssignEmployeeRotation;
use App\Domain\Attendance\Commands\GenerateRotationShiftAssignments;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeRotationAssignmentResource;
use App\Http\Resources\EmployeeShiftAssignmentResource;
use App\Models\EmployeeRotationAssignment;
use App\Models\RotationPattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * 指示書 8.5節・8.7節: 社員ごとのローテーション基準の割当と、そこからの勤務予定一括生成。
 */
#[OA\Tag(name: 'ローテーション割当', description: '社員別ローテーション基準')]
class EmployeeRotationAssignmentController extends Controller
{
    #[OA\Get(
        path: '/employee-rotation-assignments',
        operationId: 'employeeRotationAssignments.show',
        summary: '社員のローテーション割当を取得する',
        tags: ['ローテーション割当'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
        ]);

        $assignment = EmployeeRotationAssignment::query()
            ->where('user_id', $data['user_id'])
            ->with('rotationPattern')
            ->first();

        return response()->json($assignment ? new EmployeeRotationAssignmentResource($assignment) : null);
    }

    #[OA\Post(
        path: '/employee-rotation-assignments',
        operationId: 'employeeRotationAssignments.store',
        summary: '社員にローテーションを割り当てる',
        tags: ['ローテーション割当'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'rotation_pattern_id', 'rotation_start_date', 'rotation_start_position'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'rotation_pattern_id', type: 'integer'), new OA\Property(property: 'rotation_start_date', type: 'string', format: 'date'), new OA\Property(property: 'rotation_start_position', type: 'integer')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'rotation_pattern_id' => ['required', 'integer', 'exists:rotation_patterns,id'],
            'rotation_start_date' => ['required', 'date'],
            'rotation_start_position' => ['required', 'integer', 'min:0'],
        ]);

        $pattern = RotationPattern::query()->findOrFail($data['rotation_pattern_id']);
        if ($data['rotation_start_position'] >= $pattern->cycle_length) {
            throw ValidationException::withMessages(['rotation_start_position' => '開始位置はローテーション周期内で指定してください。']);
        }

        $assignment = $commandBus->dispatch(new AssignEmployeeRotation(
            userId: $data['user_id'],
            rotationPatternId: $data['rotation_pattern_id'],
            rotationStartDate: $data['rotation_start_date'],
            rotationStartPosition: $data['rotation_start_position'],
            assignedByUserId: $request->user()->id,
        ));

        return (new EmployeeRotationAssignmentResource($assignment->load('rotationPattern')))
            ->response()->setStatusCode(201);
    }

    /**
     * 指示書 8.7節・8.8節: ローテーションから指定期間分の勤務予定を一括生成する。
     * 既定(`skip_edited`)では、既に個別上書き済みの日・実績のある日・ロックされた日を
     * 自動上書きしない(安全な既定値)。
     */
    #[OA\Post(
        path: '/employee-rotation-assignments/generate',
        operationId: 'employeeRotationAssignments.generate',
        summary: 'ローテーションから勤務予定を生成する',
        tags: ['ローテーション割当'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'from', 'to'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date'), new OA\Property(property: 'overwrite_mode', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function generate(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'overwrite_mode' => ['nullable', Rule::in([
                GenerateRotationShiftAssignments::OVERWRITE_MODE_SKIP_EDITED,
                GenerateRotationShiftAssignments::OVERWRITE_MODE_OVERWRITE_ALL,
            ])],
        ]);

        $result = $commandBus->dispatch(new GenerateRotationShiftAssignments(
            userId: $data['user_id'],
            from: $data['from'],
            to: $data['to'],
            overwriteMode: $data['overwrite_mode'] ?? GenerateRotationShiftAssignments::OVERWRITE_MODE_SKIP_EDITED,
            generatedByUserId: $request->user()->id,
        ));

        return response()->json([
            'generated' => EmployeeShiftAssignmentResource::collection($result['generated']),
            'generated_count' => $result['generated']->count(),
            'skipped_dates' => $result['skipped_dates'],
        ]);
    }
}
