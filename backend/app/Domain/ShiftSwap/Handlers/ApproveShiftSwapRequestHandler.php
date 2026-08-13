<?php

namespace App\Domain\ShiftSwap\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\Attendance\Services\WorkStyleFallbackResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ShiftSwap\Aggregates\ShiftSwapRequestAggregate;
use App\Domain\ShiftSwap\Commands\ApproveShiftSwapRequest;
use App\Models\EmployeeCalendarEntry;
use App\Models\ShiftSwapRequest;
use App\Models\ShiftSwapRequestStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 振替休日申請を承認する。承認時に対象日・振替先日それぞれのEmployeeCalendarEntryの
 * 休日区分・所定時刻を丸ごと入れ替える。振替先日にまだ勤務予定行が展開されていない場合
 * (固定勤務は将来日のシフト行が未展開のことが多い)は、働き方のデフォルト値から
 * 通常の勤務日として先に作成してから入れ替えに進む。
 *
 * ShiftSwapRequestAggregate::approve()と2つのEmployeeCalendarEntryAggregate::assign()を
 * persistInTransaction()でまとめて永続化する(ApproveSpecialLeaveRequestHandlerの
 * persistInTransactionと同じ手法)。
 *
 * @implements CommandHandler<ApproveShiftSwapRequest>
 */
class ApproveShiftSwapRequestHandler implements CommandHandler
{
    public function __construct(private readonly WorkStyleFallbackResolver $workStyleFallbackResolver) {}

    public function handle(Command $command): ShiftSwapRequest
    {
        assert($command instanceof ApproveShiftSwapRequest);

        $request = ShiftSwapRequest::query()->findOrFail($command->shiftSwapRequestId);

        if ($request->status !== ShiftSwapRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの振替休日申請のみ承認できます。');
        }

        // approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による承認不要の即時確定
        // (ShiftSwapRequestController::storeRequest参照)であり、承認者チェックそのものを行わない。
        if ($command->approvedByUserId !== null && $request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $targetAssignment = EmployeeCalendarEntry::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->firstOrFail();

        $substituteAssignment = $this->resolveOrCreateSubstituteAssignment($request, $targetAssignment);

        $targetSnapshot = $this->snapshot($targetAssignment);
        $substituteSnapshot = $this->snapshot($substituteAssignment);
        $assignedByUserId = $command->approvedByUserId ?? $request->user_id;

        $aggregates = [
            ShiftSwapRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
            EmployeeCalendarEntryAggregate::retrieve($targetAssignment->id)->assign(
                userId: $targetAssignment->user_id,
                workDate: $targetAssignment->work_date->toDateString(),
                workStyleId: $targetAssignment->work_style_id,
                shiftPatternId: $targetAssignment->shift_pattern_id,
                dayType: $substituteSnapshot['day_type'],
                isWorkingDay: $substituteSnapshot['is_working_day'],
                isLegalHoliday: $substituteSnapshot['is_legal_holiday'],
                isCompanyHoliday: $substituteSnapshot['is_company_holiday'],
                plannedStartAt: $this->combineDateWithTime($targetAssignment->work_date, $substituteSnapshot['planned_start_time']),
                plannedEndAt: $this->combineDateWithTime($targetAssignment->work_date, $substituteSnapshot['planned_end_time']),
                plannedBreakMinutes: $substituteSnapshot['planned_break_minutes'],
                plannedBreakStartAt: $this->combineDateWithTime($targetAssignment->work_date, $substituteSnapshot['planned_break_start_time']),
                plannedBreakEndAt: $this->combineDateWithTime($targetAssignment->work_date, $substituteSnapshot['planned_break_end_time']),
                isPublished: $targetAssignment->is_published,
                isManuallyOverridden: true,
                assignedByUserId: $assignedByUserId,
            ),
            EmployeeCalendarEntryAggregate::retrieve($substituteAssignment->id)->assign(
                userId: $substituteAssignment->user_id,
                workDate: $substituteAssignment->work_date->toDateString(),
                workStyleId: $substituteAssignment->work_style_id,
                shiftPatternId: $substituteAssignment->shift_pattern_id,
                dayType: $targetSnapshot['day_type'],
                isWorkingDay: $targetSnapshot['is_working_day'],
                isLegalHoliday: $targetSnapshot['is_legal_holiday'],
                isCompanyHoliday: $targetSnapshot['is_company_holiday'],
                plannedStartAt: $this->combineDateWithTime($substituteAssignment->work_date, $targetSnapshot['planned_start_time']),
                plannedEndAt: $this->combineDateWithTime($substituteAssignment->work_date, $targetSnapshot['planned_end_time']),
                plannedBreakMinutes: $targetSnapshot['planned_break_minutes'],
                plannedBreakStartAt: $this->combineDateWithTime($substituteAssignment->work_date, $targetSnapshot['planned_break_start_time']),
                plannedBreakEndAt: $this->combineDateWithTime($substituteAssignment->work_date, $targetSnapshot['planned_break_end_time']),
                isPublished: $substituteAssignment->is_published,
                isManuallyOverridden: true,
                assignedByUserId: $assignedByUserId,
            ),
        ];

        AggregateRoot::persistInTransaction(...$aggregates);

        return ShiftSwapRequest::query()->findOrFail($request->id);
    }

    /**
     * 振替先日の勤務予定行が未展開の場合、働き方のデフォルト値から通常の勤務日として作成する。
     * 入れ替え本体のトランザクションに含めず、先に単独で永続化する(この時点で行が無いと
     * 後続の入れ替え処理が対象行を取得できないため)。Projectorは同期実行されるため
     * (docs/29-event-sourcing-framework-migration.md)、persist()直後にEloquentから読み直せる。
     */
    private function resolveOrCreateSubstituteAssignment(ShiftSwapRequest $request, EmployeeCalendarEntry $targetAssignment): EmployeeCalendarEntry
    {
        $existing = EmployeeCalendarEntry::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->substitute_date)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $substituteDate = $request->substitute_date->copy();

        // 振替先日の勤務予定行が未展開の場合、まずWorkStyleFallbackResolver(その月に割り当てられた
        // 働き方→システム全体設定のデフォルト働き方)で解決する。どちらも未設定の運用では、
        // 対象日(振替元)に割り当てられていた働き方(同一社員の固定勤務のはず)を使う。
        $workStyle = $this->workStyleFallbackResolver->resolveForUser($request->user_id, $substituteDate)
            ?? $targetAssignment->workStyle;

        if ($workStyle === null) {
            throw new DomainRuleException('振替先の働き方を特定できないため承認できません。');
        }

        $plannedStartAt = $workStyle->default_start_time !== null
            ? $substituteDate->copy()->setTimeFromTimeString($workStyle->default_start_time) : null;
        $plannedEndAt = $workStyle->default_end_time !== null
            ? $substituteDate->copy()->setTimeFromTimeString($workStyle->default_end_time) : null;

        $id = (string) Str::uuid();

        EmployeeCalendarEntryAggregate::retrieve($id)
            ->assign(
                userId: $request->user_id,
                workDate: $substituteDate->toDateString(),
                workStyleId: $workStyle->id,
                shiftPatternId: null,
                dayType: 'weekday',
                isWorkingDay: true,
                isLegalHoliday: false,
                isCompanyHoliday: false,
                plannedStartAt: $plannedStartAt?->toIso8601String(),
                plannedEndAt: $plannedEndAt?->toIso8601String(),
                plannedBreakMinutes: $workStyle->default_break_minutes ?? 0,
                plannedBreakStartAt: null,
                plannedBreakEndAt: null,
                isPublished: true,
                isManuallyOverridden: false,
                assignedByUserId: $request->approver_user_id ?? $request->user_id,
            )
            ->persist();

        return EmployeeCalendarEntry::query()->findOrFail($id);
    }

    /**
     * 休日区分・所定時刻を、日付に依存しない形(時刻のみ)でスナップショットする。
     * planned_start_at等はそれぞれの行自身のwork_dateに紐づく日時であるべきのため、
     * 入れ替え時は時刻のみを取り出し、入れ替え先の行自身のwork_dateと組み合わせて
     * 再構成する(combineDateWithTime参照)。
     *
     * @return array{day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, planned_start_time: ?string, planned_end_time: ?string, planned_break_minutes: int, planned_break_start_time: ?string, planned_break_end_time: ?string}
     */
    private function snapshot(EmployeeCalendarEntry $assignment): array
    {
        return [
            'day_type' => $assignment->day_type,
            'is_working_day' => $assignment->is_working_day,
            'is_legal_holiday' => $assignment->is_legal_holiday,
            'is_company_holiday' => $assignment->is_company_holiday,
            'planned_start_time' => $assignment->planned_start_at?->format('H:i:s'),
            'planned_end_time' => $assignment->planned_end_at?->format('H:i:s'),
            'planned_break_minutes' => $assignment->planned_break_minutes,
            'planned_break_start_time' => $assignment->planned_break_start_at?->format('H:i:s'),
            'planned_break_end_time' => $assignment->planned_break_end_at?->format('H:i:s'),
        ];
    }

    private function combineDateWithTime(Carbon $date, ?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return $date->copy()->setTimeFromTimeString($time)->toIso8601String();
    }
}
