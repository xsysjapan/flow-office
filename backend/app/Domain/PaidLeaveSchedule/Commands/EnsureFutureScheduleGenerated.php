<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 対象社員について、1年先までのScheduleエントリ存在を保証する(べき等)。
 * `scheduledOn`ごとの候補内容(区分・付与候補日数)はhire_date・付与ルール等から
 * CommandHandler側で算出し、この時点で確定した値として渡す
 * (Aggregate自身はProjection/Eloquentへアクセスしない)。
 *
 * @phpstan-import-type CandidateEntry from RecalculateFutureSchedule
 */
class EnsureFutureScheduleGenerated implements Command
{
    /**
     * @param  array<int, array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float}>  $candidates
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $candidates,
    ) {}
}
