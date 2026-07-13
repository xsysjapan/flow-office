<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\Attendance\Commands\CreateWorkStyle;
use App\Domain\Attendance\Commands\SetDefaultWorkStyle;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkStyleResource;
use App\Models\WorkStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * UC-C002: 勤務形態を作成する。
 */
class WorkStyleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WorkStyleResource::collection(WorkStyle::query()->with('employmentCategory')->orderBy('name')->get());
    }

    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:work_styles,code'],
            'name' => ['required', 'string', 'max:100'],
            'employment_category_id' => ['nullable', 'exists:employment_categories,id'],
            'work_time_system' => ['required', 'string', Rule::in([
                WorkStyle::WORK_TIME_SYSTEM_FIXED,
                WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE,
                WorkStyle::WORK_TIME_SYSTEM_DISCRETIONARY,
                WorkStyle::WORK_TIME_SYSTEM_MANAGER_SUPERVISOR,
                WorkStyle::WORK_TIME_SYSTEM_FLEX,
            ])],
            'prescribed_daily_minutes' => ['required', 'integer', 'min:1'],
            'prescribed_weekly_minutes' => ['required', 'integer', 'min:1'],
            'deemed_daily_minutes' => ['nullable', 'integer', 'min:1'],
            'variable_period_start_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            'default_end_time' => ['nullable', 'date_format:H:i'],
            'default_break_minutes' => ['integer', 'min:0'],
            'calendar_id' => ['nullable', 'exists:work_calendars,id'],
            'is_shift_based' => ['boolean'],
            'legal_holiday_rule' => ['nullable', Rule::in([
                WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
                WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS,
                WorkStyle::LEGAL_HOLIDAY_RULE_UNDETERMINED,
            ])],
            'four_week_period_start_date' => [
                'nullable', 'date',
                Rule::requiredIf($request->input('legal_holiday_rule') === WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS),
            ],
            'max_consecutive_work_days' => ['nullable', 'integer', 'min:1', 'max:31'],
            'settlement_start_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'core_time_enabled' => ['boolean'],
            'core_time_start' => ['nullable', 'date_format:H:i'],
            'core_time_end' => ['nullable', 'date_format:H:i'],
            'flexible_time_start' => ['nullable', 'date_format:H:i'],
            'flexible_time_end' => ['nullable', 'date_format:H:i'],
        ]);

        $data['legal_holiday_rule'] ??= WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY;

        if ($data['work_time_system'] === WorkStyle::WORK_TIME_SYSTEM_FLEX) {
            $this->validateFlexTimeFields($data);
            $data['settlement_start_day'] ??= 1;
        }

        $workStyle = $commandBus->dispatch(new CreateWorkStyle(
            attributes: $data,
            createdByUserId: $request->user()->id,
        ));

        return (new WorkStyleResource($workStyle))->response()->setStatusCode(201);
    }

    /**
     * 指示書 7.4節・7.5節・保存前バリデーション(12.5節): コアタイムは勤務可能時間帯
     * (フレキシブルタイム)の範囲内でなければならず、開始・終了の前後関係も矛盾しないこと。
     *
     * @param  array<string, mixed>  $data
     */
    private function validateFlexTimeFields(array $data): void
    {
        $coreEnabled = (bool) ($data['core_time_enabled'] ?? false);

        if ($coreEnabled && ($data['core_time_start'] ?? null) === null) {
            throw ValidationException::withMessages(['core_time_start' => 'コアタイムを有効にする場合は開始時刻を入力してください。']);
        }
        if ($coreEnabled && ($data['core_time_end'] ?? null) === null) {
            throw ValidationException::withMessages(['core_time_end' => 'コアタイムを有効にする場合は終了時刻を入力してください。']);
        }

        $coreStart = isset($data['core_time_start']) ? Carbon::createFromFormat('H:i', $data['core_time_start']) : null;
        $coreEnd = isset($data['core_time_end']) ? Carbon::createFromFormat('H:i', $data['core_time_end']) : null;
        $flexibleStart = isset($data['flexible_time_start']) ? Carbon::createFromFormat('H:i', $data['flexible_time_start']) : null;
        $flexibleEnd = isset($data['flexible_time_end']) ? Carbon::createFromFormat('H:i', $data['flexible_time_end']) : null;

        if ($coreStart !== null && $coreEnd !== null && $coreEnd->lessThanOrEqualTo($coreStart)) {
            throw ValidationException::withMessages(['core_time_end' => 'コアタイムの終了時刻は開始時刻より後にしてください。']);
        }
        if ($flexibleStart !== null && $flexibleEnd !== null && $flexibleEnd->lessThanOrEqualTo($flexibleStart)) {
            throw ValidationException::withMessages(['flexible_time_end' => '勤務可能時間帯の終了時刻は開始時刻より後にしてください。']);
        }
        if ($coreStart !== null && $flexibleStart !== null && $coreStart->lessThan($flexibleStart)) {
            throw ValidationException::withMessages(['core_time_start' => 'コアタイムは勤務可能時間帯の範囲内にしてください。']);
        }
        if ($coreEnd !== null && $flexibleEnd !== null && $coreEnd->greaterThan($flexibleEnd)) {
            throw ValidationException::withMessages(['core_time_end' => 'コアタイムは勤務可能時間帯の範囲内にしてください。']);
        }
    }

    /**
     * 指示書 3.1節・12.1節: 初回オンボーディングで「通常勤務」をデフォルト働き方として
     * 作成する。既にデフォルトが存在する場合はエラーとする(SetDefaultWorkStyleで
     * 切り替える)。
     */
    public function storeDefault(Request $request, CommandBus $commandBus): JsonResponse
    {
        $overrides = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            'default_end_time' => ['nullable', 'date_format:H:i'],
            'default_break_minutes' => ['nullable', 'integer', 'min:0'],
            'calendar_id' => ['nullable', 'exists:work_calendars,id'],
        ]);

        $workStyle = $commandBus->dispatch(new CreateDefaultWorkStyle(
            overrides: array_filter($overrides, fn ($value) => $value !== null),
            createdByUserId: $request->user()->id,
        ));

        return (new WorkStyleResource($workStyle))->response()->setStatusCode(201);
    }

    /**
     * 指示書 3.2節: 既存の働き方をデフォルトに切り替える。
     */
    public function setDefault(Request $request, WorkStyle $workStyle, CommandBus $commandBus): WorkStyleResource
    {
        $updated = $commandBus->dispatch(new SetDefaultWorkStyle(
            workStyleId: $workStyle->id,
            changedByUserId: $request->user()->id,
        ));

        return new WorkStyleResource($updated);
    }
}
