<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendancePunchAggregate;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Support\Str;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * UC-A012: 打刻ログを記録する。矛盾があっても記録は必ず成功させ、
 * 矛盾なく1日分の勤務として組み立てられる場合のみ attendance_days に反映する。
 * punched_atはオフセット付きISO8601を前提に、送信された通りの壁時計時刻とUTCオフセット(分)を
 * そのまま保存する(user.timezoneへの変換はしない)。海外出張などで打刻元の現地時刻が
 * 社員本人の既定タイムゾーンと異なる場合でも、その打刻が実際に発生した現地時刻を維持する
 * (docs/03-architecture.md 3.4)。
 *
 * 打刻(attendance_punch集約)と、それによって生じる日次勤怠(attendance_day集約)への反映は
 * 別の集約ストリームだが、`AggregateRoot::persistInTransaction()`で1トランザクションに
 * まとめて記録する(docs/29-event-sourcing-framework-migration.md参照)。
 *
 * @implements CommandHandler<RecordAttendancePunch>
 */
class RecordAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceDayPunchSyncer $syncer,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof RecordAttendancePunch);

        if ($command->idempotencyKey !== null) {
            $existing = AttendancePunch::query()->where('idempotency_key', $command->idempotencyKey)->first();
            if ($existing !== null) {
                // idempotency_keyはDBレベルでも一意制約(グローバル)のため、本来は
                // 同一利用者からの再送のみがここに到達する想定。万一異なる利用者の
                // キーと衝突した場合、他人の打刻を誤って返すことのないよう例外にする
                // (低エントロピーな冪等性キー生成など、端末側の実装不備を早期に検知する)。
                if ($existing->user_id !== $command->userId) {
                    throw new DomainRuleException('冪等性キーが他の利用者の打刻と重複しています。');
                }

                // 端末のオフラインキューからの再送等、同一冪等性キーでの再実行は
                // 新しい行を追加せず既存の結果をそのまま返す(docs/23-usecases-devices.md)。
                return $existing;
            }
        }

        $user = User::query()->findOrFail($command->userId);
        [$punchedAtNaive, $utcOffsetMinutes] = LocalDateTime::splitOffset($command->punchedAt);

        $punchId = (string) Str::uuid();
        $punchAggregate = AttendancePunchAggregate::retrieve($punchId)->record(
            userId: $command->userId,
            workDate: $command->workDate,
            punchType: $command->punchType,
            punchedAt: LocalDateTime::formatWithOffsetMinutes($punchedAtNaive, $utcOffsetMinutes),
            source: $command->source,
            note: $command->note,
            deviceId: $command->deviceId,
            authenticationKeyId: $command->authenticationKeyId,
            actorUserId: $command->actorUserId ?? $command->userId,
            offline: $command->offline,
            idempotencyKey: $command->idempotencyKey,
            requestId: $command->requestId,
        );

        // まだ永続化されていない今回の打刻を含めた、有効な打刻の一覧を組み立てる
        // (AttendanceDayPunchSyncerがDBを再クエリしても今回の打刻はまだ見えないため)。
        $activePunches = AttendancePunch::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $command->workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->with('device')
            ->get();

        $pendingPunch = new AttendancePunch([
            'id' => $punchId,
            'user_id' => $command->userId,
            'work_date' => $command->workDate,
            'punch_type' => $command->punchType,
            'punched_at' => $punchedAtNaive,
            'utc_offset_minutes' => $utcOffsetMinutes,
            'device_id' => $command->deviceId,
        ]);
        $activePunches->push($pendingPunch);
        $activePunches = $activePunches->sortBy('punched_at')->values();

        $dayAggregate = $this->syncer->prepare($user->id, $command->workDate, $activePunches);

        AggregateRoot::persistInTransaction(...array_filter([$punchAggregate, $dayAggregate]));

        return AttendancePunch::query()->findOrFail($punchId);
    }
}
