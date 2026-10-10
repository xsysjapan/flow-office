<?php

namespace App\Domain\CompensatoryLeaveAccount\Reactors;

use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\CompensatoryLeaveAccount\Commands\SyncCompensatoryLeaveAccountGrant;
use App\Domain\EventSourcing\CommandBus;
use App\Models\DayClassification;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 勤怠実績(日次計算)の反映・補正・削除を受けて、対象日の代休の下書き付与を同期する(代休口座の文脈のReactor。原則15)。
 *
 * 勤怠日テーブルは読まず、イベントの内容(利用者・勤務日・日区分・実労働分)で判定する。
 * 判定と換算は口座集約(SyncCompensatoryLeaveAccountGrantHandler経由)が行う。
 * 計算イベントに利用者・勤務日が無い(本変更前の保存イベント)場合は同期しない。
 */
class SyncCompensatoryLeaveAccountGrantOnAttendanceDayCalculatedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onAttendanceDayCalculated(AttendanceDayCalculated $event): void
    {
        if ($event->userId === null || $event->workDate === null) {
            return;
        }

        $dayClassification = $event->calculation['day_classification'] ?? null;

        $this->sync(
            userId: $event->userId,
            workDate: $event->workDate,
            dayClassification: is_string($dayClassification) ? $dayClassification : null,
            workMinutes: (int) ($event->calculation['work_minutes'] ?? 0),
        );
    }

    public function onAttendanceDailyCalculationAdjusted(AttendanceDailyCalculationAdjusted $event): void
    {
        if ($event->userId === null || $event->workDate === null) {
            return;
        }

        $this->sync(
            userId: $event->userId,
            workDate: $event->workDate,
            dayClassification: $event->dayClassification,
            workMinutes: (int) ($event->workMinutes ?? 0),
        );
    }

    /** 勤怠日の削除: 同じ日の下書き付与を外す(休日出勤でない扱いで同期する)。 */
    public function onAttendanceDayDeleted(AttendanceDayDeleted $event): void
    {
        $this->sync(
            userId: $event->userId,
            workDate: $event->workDate,
            dayClassification: null,
            workMinutes: 0,
        );
    }

    private function sync(string $userId, string $workDate, ?string $dayClassification, int $workMinutes): void
    {
        $this->commandBus->dispatch(new SyncCompensatoryLeaveAccountGrant(
            userId: $userId,
            workDate: $workDate,
            isHolidayDay: in_array($dayClassification, [DayClassification::PRESCRIBED_HOLIDAY, DayClassification::LEGAL_HOLIDAY], true),
            workMinutes: $workMinutes,
        ));
    }
}
