<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Services\FlexSettlementSummaryCalculator;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceDayResource;
use App\Http\Resources\AttendanceMonthResource;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Support\LocalDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * UC-A001〜UC-A011: 日次・週次・月次勤怠。「今日」の判定は社員本人のタイムゾーンを
 * 基準にする (docs/06-usecases-auth.md UC-003)。
 */
#[OA\Tag(name: '勤怠', description: '日次・週次・月次勤怠')]
class AttendanceController extends Controller
{
    #[OA\Get(
        path: '/attendance/today',
        operationId: 'attendance.today',
        summary: '今日の勤怠を取得する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function today(Request $request): AttendanceDayResource
    {
        $user = $request->user();
        $today = Carbon::today($user->timezone)->toDateString();

        $day = AttendanceDay::query()
            ->with(['breaks', 'calculation'])
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
        }

        // 勤務予定(shift)は勤務実績とは異なり出張先の現地時刻を持たないため、一般の日時と
        // 同様にシステムのデフォルトタイムゾーンのオフセットを付与する (docs/03-architecture.md 3.4)。
        $defaultTimezone = SystemSetting::current()->default_timezone;
        $day->setAttribute('planned_start_at', LocalDateTime::toIso8601($shift?->planned_start_at, $defaultTimezone));
        $day->setAttribute('planned_end_at', LocalDateTime::toIso8601($shift?->planned_end_at, $defaultTimezone));

        return new AttendanceDayResource($day);
    }

    #[OA\Post(
        path: '/attendance/clock-in',
        operationId: 'attendance.clockIn',
        summary: '出勤打刻する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function clockIn(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new ClockIn($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation']));
    }

    #[OA\Post(
        path: '/attendance/break/start',
        operationId: 'attendance.startBreak',
        summary: '休憩を開始する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function startBreak(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new StartBreak($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation']));
    }

    #[OA\Post(
        path: '/attendance/break/end',
        operationId: 'attendance.endBreak',
        summary: '休憩を終了する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function endBreak(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new EndBreak($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation']));
    }

    #[OA\Post(
        path: '/attendance/clock-out',
        operationId: 'attendance.clockOut',
        summary: '退勤打刻する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function clockOut(Request $request, CommandBus $commandBus): AttendanceDayResource
    {
        $day = $commandBus->dispatch(new ClockOut($request->user()->id));

        return new AttendanceDayResource($day->load(['breaks', 'calculation']));
    }

    /**
     * UC-A006: 週次勤怠を編集する(日次勤怠一覧の取得のみをここで提供し、
     * 保存はUC-A005の日次編集エンドポイントに委ねる。週次は独立データを持たない)。
     */
    #[OA\Get(
        path: '/attendance/week',
        operationId: 'attendance.week',
        summary: '週次勤怠を取得する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'start_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function week(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['start_date' => ['required', 'date']]);
        $requestedDate = Carbon::parse($data['start_date']);
        $weekStartsOn = $this->resolveWeekStartsOn($request->user()->id, $requestedDate);

        $start = $requestedDate->copy();
        while ($start->isoWeekday() !== $weekStartsOn) {
            $start->subDay();
        }
        $end = $start->copy()->addDays(6);

        $days = AttendanceDay::query()
            ->with(['breaks', 'calculation'])
            ->where('user_id', $request->user()->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->orderBy('work_date')
            ->get();

        return AttendanceDayResource::collection($days);
    }

    /**
     * 週次勤怠編集画面(UC-A006)の週開始日を、法定休日判定(LegalHolidayRequirementChecker)
     * と同じ基準(勤務形態に紐づくカレンダーの`week_starts_on`)に揃える。勤務予定が
     * まだ無い場合はカレンダーの既定値と同じ月曜(ISO: 1)を使う。
     */
    private function resolveWeekStartsOn(int $userId, Carbon $referenceDate): int
    {
        $workStyle = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $referenceDate->copy()->subDays(6)->toDateString())
            ->whereDate('work_date', '<=', $referenceDate->copy()->addDays(6)->toDateString())
            ->with('workStyle.calendar')
            ->orderBy('work_date')
            ->first()
            ?->workStyle;

        return $workStyle?->calendar?->week_starts_on ?? 1;
    }

    #[OA\Get(
        path: '/attendance/days/{attendanceDay}',
        operationId: 'attendance.days.show',
        summary: '日次勤怠詳細を取得する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceDay', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function showDay(Request $request, AttendanceDay $attendanceDay): AttendanceDayResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendanceDay->user_id, '他の社員の日次勤怠を閲覧する権限がありません。');

        return new AttendanceDayResource($attendanceDay->load(['breaks', 'calculation']));
    }

    /**
     * 出勤日(attendance_days)を任意の勤務日に新規作成する。打刻(attendance_punches)とは
     * 勤務日が同じというだけの緩い関係しかなく、打刻の有無にかかわらず作成できる。
     */
    #[OA\Post(
        path: '/attendance/days',
        operationId: 'attendance.days.store',
        summary: '日次勤怠を作成する',
        tags: ['勤怠'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'work_date', 'reason'], properties: [new OA\Property(property: 'user_id', type: 'integer'), new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'actual_start_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'actual_end_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'work_type', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeDay(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'actual_start_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'actual_end_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'breaks' => ['array'],
            'breaks.*.start' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'breaks.*.end' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'work_type' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
        ]);

        $this->abortUnlessOwnerOrAdmin($request, $data['user_id'], '他の社員の出勤日を作成する権限がありません。');

        $day = $commandBus->dispatch(new CreateAttendanceDay(
            userId: $data['user_id'],
            workDate: $data['work_date'],
            actualStartAt: $data['actual_start_at'] ?? null,
            actualEndAt: $data['actual_end_at'] ?? null,
            breaks: $data['breaks'] ?? [],
            workType: $data['work_type'] ?? null,
            note: $data['note'] ?? null,
            reason: $data['reason'],
            createdByUserId: $request->user()->id,
        ));

        return (new AttendanceDayResource($day->load(['breaks', 'calculation'])))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/attendance/days/{attendanceDay}',
        operationId: 'attendance.days.update',
        summary: '日次勤怠を編集する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceDay', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'actual_start_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'actual_end_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'work_type', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function updateDay(Request $request, AttendanceDay $attendanceDay, CommandBus $commandBus): AttendanceDayResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendanceDay->user_id, '他の社員の日次勤怠を編集する権限がありません。');

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

        return new AttendanceDayResource($attendanceDay->refresh()->load(['breaks', 'calculation']));
    }

    /**
     * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能で、
     * 承認済み・締め済みの日次勤怠は削除できない。
     */
    #[OA\Delete(
        path: '/attendance/days/{attendanceDay}',
        operationId: 'attendance.days.destroy',
        summary: '日次勤怠を削除する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceDay', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function destroyDay(Request $request, AttendanceDay $attendanceDay, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendanceDay->user_id, '他の社員の日次勤怠を削除する権限がありません。');

        $data = $request->validate(['reason' => ['required', 'string']]);

        $commandBus->dispatch(new DeleteAttendanceDay(
            attendanceDayId: $attendanceDay->id,
            reason: $data['reason'],
            deletedByUserId: $request->user()->id,
        ));

        return response()->json(['deleted' => true]);
    }

    /**
     * UC-A007: 月次勤怠を確認する。
     */
    #[OA\Get(
        path: '/attendance/months/{yearMonth}',
        operationId: 'attendance.months.show',
        summary: '月次勤怠を取得する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'yearMonth', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function month(Request $request, string $yearMonth): array
    {
        $userId = $request->user()->id;

        $days = AttendanceDay::query()
            ->with(['breaks', 'calculation'])
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
            // フレックスタイム制(指示書 7.6節)のみ非nullを返す。attendance_monthsの提出前
            // (未提出でまだ行が存在しない月)でも表示できるよう、monthとは独立して都度計算する。
            'flex_settlement_summary' => app(FlexSettlementSummaryCalculator::class)->calculateForMonth($userId, $yearMonth),
            // 9区分(所定内残業/法定外残業/月60時間超残業/深夜労働等)の月合計。提出前でも
            // 進捗の目安として都度計算する(提出後はattendance_months.snapshot_jsonが確定値)。
            'monthly_calculation_totals' => app(MonthlyOvertimeCalculator::class)->calculateCategoryTotals($userId, $yearMonth),
        ];
    }

    #[OA\Post(
        path: '/attendance/months/{yearMonth}/submit',
        operationId: 'attendance.months.submit',
        summary: '月次勤怠を提出する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'yearMonth', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['approver_user_id'], properties: [new OA\Property(property: 'approver_user_id', type: 'integer')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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

    #[OA\Post(
        path: '/attendance-months/{attendanceMonth}/approve',
        operationId: 'attendanceMonths.approve',
        summary: '月次勤怠を承認する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceMonth', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveMonth(Request $request, AttendanceMonth $attendanceMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $commandBus->dispatch(new ApproveAttendanceMonth($attendanceMonth->id, $request->user()->id));

        return new AttendanceMonthResource($attendanceMonth->refresh()->load('approver'));
    }

    #[OA\Post(
        path: '/attendance-months/{attendanceMonth}/return',
        operationId: 'attendanceMonths.return',
        summary: '月次勤怠を差し戻す',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceMonth', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnMonth(Request $request, AttendanceMonth $attendanceMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnAttendanceMonth($attendanceMonth->id, $request->user()->id, $data['comment']));

        return new AttendanceMonthResource($attendanceMonth->refresh()->load('approver'));
    }

    /**
     * UC-A011: 管理部が月次勤怠を締める。
     */
    #[OA\Post(
        path: '/attendance-months/{attendanceMonth}/close',
        operationId: 'attendanceMonths.close',
        summary: '月次勤怠を締める',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceMonth', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function closeMonth(Request $request, AttendanceMonth $attendanceMonth, CommandBus $commandBus): AttendanceMonthResource
    {
        $commandBus->dispatch(new CloseAttendanceMonth($attendanceMonth->id, $request->user()->id));

        return new AttendanceMonthResource($attendanceMonth->refresh());
    }

    #[OA\Get(
        path: '/attendance/months/mine',
        operationId: 'attendance.months.mine',
        summary: '自分の月次勤怠一覧を取得する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myMonths(Request $request): AnonymousResourceCollection
    {
        $months = AttendanceMonth::query()
            ->with('approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('year_month')
            ->get();

        return AttendanceMonthResource::collection($months);
    }

    /**
     * UC-A010: 自分が承認者に指定された提出済み月次に加え、UC-A011: 管理部
     * (admin・hr_staff)は承認者を問わず全社員の承認済み(締め処理待ち)月次も一覧できる。
     */
    #[OA\Get(
        path: '/attendance/months/to-approve',
        operationId: 'attendance.months.toApprove',
        summary: '承認対象の月次勤怠一覧を取得する',
        tags: ['勤怠'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function monthsToApprove(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $canClose = $user->hasRole(Role::ADMIN) || $user->hasRole(Role::HR_STAFF);

        $months = AttendanceMonth::query()
            ->with('approver', 'user')
            ->where(function ($query) use ($user, $canClose) {
                $query->where('approver_user_id', $user->id)->where('status', 'submitted');
                if ($canClose) {
                    $query->orWhere('status', 'approved');
                }
            })
            ->orderByDesc('year_month')
            ->get();

        return AttendanceMonthResource::collection($months);
    }
}
