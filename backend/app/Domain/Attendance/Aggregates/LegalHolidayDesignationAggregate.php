<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\LegalHolidayDesignated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * legal_holiday_designation集約。主キー(legal_holiday_designations.id)はコマンド側/呼び出し元
 * サービスが決めたUUIDで、行の新規作成自体はLegalHolidayDesignationProjectorに委ねられる。
 * ユーザー+週の組で現在有効な指定は1件のみのため、Handlerは既存行があればそのidを
 * 再利用してretrieveする(再指定は同一集約ストリームへの追記)。
 */
class LegalHolidayDesignationAggregate extends AggregateRoot
{
    public function designate(
        string $userId,
        string $weekStartDate,
        ?string $previousDesignatedDate,
        string $designatedDate,
        string $reason,
        string $designatedByUserId,
    ): self {
        $this->recordThat(new LegalHolidayDesignated(
            userId: $userId,
            weekStartDate: $weekStartDate,
            previousDesignatedDate: $previousDesignatedDate,
            designatedDate: $designatedDate,
            reason: $reason,
            designatedByUserId: $designatedByUserId,
        ));

        return $this;
    }
}
