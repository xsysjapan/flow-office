<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\LegalHolidayDesignated;
use App\Models\LegalHolidayDesignation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attendance.legal_holiday_designatedからlegal_holiday_designationsを作成・更新する
 * (.claude/skills/add-projection参照)。DesignateLegalHolidayHandlerは日次再計算が
 * 直後に読める必要があるため、イベント記録前に同じ内容を直接この行へ書き込んでいる
 * (docs/29-event-sourcing-framework-migration.md「移行済み: PaidLeave / SpecialLeave」の
 * `$day->work_type`直接反映と同じ理由)。ここでのupdateOrCreateは同じ値の再書き込みになるが、
 * イベントからの再生成(event-sourcing:replay)でも同じ結果になるよう冪等に保つ。
 */
class LegalHolidayDesignationProjector extends Projector
{
    public function onLegalHolidayDesignated(LegalHolidayDesignated $event): void
    {
        $designation = LegalHolidayDesignation::query()->find($event->aggregateRootUuid())
            ?? new LegalHolidayDesignation(['id' => $event->aggregateRootUuid()]);

        $designation->fill([
            'user_id' => $event->userId,
            'week_start_date' => $event->weekStartDate,
            'designated_date' => $event->designatedDate,
            'reason' => $event->reason,
            'designated_by' => $event->designatedByUserId,
        ])->save();
    }
}
