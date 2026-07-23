<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendancePunchCorrected;
use App\Domain\Attendance\Events\AttendancePunchDeleted;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * attendance_punch集約。打刻ログは追記のみ(CLAUDE.md「打刻と勤怠編集を区別する」)であり、
 * 訂正・削除は元の行を書き換えず「訂正済み/削除済み」として残す新しいイベントを追記する。
 * 訂正で作られる新しい打刻行(correctedPunchId)は、元の打刻の集約ストリーム上の
 * AttendancePunchCorrectedイベントから作成され、それ自身の集約ストリームは持たない
 * (旧実装から引き継いだ設計。将来その行がさらに訂正される場合は、そのUUIDをretrieve()した
 * 新しい集約として扱う。version 0から開始しても問題ない)。
 */
class AttendancePunchAggregate extends AggregateRoot
{
    public function record(
        string $userId,
        string $workDate,
        string $punchType,
        string $punchedAt,
        string $source,
        ?string $note,
        ?string $deviceId,
        ?string $authenticationKeyId,
        ?string $actorUserId,
        bool $offline,
        ?string $idempotencyKey,
        ?string $requestId,
    ): self {
        $this->recordThat(new AttendancePunchRecorded(
            userId: $userId,
            workDate: $workDate,
            punchType: $punchType,
            punchedAt: $punchedAt,
            source: $source,
            note: $note,
            deviceId: $deviceId,
            authenticationKeyId: $authenticationKeyId,
            actorUserId: $actorUserId,
            offline: $offline,
            idempotencyKey: $idempotencyKey,
            requestId: $requestId,
        ));

        return $this;
    }

    public function correct(
        string $correctedPunchId,
        string $userId,
        string $workDate,
        string $punchType,
        string $punchedAt,
        string $source,
        ?string $note,
        string $reason,
        string $correctedByUserId,
    ): self {
        $this->recordThat(new AttendancePunchCorrected(
            correctedPunchId: $correctedPunchId,
            userId: $userId,
            workDate: $workDate,
            punchType: $punchType,
            punchedAt: $punchedAt,
            source: $source,
            note: $note,
            reason: $reason,
            correctedByUserId: $correctedByUserId,
        ));

        return $this;
    }

    public function delete(string $reason, string $deletedByUserId): self
    {
        $this->recordThat(new AttendancePunchDeleted(
            reason: $reason,
            deletedByUserId: $deletedByUserId,
        ));

        return $this;
    }
}
