<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\Attendance\Commands\AdjustAttendanceDailyCalculation;
use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Commands\GeneratePatternAttendanceDays;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Services\AttendanceApproverAccess;
use App\Domain\Attendance\Services\AttendanceDayDefaultsResolver;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\FlexSettlementSummaryCalculator;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\Attendance\Services\PaidLeaveApprovalGuard;
use App\Domain\Attendance\Services\WeeklyPatternResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceDayResource;
use App\Http\Resources\AttendanceMonthResource;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveUsage;
use App\Models\SystemSetting;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use App\Models\WorkLocationType;
use App\Support\LocalDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
            ->with(['breaks', 'leaveSegments', 'calculation'])
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
            $day->setRelation('leaveSegments', collect());
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

        return new AttendanceDayResource($day->load(['breaks', 'leaveSegments', 'calculation']));
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

        return new AttendanceDayResource($day->load(['breaks', 'leaveSegments', 'calculation']));
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

        return new AttendanceDayResource($day->load(['breaks', 'leaveSegments', 'calculation']));
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

        return new AttendanceDayResource($day->load(['breaks', 'leaveSegments', 'calculation']));
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
        parameters: [new OA\Parameter(name: 'start_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'user_id', in: 'query', required: false, description: '省略時は自分自身。他の社員を指定できるのはadmin、またはその週が含まれる年月の承認者のみ', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function week(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);
        $requestedUserId = $data['user_id'] ?? $request->user()->id;
        $requestedDate = Carbon::parse($data['start_date']);
        $weekStartsOn = $this->resolveWeekStartsOn($requestedUserId, $requestedDate);

        $start = $requestedDate->copy();
        while ($start->isoWeekday() !== $weekStartsOn) {
            $start->subDay();
        }
        $end = $start->copy()->addDays(6);

        $targetUserId = $this->resolveViewableUserId(
            $request,
            $data['user_id'] ?? null,
            array_unique([$start->format('Y-m'), $end->format('Y-m')]),
            '他の社員の週次勤怠を閲覧する権限がありません。',
        );

        $days = AttendanceDay::query()
            ->with(['breaks', 'leaveSegments', 'calculation', 'specialLeaveUsages.grant.specialLeaveType', 'specialLeaveUsages.request.specialLeaveType'])
            ->where('user_id', $targetUserId)
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
    private function resolveWeekStartsOn(string $userId, Carbon $referenceDate): int
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

    /**
     * 日次・週次・月次勤怠の閲覧のみを、本人・管理者に加えて「対象の年月の承認者に指定
     * されている場合」まで許可する(UC-A009)。書き込み系エンドポイントは引き続き
     * `resolveTargetUserId`/`abortUnlessOwnerOrAdmin`(本人・管理者のみ)を使うこと。
     *
     * @param  string[]  $yearMonths  対象の年月の候補(週次は月をまたぐことがあるため複数渡せる)
     */
    private function resolveViewableUserId(Request $request, ?string $requestedUserId, array $yearMonths, string $message): string
    {
        $userId = $requestedUserId ?? $request->user()->id;
        $this->abortUnlessViewable($request, $userId, $yearMonths, $message);

        return $userId;
    }

    /** @param  string[]  $yearMonths */
    private function abortUnlessViewable(Request $request, string $ownerId, array $yearMonths, string $message): void
    {
        if ($ownerId === $request->user()->id) {
            return;
        }

        $isAdmin = $this->currentTokenHasFullAccess($request)
            && app(EffectiveAccessResolver::class)->hasGlobalPermission($request->user(), 'attendance.read');
        $isApprover = app(AttendanceApproverAccess::class)->isApproverForAnyYearMonth($request->user()->id, $ownerId, $yearMonths);

        abort_if(! $isAdmin && ! $isApprover, 403, $message);
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
        $this->abortUnlessViewable(
            $request,
            $attendanceDay->user_id,
            [$attendanceDay->work_date->format('Y-m')],
            '他の社員の日次勤怠を閲覧する権限がありません。',
        );

        return new AttendanceDayResource($attendanceDay->load(['breaks', 'leaveSegments', 'calculation']));
    }

    /**
     * 日次勤怠の入力画面(未入力の日)を開いた際の初期値を返す。打刻→勤務予定(休憩を含む)→
     * システムの初期設定、の優先順位で解決する(AttendanceDayDefaultsResolver参照)。
     * 保存されるまでは正データを変更しない、あくまで入力欄への提案。
     */
    #[OA\Get(
        path: '/attendance/day-defaults',
        operationId: 'attendance.dayDefaults',
        summary: '日次勤怠入力の初期値を取得する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'work_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function dayDefaults(Request $request, AttendanceDayDefaultsResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'work_date' => ['required', 'date'],
        ]);

        $this->abortUnlessOwnerOrAdmin($request, $data['user_id'], '他の社員の日次勤怠の初期値を参照する権限がありません。');

        return response()->json($resolver->resolve($data['user_id'], $data['work_date']));
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'work_date', 'reason'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'actual_start_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'actual_end_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'work_type', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'leave_segments', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeDay(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'actual_start_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'actual_end_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'breaks' => ['array'],
            'breaks.*.start' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'breaks.*.end' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'work_type' => ['nullable', 'string'],
            'work_location_type' => ['nullable', Rule::in(WorkLocationType::values())],
            'note' => ['nullable', 'string'],
            'leave_segments' => ['array'],
            'leave_segments.*.start' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'leave_segments.*.end' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'leave_segments.*.note' => ['nullable', 'string'],
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
            leaveSegments: $data['leave_segments'] ?? [],
            reason: $data['reason'],
            createdByUserId: $request->user()->id,
            workLocationType: $data['work_location_type'] ?? null,
        ));

        return (new AttendanceDayResource($day->load(['breaks', 'leaveSegments', 'calculation'])))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/attendance/days/{attendanceDay}',
        operationId: 'attendance.days.update',
        summary: '日次勤怠を編集する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceDay', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'actual_start_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'actual_end_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'work_type', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'leave_segments', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'reason', type: 'string')])),
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
            'work_location_type' => ['nullable', Rule::in(WorkLocationType::values())],
            'note' => ['nullable', 'string'],
            'leave_segments' => ['array'],
            'leave_segments.*.start' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'leave_segments.*.end' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'leave_segments.*.note' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
        ]);

        $commandBus->dispatch(new EditAttendanceDay(
            attendanceDayId: $attendanceDay->id,
            actualStartAt: $data['actual_start_at'] ?? null,
            actualEndAt: $data['actual_end_at'] ?? null,
            breaks: $data['breaks'] ?? [],
            workType: $data['work_type'] ?? null,
            note: $data['note'] ?? null,
            leaveSegments: $data['leave_segments'] ?? [],
            reason: $data['reason'],
            editedByUserId: $request->user()->id,
            workLocationType: $data['work_location_type'] ?? null,
            workLocationTypeProvided: $request->has('work_location_type'),
        ));

        return new AttendanceDayResource($attendanceDay->refresh()->load(['breaks', 'leaveSegments', 'calculation']));
    }

    /**
     * 週次・月次一括入力: 曜日ごとの実際の出退勤・休憩時刻(+日単位の上書き)から、
     * 指定期間の実績をどう展開するかを確認する(永続化しない)。
     */
    #[OA\Post(
        path: '/attendance/days/preview-pattern',
        operationId: 'attendance.days.previewPattern',
        summary: '週次・月次パターンの実績展開結果をプレビューする',
        tags: ['勤怠'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['from', 'to', 'utc_offset', 'weekly_pattern'], properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date'), new OA\Property(property: 'utc_offset', type: 'string'), new OA\Property(property: 'weekly_pattern', type: 'object'), new OA\Property(property: 'day_overrides', type: 'object', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function previewAttendancePattern(Request $request, AttendanceEditGuard $guard): JsonResponse
    {
        $data = $request->validate($this->attendancePatternValidationRules());

        $userId = $request->user()->id;
        $resolver = new WeeklyPatternResolver($data['weekly_pattern'], $data['day_overrides'] ?? []);
        $period = Carbon::parse($data['from'])->toPeriod(Carbon::parse($data['to']));
        $days = [];

        foreach ($period as $date) {
            $resolved = $resolver->resolve($date);
            $value = $resolved['value'] ?? null;

            if ($value === null) {
                continue;
            }

            $hasExistingDay = AttendanceDay::query()
                ->where('user_id', $userId)
                ->whereDate('work_date', $date->toDateString())
                ->exists();

            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->dayOfWeekIso,
                'start_time' => $value['start_time'],
                'end_time' => $value['end_time'],
                'break_start_time' => $value['break_start_time'] ?? null,
                'break_end_time' => $value['break_end_time'] ?? null,
                'has_existing_day' => $hasExistingDay,
                'is_locked' => ! $guard->isMutable(null, $userId, $date->toDateString()),
            ];
        }

        return response()->json(['days' => $days]);
    }

    /**
     * 週次・月次一括入力: 曜日ごとの実際の出退勤・休憩時刻(+日単位の上書き)を指定期間へ
     * 一括展開し、実績(attendance_days)を作成・更新する。日次ロジックは複製せず、
     * 日ごとに既存の単日Command(CreateAttendanceDay/EditAttendanceDay)を呼び出す
     * (GeneratePatternAttendanceDaysHandler参照)。
     */
    #[OA\Post(
        path: '/attendance/days/generate-pattern',
        operationId: 'attendance.days.generatePattern',
        summary: '週次・月次パターンから実績を一括作成・更新する',
        tags: ['勤怠'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'from', 'to', 'utc_offset', 'weekly_pattern', 'reason'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date'), new OA\Property(property: 'utc_offset', type: 'string'), new OA\Property(property: 'weekly_pattern', type: 'object'), new OA\Property(property: 'day_overrides', type: 'object', nullable: true), new OA\Property(property: 'overwrite_mode', type: 'string', nullable: true), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function generateAttendancePattern(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            ...$this->attendancePatternValidationRules(),
            'overwrite_mode' => ['nullable', Rule::in([
                GeneratePatternAttendanceDays::OVERWRITE_MODE_SKIP_EXISTING,
                GeneratePatternAttendanceDays::OVERWRITE_MODE_OVERWRITE_EXISTING,
            ])],
            'reason' => ['required', 'string'],
        ]);

        $this->abortUnlessOwnerOrAdmin($request, $data['user_id'], '他の社員の実績を一括入力する権限がありません。');

        $result = $commandBus->dispatch(new GeneratePatternAttendanceDays(
            userId: $data['user_id'],
            from: $data['from'],
            to: $data['to'],
            weeklyPattern: $data['weekly_pattern'],
            dayOverrides: $data['day_overrides'] ?? [],
            utcOffset: $data['utc_offset'],
            overwriteMode: $data['overwrite_mode'] ?? GeneratePatternAttendanceDays::OVERWRITE_MODE_SKIP_EXISTING,
            reason: $data['reason'],
            actingUserId: $request->user()->id,
        ));

        return response()->json($result);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function attendancePatternValidationRules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'utc_offset' => ['required', 'regex:/^[+-]\d{2}:\d{2}$/'],
            'weekly_pattern' => ['required', 'array'],
            'weekly_pattern.*' => ['nullable', 'array'],
            'weekly_pattern.*.start_time' => ['required_with:weekly_pattern.*.end_time', 'date_format:H:i'],
            'weekly_pattern.*.end_time' => ['required_with:weekly_pattern.*.start_time', 'date_format:H:i'],
            'weekly_pattern.*.break_start_time' => ['required_with:weekly_pattern.*.break_end_time', 'date_format:H:i'],
            'weekly_pattern.*.break_end_time' => ['required_with:weekly_pattern.*.break_start_time', 'date_format:H:i'],
            'day_overrides' => ['nullable', 'array'],
            'day_overrides.*' => ['nullable', 'array'],
            'day_overrides.*.start_time' => ['required_with:day_overrides.*.end_time', 'date_format:H:i'],
            'day_overrides.*.end_time' => ['required_with:day_overrides.*.start_time', 'date_format:H:i'],
            'day_overrides.*.break_start_time' => ['required_with:day_overrides.*.break_end_time', 'date_format:H:i'],
            'day_overrides.*.break_end_time' => ['required_with:day_overrides.*.break_start_time', 'date_format:H:i'],
        ];
    }

    /**
     * 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正する。
     * 実績(actual_start_at/actual_end_at/breaks)が再編集され再計算されると、この補正は
     * 解除される(AttendanceDailyCalculationProjector参照)。
     */
    #[OA\Put(
        path: '/attendance/days/{attendanceDay}/calculation',
        operationId: 'attendance.days.adjustCalculation',
        summary: '日次勤怠の区分ごとの時間を手動で補正する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceDay', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['prescribed_work_minutes', 'statutory_within_overtime_minutes', 'statutory_excess_overtime_minutes', 'legal_holiday_work_minutes', 'late_night_prescribed_work_minutes', 'late_night_statutory_within_overtime_minutes', 'late_night_statutory_excess_overtime_minutes', 'late_night_legal_holiday_work_minutes', 'prescribed_holiday_work_minutes', 'late_night_prescribed_holiday_work_minutes', 'reason'], properties: [new OA\Property(property: 'prescribed_work_minutes', type: 'integer'), new OA\Property(property: 'statutory_within_overtime_minutes', type: 'integer'), new OA\Property(property: 'statutory_excess_overtime_minutes', type: 'integer'), new OA\Property(property: 'legal_holiday_work_minutes', type: 'integer'), new OA\Property(property: 'payroll_work_minutes', type: 'integer', nullable: true, description: '給与計算上の労働時間(裁量労働制のみなし時間の補正等に使用)。省略時は現在値を維持する'), new OA\Property(property: 'late_night_prescribed_work_minutes', type: 'integer'), new OA\Property(property: 'late_night_statutory_within_overtime_minutes', type: 'integer'), new OA\Property(property: 'late_night_statutory_excess_overtime_minutes', type: 'integer'), new OA\Property(property: 'late_night_legal_holiday_work_minutes', type: 'integer'), new OA\Property(property: 'prescribed_holiday_work_minutes', type: 'integer'), new OA\Property(property: 'late_night_prescribed_holiday_work_minutes', type: 'integer'), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function adjustCalculation(Request $request, AttendanceDay $attendanceDay, CommandBus $commandBus): AttendanceDayResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendanceDay->user_id, '他の社員の日次勤怠を補正する権限がありません。');

        $data = $request->validate([
            'prescribed_work_minutes' => ['required', 'integer', 'min:0'],
            'statutory_within_overtime_minutes' => ['required', 'integer', 'min:0'],
            'statutory_excess_overtime_minutes' => ['required', 'integer', 'min:0'],
            'legal_holiday_work_minutes' => ['required', 'integer', 'min:0'],
            'payroll_work_minutes' => ['nullable', 'integer', 'min:0'],
            'late_night_prescribed_work_minutes' => ['required', 'integer', 'min:0'],
            'late_night_statutory_within_overtime_minutes' => ['required', 'integer', 'min:0'],
            'late_night_statutory_excess_overtime_minutes' => ['required', 'integer', 'min:0'],
            'late_night_legal_holiday_work_minutes' => ['required', 'integer', 'min:0'],
            'prescribed_holiday_work_minutes' => ['required', 'integer', 'min:0'],
            'late_night_prescribed_holiday_work_minutes' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string'],
        ]);

        $commandBus->dispatch(new AdjustAttendanceDailyCalculation(
            attendanceDayId: $attendanceDay->id,
            prescribedWorkMinutes: $data['prescribed_work_minutes'],
            statutoryWithinOvertimeMinutes: $data['statutory_within_overtime_minutes'],
            statutoryExcessOvertimeMinutes: $data['statutory_excess_overtime_minutes'],
            legalHolidayWorkMinutes: $data['legal_holiday_work_minutes'],
            payrollWorkMinutes: $data['payroll_work_minutes'] ?? null,
            lateNightPrescribedWorkMinutes: $data['late_night_prescribed_work_minutes'],
            lateNightStatutoryWithinOvertimeMinutes: $data['late_night_statutory_within_overtime_minutes'],
            lateNightStatutoryExcessOvertimeMinutes: $data['late_night_statutory_excess_overtime_minutes'],
            lateNightLegalHolidayWorkMinutes: $data['late_night_legal_holiday_work_minutes'],
            prescribedHolidayWorkMinutes: $data['prescribed_holiday_work_minutes'],
            lateNightPrescribedHolidayWorkMinutes: $data['late_night_prescribed_holiday_work_minutes'],
            reason: $data['reason'],
            adjustedByUserId: $request->user()->id,
        ));

        return new AttendanceDayResource($attendanceDay->refresh()->load(['breaks', 'leaveSegments', 'calculation']));
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string'), new OA\Property(property: 'punch_log_action', type: 'string', enum: ['leave_punches', 'delete_punches', 'recreate_from_punches'])])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function destroyDay(Request $request, AttendanceDay $attendanceDay, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendanceDay->user_id, '他の社員の日次勤怠を削除する権限がありません。');

        $data = $request->validate([
            'reason' => ['required', 'string'],
            'punch_log_action' => ['nullable', Rule::in([
                DeleteAttendanceDay::LEAVE_PUNCHES,
                DeleteAttendanceDay::DELETE_PUNCHES,
                DeleteAttendanceDay::RECREATE_FROM_PUNCHES,
            ])],
        ]);

        $commandBus->dispatch(new DeleteAttendanceDay(
            attendanceDayId: $attendanceDay->id,
            reason: $data['reason'],
            deletedByUserId: $request->user()->id,
            punchLogAction: $data['punch_log_action'] ?? DeleteAttendanceDay::LEAVE_PUNCHES,
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
        parameters: [new OA\Parameter(name: 'yearMonth', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_id', in: 'query', required: false, description: '省略時は自分自身。他の社員を指定できるのはadmin、またはその年月の承認者のみ', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function month(Request $request, string $yearMonth): array
    {
        $data = $request->validate(['user_id' => ['nullable', 'string', 'exists:users,id']]);
        $userId = $this->resolveViewableUserId($request, $data['user_id'] ?? null, [$yearMonth], '他の社員の月次勤怠を閲覧する権限がありません。');

        $days = AttendanceDay::query()
            ->with(['breaks', 'leaveSegments', 'calculation', 'specialLeaveUsages.grant.specialLeaveType', 'specialLeaveUsages.request.specialLeaveType'])
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->orderBy('work_date')
            ->get();

        $month = AttendanceMonth::query()
            ->with(['user', 'approver'])
            ->where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        return [
            'days' => AttendanceDayResource::collection($days),
            'month' => $month ? new AttendanceMonthResource($month) : null,
            // フレックスタイム制(指示書 7.6節)のみ非nullを返す。attendance_monthsの提出前
            // (未提出でまだ行が存在しない月)でも表示できるよう、monthとは独立して都度計算する。
            'flex_settlement_summary' => app(FlexSettlementSummaryCalculator::class)->calculateForMonth($userId, $yearMonth),
            // 法定内残業/法定外残業/月60時間超残業/週40時間超残業/深夜時間等の月合計。提出前でも
            // 進捗の目安として都度計算する(提出後はattendance_months.snapshot_jsonが確定値)。
            'monthly_calculation_totals' => app(MonthlyOvertimeCalculator::class)->calculateCategoryTotals($userId, $yearMonth),
            // 特別休暇の種類ごとの内訳(special_leave_type_id別)。上記totals内のspecial_leave_days/
            // special_leave_minutesはこの内訳の合計と一致する(MonthlyOvertimeCalculator参照)。
            'special_leave_breakdown' => app(MonthlyOvertimeCalculator::class)->calculateSpecialLeaveBreakdown($userId, $yearMonth),
            // 付与必須の特別休暇で、承認済みにもかかわらず付与残数不足などでgrant消化へ確定
            // できていないものを警告する。申請中(submitted)はWorkflow側の申請中数で別途扱う。
            'special_leave_balance_warnings' => $this->specialLeaveBalanceWarnings($userId, $yearMonth),
        ];
    }

    /**
     * @return list<array{special_leave_request_id: string, special_leave_type_id: int|null, special_leave_type_name: string|null, used_on: string|null, requested_days: float, used_minutes: int|null, message: string}>
     */
    private function specialLeaveBalanceWarnings(string $userId, string $yearMonth): array
    {
        return SpecialLeaveUsage::query()
            ->where('user_id', $userId)
            ->where('used_on', 'like', "{$yearMonth}%")
            ->where('is_confirmed', false)
            ->whereHas('request', fn ($query) => $query
                ->where('status', SpecialLeaveRequestStatus::APPROVED)
                ->whereHas('specialLeaveType', fn ($query) => $query->where('requires_grant', true)))
            ->with('request.specialLeaveType')
            ->orderBy('used_on')
            ->get()
            ->map(fn (SpecialLeaveUsage $usage) => [
                'special_leave_request_id' => $usage->special_leave_request_id,
                'special_leave_type_id' => $usage->request?->special_leave_type_id,
                'special_leave_type_name' => $usage->request?->specialLeaveType?->name,
                'used_on' => $usage->used_on?->toDateString(),
                'requested_days' => (float) $usage->used_days,
                'used_minutes' => $usage->used_minutes,
                'message' => sprintf(
                    '%s の%sは付与残数不足のため、承認済みですが特別休暇残数へ消化反映されていません。',
                    $usage->used_on?->toDateString(),
                    $usage->request?->specialLeaveType?->name ?? '特別休暇',
                ),
            ])
            ->values()
            ->all();
    }

    #[OA\Post(
        path: '/attendance/months/{yearMonth}/submit',
        operationId: 'attendance.months.submit',
        summary: '月次勤怠を提出する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'yearMonth', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['approver_user_id'], properties: [new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function submitMonth(
        Request $request,
        string $yearMonth,
        CommandBus $commandBus,
        PaidLeaveApprovalGuard $paidLeaveApprovalGuard,
    ): AttendanceMonthResource {
        // system_settings.attendance_requires_approval=falseの場合でも、月次勤怠申請は
        // (PaidLeaveと異なり)workflow_requestのオーケストレーションを経由させたまま、
        // 承認者省略時のプレースホルダIDだけを立てる。実際の自動承認はSubmitAttendanceMonthHandlerが
        // ExpenseClaimのapproval_skip_thresholdと同じ仕組みで行う。
        $requiresApproval = SystemSetting::current()->attendance_requires_approval;

        $data = $request->validate([
            'approver_user_id' => [$requiresApproval ? 'required' : 'nullable', 'string', 'exists:users,id'],
        ]);

        // AttendanceMonthAggregate::submit()のapproverUserIdは非null必須のため、指定が無い場合は
        // 申請者自身のIDをプレースホルダとして使う(PaidLeaveControllerと同じ理由。このパスの
        // 申請は即座にapprovedになるため、実質的な承認者としては使われない)。
        $approverUserId = $data['approver_user_id'] ?? $request->user()->id;

        // workflow_requestの下書きを作る前に検証し、提出失敗時の孤立した申請を残さない。
        // Handler側でも同じGuardを実行し、API以外のCommand実行経路にも制約を適用する。
        $paidLeaveApprovalGuard->ensureApproved($request->user()->id, $yearMonth);

        // 月次勤怠申請はworkflow_requestの下書き作成を起点にする。集約ID(subject_id)だけを
        // 先に確定させ、実際の提出(attendance_month.submitted/locked/shared)と
        // workflow_requestの提出はReactorのカスケードで同期的に行われる。
        $attendanceMonthId = AttendanceMonth::resolveIdFor($request->user()->id, $yearMonth);

        $commandBus->dispatch(new DraftWorkflowRequest(
            requestTypeCode: null,
            applicantUserId: $request->user()->id,
            title: "{$yearMonth} 月次勤怠",
            formData: [],
            approverUserId: $approverUserId,
            subjectType: 'attendance_month',
            subjectId: $attendanceMonthId,
        ));

        $month = AttendanceMonth::query()->with('approver')->findOrFail($attendanceMonthId);

        return new AttendanceMonthResource($month);
    }

    /**
     * idで単一の月次勤怠を取得する軽量エンドポイント。バックオフィスタスク(source_id =
     * attendance_months.id)からリンクする際に、対象の社員・対象年月・ステータスだけを
     * 素早く参照するために使う。本人・承認者・管理者に加え、締め処理(close、
     * attendance.read Permissionを持つ人事部担当者もバックオフィスタスクから参照する必要が
     * あるため、hr_staffも許可する。
     */
    #[OA\Get(
        path: '/attendance-months/{attendanceMonth}',
        operationId: 'attendanceMonths.show',
        summary: '月次勤怠を1件取得する',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'attendanceMonth', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function showMonth(Request $request, AttendanceMonth $attendanceMonth): AttendanceMonthResource
    {
        if ($attendanceMonth->user_id !== $request->user()->id
            && ! app(EffectiveAccessResolver::class)->hasPermission($request->user(), 'attendance.read', resourceUserId: $attendanceMonth->user_id)
            && ! app(AttendanceApproverAccess::class)->isApproverForAnyYearMonth($request->user()->id, $attendanceMonth->user_id, [$attendanceMonth->year_month])
        ) {
            abort(403, '他の社員の月次勤怠を閲覧する権限がありません。');
        }

        return new AttendanceMonthResource($attendanceMonth->load(['user', 'approver']));
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
        $workflowRequest = $this->submittedWorkflowRequestFor($attendanceMonth);

        if ($workflowRequest !== null) {
            $commandBus->dispatch(new ApproveWorkflowRequest($workflowRequest->id, $request->user()->id));
        } else {
            // 提出経路の変更前に提出された月次勤怠(対応するworkflow_requestが無い行)は、
            // 承認できなくならないよう従来通り直接承認する。
            $commandBus->dispatch(new ApproveAttendanceMonth($attendanceMonth->id, $request->user()->id));
        }

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

        $workflowRequest = $this->submittedWorkflowRequestFor($attendanceMonth);

        if ($workflowRequest !== null) {
            $commandBus->dispatch(new ReturnWorkflowRequest($workflowRequest->id, $request->user()->id, $data['comment']));
        } else {
            $commandBus->dispatch(new ReturnAttendanceMonth($attendanceMonth->id, $request->user()->id, $data['comment']));
        }

        return new AttendanceMonthResource($attendanceMonth->refresh()->load('approver'));
    }

    /**
     * 月次勤怠に紐づく提出済みのworkflow_request(申請本体)を取得する。提出経路が
     * DraftWorkflowRequest起点になっているため通常は必ず存在するが、変更前に提出された
     * 行のために null を返せるようにしている。
     */
    private function submittedWorkflowRequestFor(AttendanceMonth $attendanceMonth): ?WorkflowRequest
    {
        return WorkflowRequest::query()
            ->where('subject_type', 'attendance_month')
            ->where('subject_id', $attendanceMonth->id)
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->latest('submitted_at')
            ->first();
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
            ->with(['user', 'approver'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('year_month')
            ->get();

        return AttendanceMonthResource::collection($months);
    }

    /**
     * 管理者が対象社員を選んで月次勤怠一覧を確認する(月次・週次・日次の勤怠参照)。
     * ルート側で`attendance.update` Permissionにより制限する。
     */
    #[OA\Get(
        path: '/attendance/months/user/{userId}',
        operationId: 'attendance.months.forUser',
        summary: '指定した社員の月次勤怠一覧を取得する(管理者のみ)',
        tags: ['勤怠'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function monthsForUser(string $userId): AnonymousResourceCollection
    {
        $months = AttendanceMonth::query()
            ->with(['user', 'approver'])
            ->where('user_id', $userId)
            ->orderByDesc('year_month')
            ->get();

        return AttendanceMonthResource::collection($months);
    }

    /**
     * UC-A010: 自分が承認者に指定された提出済み月次に加え、UC-A011: 管理部
     * (admin・hr_staff)は承認者を問わず全社員の承認済み(締め処理待ち)月次も一覧できる。
     * ステータス・年月・対象社員での絞り込みとページングに対応する(UserController::index
     * と同じ`per_page`+`paginate()`のパターン)。
     */
    #[OA\Get(
        path: '/attendance/months/to-approve',
        operationId: 'attendance.months.toApprove',
        summary: '承認対象の月次勤怠一覧を取得する',
        tags: ['勤怠'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['submitted', 'approved'])),
            new OA\Parameter(name: 'year_month', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function monthsToApprove(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $canClose = app(EffectiveAccessResolver::class)->hasGlobalPermission($user, 'attendance.update');

        $data = $request->validate([
            'status' => ['nullable', Rule::in([AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::APPROVED])],
            'year_month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $months = AttendanceMonth::query()
            ->with(['user', 'approver'])
            ->where(function ($query) use ($user, $canClose) {
                $query->where('approver_user_id', $user->id)->where('status', AttendanceMonthStatus::SUBMITTED);
                if ($canClose) {
                    $query->orWhere('status', AttendanceMonthStatus::APPROVED);
                }
            })
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['year_month'] ?? null, fn ($query, $yearMonth) => $query->where('year_month', $yearMonth))
            ->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->orderByDesc('year_month')
            ->paginate($data['per_page'] ?? 20);

        return AttendanceMonthResource::collection($months);
    }
}
