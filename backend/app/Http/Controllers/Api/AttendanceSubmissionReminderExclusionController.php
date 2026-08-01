<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\ExcludeAttendanceSubmissionReminder;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceSubmissionReminderExclusionResource;
use App\Models\AttendanceSubmissionReminderExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * 勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の対象から、特定の社員×特定の年月を
 * 個別に除外する管理機能。「そもそもその月は提出対象ではなかった」という誤送信ケースなど、
 * `usage_start_date`/`hire_date`による除外条件では対応できない例外的なケース向けの汎用手段。
 * ADMINロールのみ操作できる(routes/api.php参照)。
 */
#[OA\Tag(name: '勤怠未提出督促の個別除外', description: '特定の社員×年月を勤怠未提出督促の対象から除外する')]
class AttendanceSubmissionReminderExclusionController extends Controller
{
    #[OA\Get(
        path: '/attendance-submission-reminder-exclusions',
        operationId: 'attendanceSubmissionReminderExclusions.index',
        summary: '勤怠未提出督促の個別除外一覧を取得する',
        tags: ['勤怠未提出督促の個別除外'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $exclusions = AttendanceSubmissionReminderExclusion::query()
            ->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->with('user')
            ->orderByDesc('year_month')
            ->get();

        return AttendanceSubmissionReminderExclusionResource::collection($exclusions);
    }

    #[OA\Post(
        path: '/attendance-submission-reminder-exclusions',
        operationId: 'attendanceSubmissionReminderExclusions.store',
        summary: '特定の社員×年月を勤怠未提出督促の対象から除外する',
        tags: ['勤怠未提出督促の個別除外'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'year_month', 'reason'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'year_month', type: 'string'), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'year_month' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string'],
        ]);

        $exclusion = $commandBus->dispatch(new ExcludeAttendanceSubmissionReminder(
            userId: $data['user_id'],
            yearMonth: $data['year_month'],
            reason: $data['reason'],
            excludedByUserId: $request->user()->id,
        ));

        return (new AttendanceSubmissionReminderExclusionResource($exclusion->load('user')))
            ->response()->setStatusCode(201);
    }
}
