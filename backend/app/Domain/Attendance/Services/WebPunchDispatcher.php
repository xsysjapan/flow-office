<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A001〜A004: Web画面の出退勤操作を、端末等の他の操作経路と共通の`RecordAttendancePunch`
 * コマンド経由で記録する。日次勤怠への反映(状態遷移・休憩の組み立て・日次計算)は
 * `AttendanceDayPunchSyncer`に一本化されており、経路ごとに計算ロジックを複製しない
 * (docs/03-architecture.md 3.5)。
 */
class WebPunchDispatcher
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly AttendanceEditGuard $guard,
    ) {}

    /**
     * @param  AttendanceDay|null  $day  呼び出し元(各Handler)が独自の状態遷移検証のために
     *                                   既に取得済みの当日分。ここで取り直さず再利用する。
     */
    public function dispatch(?AttendanceDay $day, string $userId, string $workDate, string $punchType, Carbon $punchedAt): AttendanceDay
    {
        if ($day !== null && $day->source !== AttendanceDaySource::PUNCH) {
            // 日次編集(UC-A005)・出勤日新規作成(UC-A016)等で既に確定した日は、
            // 打刻ボタンでは変更しない(docs/03-architecture.md 3.6)。無言で何も
            // 反映されない事態を避けるため、ここで明示的にエラーにする。
            throw new DomainRuleException('この日は日次編集で確定済みのため、打刻では状態を変更できません。日次編集から変更してください。');
        }

        $this->guard->assertMutable($day, $userId, $workDate);

        $this->commandBus->dispatch(new RecordAttendancePunch(
            userId: $userId,
            workDate: $workDate,
            punchType: $punchType,
            punchedAt: LocalDateTime::formatWithOffsetMinutes($punchedAt, $punchedAt->utcOffset()),
            source: 'web',
            note: null,
            actorUserId: $userId,
        ));

        return AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->firstOrFail();
    }
}
