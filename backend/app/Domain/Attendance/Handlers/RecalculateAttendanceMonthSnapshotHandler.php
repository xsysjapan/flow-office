<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\RecalculateAttendanceMonthSnapshot;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;

/**
 * 過去データ補正: 提出済み・承認済み・締め済みの月次勤怠のsnapshot_jsonを、対象月の日次実績
 * (提出時にロックされ変更されない)から現在の集計ロジックで再計算する。差戻し中(returned)・
 * 未提出(not_submitted)の月は対象外(差戻し中は日次がロック解除され編集中のため、再計算しても
 * 再提出までに値が変わりうる。未提出はsnapshot自体が存在しない)。
 *
 * @implements CommandHandler<RecalculateAttendanceMonthSnapshot>
 */
class RecalculateAttendanceMonthSnapshotHandler implements CommandHandler
{
    private const RECALCULATABLE_STATUSES = [
        AttendanceMonthStatus::SUBMITTED,
        AttendanceMonthStatus::APPROVED,
        AttendanceMonthStatus::CLOSED,
    ];

    public function __construct(
        private readonly MonthlyOvertimeCalculator $monthlyOvertimeCalculator,
        private readonly AttendanceCalculator $attendanceCalculator,
    ) {}

    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof RecalculateAttendanceMonthSnapshot);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if (! in_array($month->status, self::RECALCULATABLE_STATUSES, true)) {
            throw new DomainRuleException('この月次勤怠は現在のステータスからは再計算できません。');
        }

        // day_classification追加前の日次勤怠は区分がNULLのまま残っている。月次の区分別集計より
        // 先に、当時の勤務予定・会社カレンダーを使って通常の日次計算処理を再実行し、区分だけで
        // なく休日労働・残業を含む日次計算結果全体を現在のロジックで補正する。
        $days = AttendanceDay::query()
            ->where('user_id', $month->user_id)
            ->where('work_date', 'like', "{$month->year_month}%")
            ->with([
                'breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages',
                'calendarEntry.workStyle',
            ])
            ->orderBy('work_date')
            ->get();

        foreach ($days as $day) {
            $calculation = $this->attendanceCalculator->calculate($day);

            AttendanceDayAggregate::retrieve($day->id)
                ->calculate($calculation)
                ->persist();
        }

        // SubmitAttendanceMonthHandler::buildSnapshot()と同じ形(day_count + 集計値)を保つ。
        $dayCount = AttendanceDay::query()
            ->where('user_id', $month->user_id)
            ->where('work_date', 'like', "{$month->year_month}%")
            ->count();

        $snapshot = array_merge(
            ['day_count' => $dayCount],
            $this->monthlyOvertimeCalculator->calculateCategoryTotals($month->user_id, $month->year_month),
        );

        AttendanceMonthAggregate::retrieve($month->id)
            ->recalculateSnapshot($snapshot)
            ->persist();

        return AttendanceMonth::query()->findOrFail($month->id);
    }
}
