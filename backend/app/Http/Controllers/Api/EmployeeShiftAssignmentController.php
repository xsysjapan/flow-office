<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftAssignmentResource;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkStyle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * 社員別勤務予定 (docs/19-implementation-phases.md Phase4)。
 * 会社カレンダーの日区分をベースに、指定期間分の勤務予定を一括生成する。
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
     * work_stylesに紐づくカレンダーの日区分をもとに、指定期間の勤務予定を一括生成する。
     */
    public function generate(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_style_id' => ['required', 'integer', 'exists:work_styles,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $workStyle = WorkStyle::query()->with('calendar.days')->findOrFail($data['work_style_id']);
        $calendarDaysByDate = $workStyle->calendar->days->keyBy(fn ($day) => $day->date->toDateString());

        $period = Carbon::parse($data['from'])->toPeriod(Carbon::parse($data['to']));
        $assignments = [];

        foreach ($period as $date) {
            $calendarDay = $calendarDaysByDate->get($date->toDateString());
            $isWorkingDay = $calendarDay?->is_working_day ?? true;

            // 'work_date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $assignment = EmployeeShiftAssignment::query()
                ->where('user_id', $data['user_id'])
                ->whereDate('work_date', $date->toDateString())
                ->first() ?? new EmployeeShiftAssignment([
                    'user_id' => $data['user_id'],
                    'work_date' => $date->toDateString(),
                ]);

            $assignment->fill([
                'work_style_id' => $workStyle->id,
                'day_type' => $calendarDay?->day_type ?? 'weekday',
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $calendarDay?->is_legal_holiday ?? false,
                'is_company_holiday' => $calendarDay?->is_company_holiday ?? false,
                'planned_start_at' => $isWorkingDay && $workStyle->default_start_time
                    ? $date->copy()->setTimeFromTimeString($workStyle->default_start_time) : null,
                'planned_end_at' => $isWorkingDay && $workStyle->default_end_time
                    ? $date->copy()->setTimeFromTimeString($workStyle->default_end_time) : null,
                'planned_break_minutes' => $isWorkingDay ? $workStyle->default_break_minutes : 0,
            ])->save();

            $assignments[] = $assignment;
        }

        return EmployeeShiftAssignmentResource::collection(collect($assignments));
    }
}
