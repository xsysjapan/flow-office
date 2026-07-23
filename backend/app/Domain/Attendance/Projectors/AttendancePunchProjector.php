<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendancePunchCorrected;
use App\Domain\Attendance\Events\AttendancePunchDeleted;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use App\Support\LocalDateTime;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attendance_punch.*イベントからattendance_punchesを作成・更新する。打刻ログは追記のみのため、
 * 訂正・削除イベントも元の行を物理的に書き換えず「訂正済み/削除済み」の状態に更新するだけで、
 * 新しい行の作成(訂正時)以外で行を消すことはない。
 */
class AttendancePunchProjector extends Projector
{
    public function onAttendancePunchRecorded(AttendancePunchRecorded $event): void
    {
        [$punchedAt, $utcOffsetMinutes] = LocalDateTime::splitOffset($event->punchedAt);

        AttendancePunch::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'work_date' => $event->workDate,
                'punch_type' => $event->punchType,
                'punched_at' => $punchedAt,
                'utc_offset_minutes' => $utcOffsetMinutes,
                'source' => $event->source,
                'note' => $event->note,
                'device_id' => $event->deviceId,
                'authentication_key_id' => $event->authenticationKeyId,
                'actor_user_id' => $event->actorUserId,
                'offline' => $event->offline,
                'idempotency_key' => $event->idempotencyKey,
                'request_id' => $event->requestId,
                'status' => PunchStatus::ACTIVE,
            ],
        );
    }

    public function onAttendancePunchCorrected(AttendancePunchCorrected $event): void
    {
        [$punchedAt, $utcOffsetMinutes] = LocalDateTime::splitOffset($event->punchedAt);

        // 先に訂正後の行を作成する。superseded_by_punch_idの外部キー制約があるため、
        // 参照先が存在しない状態で元の行を先に更新すると制約違反になる。
        AttendancePunch::query()->updateOrCreate(
            ['id' => $event->correctedPunchId],
            [
                'user_id' => $event->userId,
                'work_date' => $event->workDate,
                'punch_type' => $event->punchType,
                'punched_at' => $punchedAt,
                'utc_offset_minutes' => $utcOffsetMinutes,
                'source' => $event->source,
                'note' => $event->note,
                'status' => PunchStatus::ACTIVE,
            ],
        );

        AttendancePunch::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PunchStatus::CORRECTED,
            'superseded_by_punch_id' => $event->correctedPunchId,
            'correction_reason' => $event->reason,
            'corrected_by_user_id' => $event->correctedByUserId,
            'corrected_at' => $event->createdAt(),
        ]);
    }

    public function onAttendancePunchDeleted(AttendancePunchDeleted $event): void
    {
        AttendancePunch::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PunchStatus::DELETED,
            'correction_reason' => $event->reason,
            'corrected_by_user_id' => $event->deletedByUserId,
            'corrected_at' => $event->createdAt(),
        ]);
    }
}
