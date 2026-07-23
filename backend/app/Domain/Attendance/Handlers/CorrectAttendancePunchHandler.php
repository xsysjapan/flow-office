<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendancePunchAggregate;
use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use App\Support\LocalDateTime;
use Illuminate\Support\Str;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * UC-A013: 打刻ログを訂正する。打刻ログは追記のみのため、元の行は書き換えず「訂正済み」
 * として残し、訂正後の値を新しい打刻行として追記する。対象日が締め後・承認済み月に
 * 属する場合は、打刻ログ自体の訂正もできない(AttendanceEditGuard参照。打刻ログの状態が
 * 変わることで、承認済みの記録に対する監査証跡が書き換わってしまうため)。
 *
 * 打刻の訂正と、それによって生じる日次勤怠への反映は、`AggregateRoot::persistInTransaction()`
 * で1トランザクションにまとめて記録する。
 *
 * @implements CommandHandler<CorrectAttendancePunch>
 */
class CorrectAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceDayPunchSyncer $syncer,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof CorrectAttendancePunch);

        $original = AttendancePunch::query()->findOrFail($command->attendancePunchId);

        if (! $original->isActive()) {
            throw new DomainRuleException('既に訂正・削除済みの打刻ログは重ねて訂正できません。');
        }

        $workDate = $original->work_date->toDateString();
        $day = AttendanceDay::query()
            ->where('user_id', $original->user_id)
            ->whereDate('work_date', $workDate)
            ->first();
        $this->guard->assertMutable($day, $original->user_id, $workDate);

        [$punchedAtNaive, $utcOffsetMinutes] = LocalDateTime::splitOffset($command->punchedAt);
        $correctedId = (string) Str::uuid();

        $punchAggregate = AttendancePunchAggregate::retrieve($original->id)->correct(
            correctedPunchId: $correctedId,
            userId: $original->user_id,
            workDate: $workDate,
            punchType: $command->punchType,
            punchedAt: LocalDateTime::formatWithOffsetMinutes($punchedAtNaive, $utcOffsetMinutes),
            source: $original->source,
            note: $original->note,
            reason: $command->reason,
            correctedByUserId: $command->correctedByUserId,
        );

        // 元の打刻を「訂正済み」に置き換え、新しい訂正後の打刻を有効な打刻として扱った
        // 一覧を組み立てる(どちらもまだ永続化されていないため)。
        $activePunches = AttendancePunch::query()
            ->where('user_id', $original->user_id)
            ->whereDate('work_date', $workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->where('id', '!=', $original->id)
            ->with('device')
            ->get();

        $correctedPunch = new AttendancePunch([
            'id' => $correctedId,
            'user_id' => $original->user_id,
            'work_date' => $workDate,
            'punch_type' => $command->punchType,
            'punched_at' => $punchedAtNaive,
            'utc_offset_minutes' => $utcOffsetMinutes,
            'device_id' => $original->device_id,
        ]);
        $activePunches->push($correctedPunch);
        $activePunches = $activePunches->sortBy('punched_at')->values();

        $dayAggregate = $this->syncer->prepare($original->user_id, $workDate, $activePunches);

        AggregateRoot::persistInTransaction(...array_filter([$punchAggregate, $dayAggregate]));

        return AttendancePunch::query()->findOrFail($correctedId);
    }
}
