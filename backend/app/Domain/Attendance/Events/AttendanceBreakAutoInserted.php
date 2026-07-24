<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.break_auto_inserted
 *
 * 退勤時、働き方(work_styles.auto_break_enabled)の設定に基づき、打刻・記録された休憩が
 * 一切無い日に標準休憩(default_break_start_time〜default_break_end_time)を自動で
 * 補完した (AttendanceDayPunchSyncer参照)。ユーザーが実際に打刻・編集した休憩を上書きすることは
 * ない。AttendanceDayProjectorがattendance_breaks行の作成自体を担当する(attendanceBreakIdは
 * 他イベントから参照されないため、Projectorが採番する連番PKのままでよい)。
 */
class AttendanceBreakAutoInserted extends ShouldBeStored
{
    public function __construct(
        public readonly string $workStyleId,
        public readonly string $breakStartAt,
        public readonly string $breakEndAt,
    ) {}
}
