<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 月次勤怠の提出を受けて、対象期間(休日出勤日が期間内)の下書き付与を確定する(月次提出からのReactor発行)。
 * periodStart・periodEndは対象月の初日・末日(Y-m-d。両端を含む)、submittedAtは提出日時(確定日時・失効日の起点)。
 *
 * Reactorが発行するため`viaReactor=true`・`initiatedByUserId`(連鎖の起点の操作者)を持つ。確定の対象は下書き付与だけなので、
 * 同じイベントを2回処理しても結果は同じ(2回目は対象が無く何も記録しない)。
 */
class ConfirmCompensatoryLeaveGrantsForPeriod implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $submittedAt,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
