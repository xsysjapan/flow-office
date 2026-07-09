<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceDayResource;
use App\Http\Resources\AttendanceMonthResource;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * UC-A001〜UC-A011: 日次・週次・月次勤怠。「今日」の判定は社員本人のタイムゾーンを
 * 基準にする (docs/06-usecases-auth.md UC-003)。
 */
class AttendanceController extends Controller
{
    public function today(Request $request): AttendanceDayResource
    {
        $user = $request->user();
        $today = Carbon::today($user->timezone)->toDateString();

        $day = AttendanceDay::query()
            ->with(['breaks', 'calculation', 'user'])
            ->where('user_id', $user->id)
            ->whereDate('work_date', $today)
            ->first();

        $shift = EmployeeShiftAssignment::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $today)
            ->first();

        if ($day === null) {
            $day = new AttendanceDay([
                'user_id' => $user->id,
                'work_date' => $today,
                'status' => 'not_started',
            ]);
            $day->setRelation('breaks', collect());
            $day->setRelation('user', $user);
        }

        $day->setAttribute('planned_start_at', LocalDateTime::toIso8601($shift?->planned_start_at, $user->timezone));
        $day->setAttribute('planned_end_at', LocalDateTime::toIso8601($shift?->planned_end_at, $user->timezone));

        return new AttendanceDayResource($day);
    }

    public function clockIn(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new ClockIn($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation', 'user']));
    }

    public function startBreak(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new StartBreak($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation', 'user']));
    }

    public function endBreak(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new EndBreak($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation', 'user']));
    }

    public function clockOut(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new ClockOut($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation', 'user']));
    }

    /**
     * UC-A006: 週次勤怠を編集する(日次勤怠一覧の取得のみをここで提供し、
     * 保存はUC-A005の日次編集エンドポイントに委ねる。週次は独立データを持たない)。
     */
    public function week(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['start_date' => ['required', 'date']]);
        $start = Carbon::parse($data['start_date'])->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        $days = AttendanceDay::query()
            ->with(['breaks', 'calculation', 'user'])
            ->where('user_id', $request->user()->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->orderBy('work_date')
            ->get();

        return AttendanceDayResource::collection($days);
    }

    public function showDay(AttendanceDay $attendanceDay): AttendanceDayResource
    {
        return new AttendanceDayResource($attendanceDay->load(['breaks', 'calculation', 'user']));
    }

    public function updateDay(Request $request, AttendanceDay $attendanceDay, CommandBus $commandBus): AttendanceDayResource
    {
        $data = $request->validate([
            'actual_start_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'actual_end_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'breaks' => ['array'],
            'breaks.*.start' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'breaks.*.end' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'work_type' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
        ]);

        $commandBus->dispatch(new EditAttendanceDay(
            attendanceDayId: $attendanceDay->id,
            actualStartAt: $data['actual_start_at'] ?? null,
            actualEndAt: $data['actual_end_at'] ?? null,
            breaks: $data['breaks'] ?? [],
            workType: $data['work_type'] ?? null,
            note: $data['note'] ?? null,
            reason: $data['reason'],
            editedByUserId: $request->user()->id,
        ));

        return new AttendanceDayResource($attendanceDay->refresh()->load(['breaks', 'calculation', 'user']));
    }

    /**
     * UC-A007: 月次勤怠を確認する。
     */
    public function month(Request $request, string $yearMonth): array
    {
        $userId = $request->user()->id;

        $days = AttendanceDay::query()
            ->with(['breaks', 'calculation', 'user'])
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->orderBy('work_date')
            ->get();

        $month = AttendanceMonth::query()
            ->where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        return [
            'days' => AttendanceDayResource::collection($days),
            'month' => $month ? new AttendanceMonthResource($month) : null,
        ];
    }

    public function submitMonth(Request $request, string $yearMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $data = $request->validate(['approver_user_id' => ['required', 'integer', 'exists:users,id']]);

        $month = $commandBus->dispatch(new SubmitAttendanceMonth(
            userId: $request->user()->id,
            yearMonth: $yearMonth,
            approverUserId: $data['approver_user_id'],
        ));

        return new AttendanceMonthResource($month->load('approver'));
    }

    public function approveMonth(Request $request, AttendanceMonth $attendanceMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $commandBus->dispatch(new ApproveAttendanceMonth($attendanceMonth->id, $request->user()->id));

        return new AttendanceMonthResource($attendanceMonth->refresh()->load('approver'));
    }

    public function returnMonth(Request $request, AttendanceMonth $attendanceMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnAttendanceMonth($attendanceMonth->id, $request->user()->id, $data['comment']));

        return new AttendanceMonthResource($attendanceMonth->refresh()->load('approver'));
    }

    /**
     * UC-A011: 管理部が月次勤怠を締める。
     */
    public function closeMonth(Request $request, AttendanceMonth $attendanceMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $commandBus->dispatch(new CloseAttendanceMonth($attendanceMonth->id, $request->user()->id));

        return new AttendanceMonthResource($attendanceMonth->refresh());
    }

    public function myMonths(Request $request): AnonymousResourceCollection
    {
        $months = AttendanceMonth::query()
            ->with('approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('year_month')
            ->get();

        return AttendanceMonthResource::collection($months);
    }

    public function monthsToApprove(Request $request): AnonymousResourceCollection
    {
        $months = AttendanceMonth::query()
            ->with('approver', 'user')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', 'submitted')
            ->orderByDesc('year_month')
            ->get();

        return AttendanceMonthResource::collection($months);
    }
}
