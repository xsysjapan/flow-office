<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\Attendance\Commands\EditEmployeeShiftAssignment;
use App\Domain\Attendance\Commands\GenerateEmployeeShiftAssignments;
use App\Domain\Attendance\Commands\PublishEmployeeShiftAssignments;
use App\Domain\Attendance\Services\ShiftScheduleReviewService;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftAssignmentResource;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 社員別勤務予定 (docs/19-implementation-phases.md Phase4)。
 * 会社カレンダーの日区分をベースにした一括生成(UC-C003)と、3交代制シフト表の
 * 日別パターン割当(UC-C004)の両方をここで扱う。
 */
class EmployeeShiftAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $assignments = EmployeeShiftAssignment::query()
            ->where('user_id', $data['user_id'])
            ->whereDate('work_date', '>=', $data['from'])
            ->whereDate('work_date', '<=', $data['to'])
            ->orderBy('work_date')
            ->get();

        return EmployeeShiftAssignmentResource::collection($assignments);
    }

    /**
     * UC-C003: work_stylesに紐づくカレンダーの日区分をもとに、指定期間の勤務予定を一括生成する。
     */
    public function generate(Request $request, CommandBus $commandBus): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_style_id' => ['required', 'integer', 'exists:work_styles,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $assignments = $commandBus->dispatch(new GenerateEmployeeShiftAssignments(
            userId: $data['user_id'],
            workStyleId: $data['work_style_id'],
            from: $data['from'],
            to: $data['to'],
            generatedByUserId: $request->user()->id,
        ));

        return EmployeeShiftAssignmentResource::collection($assignments);
    }

    /**
     * UC-C004 手順3〜4: 3交代制シフト表で、社員の特定日にシフトパターンを割り当てる。
     */
    public function assignPattern(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_style_id' => ['required', 'integer', 'exists:work_styles,id'],
            'work_date' => ['required', 'date'],
            'shift_pattern_id' => ['required', 'integer', 'exists:shift_patterns,id'],
            'is_legal_holiday' => ['boolean'],
            'is_company_holiday' => ['boolean'],
        ]);

        $assignment = $commandBus->dispatch(new AssignShiftPatternDay(
            userId: $data['user_id'],
            workDate: $data['work_date'],
            workStyleId: $data['work_style_id'],
            shiftPatternId: $data['shift_pattern_id'],
            isLegalHoliday: $data['is_legal_holiday'] ?? false,
            isCompanyHoliday: $data['is_company_holiday'] ?? false,
            assignedByUserId: $request->user()->id,
        ));

        return (new EmployeeShiftAssignmentResource($assignment))->response()->setStatusCode(201);
    }

    /**
     * UC-C004 手順5: 公開前に法定休日不足・連続勤務・月間予定時間を確認する(読み取り専用、警告のみ)。
     */
    public function review(Request $request, ShiftScheduleReviewService $reviewService): JsonResponse
    {
        $data = $request->validate([
            'department' => ['nullable', 'string'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'year_month' => ['required', 'date_format:Y-m'],
        ]);

        $userIds = $this->resolveTargetUserIds($data);

        return response()->json($reviewService->review($userIds, $data['year_month']));
    }

    /**
     * UC-C004 手順6: 3交代制シフト表を公開する。
     */
    public function publish(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'department' => ['nullable', 'string'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'year_month' => ['required', 'date_format:Y-m'],
        ]);

        $userIds = $this->resolveTargetUserIds($data);

        $result = $commandBus->dispatch(new PublishEmployeeShiftAssignments(
            userIds: $userIds,
            yearMonth: $data['year_month'],
            publishedByUserId: $request->user()->id,
        ));

        return response()->json($result);
    }

    /**
     * 勤務予定(所定労働時間)を編集する。1か月単位変形労働時間制で、特定の日だけ
     * あらかじめ8時間を超える所定労働時間を設定する場合などに使う。
     */
    public function update(Request $request, EmployeeShiftAssignment $employeeShiftAssignment, CommandBus $commandBus): EmployeeShiftAssignmentResource
    {
        $data = $request->validate([
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'planned_break_minutes' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string'],
        ]);

        $commandBus->dispatch(new EditEmployeeShiftAssignment(
            employeeShiftAssignmentId: $employeeShiftAssignment->id,
            plannedStartAt: $data['planned_start_at'] ?? null,
            plannedEndAt: $data['planned_end_at'] ?? null,
            plannedBreakMinutes: $data['planned_break_minutes'],
            reason: $data['reason'],
            editedByUserId: $request->user()->id,
        ));

        return new EmployeeShiftAssignmentResource($employeeShiftAssignment->refresh());
    }

    /**
     * @param  array{department?: ?string, user_ids?: ?list<int>}  $data
     * @return list<int>
     */
    private function resolveTargetUserIds(array $data): array
    {
        $userIds = collect($data['user_ids'] ?? []);

        if (! empty($data['department'])) {
            $userIds = $userIds->merge(
                User::query()->where('department', $data['department'])->pluck('id')
            );
        }

        return $userIds->unique()->values()->all();
    }
}
