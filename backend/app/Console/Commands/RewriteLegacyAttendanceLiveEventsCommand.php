<?php

namespace App\Console\Commands;

use App\Models\StoredEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WEB画面の出退勤操作(UC-A001〜A004)を端末等と共通のRecordAttendancePunch/
 * AttendanceDayPunchSyncerに一本化した際、`attendance.clocked_in` /
 * `attendance.break_started` / `attendance.break_ended` / `attendance.clocked_out` の
 * 4イベント種別は廃止し、`attendance_day.live_status_synced` /
 * `attendance_day.synced_from_punches` に置き換えた(docs/17-events.md参照)。
 *
 * `stored_events`は本来「追記のみ・更新しない」が原則(StoredEventモデル参照)だが、
 * この4種別は廃止済みで今後発行されることがなく、他ドメインとの整合を壊す参照も
 * 持たない(このイベント種別を購読するProjectorは存在しない)。ユーザーの要望により、
 * 過去に記録された行そのものを新しいイベント種別・payload形状に書き換える、
 * 一度きりの手動実行コマンドとして提供する。通常のCommand→CommandHandlerの
 * フローの外で行う例外的な操作であり、cronスケジュールには登録しない。
 *
 * マッピング:
 * - attendance.clocked_in   → attendance_day.live_status_synced (status: working)
 * - attendance.break_started → attendance_day.live_status_synced (status: on_break)
 * - attendance.break_ended   → attendance_day.live_status_synced (status: working)
 * - attendance.clocked_out   → attendance_day.synced_from_punches
 *   (同一aggregate_id(attendance_day_id)の直近のclocked_inイベントからactual_start_atを
 *   補い、このclocked_outのactual_end_atと組み合わせる。対応するclocked_inが見つからない
 *   場合(データ不整合等)は attendance_day.live_status_synced (status: clocked_out) に
 *   フォールバックする)
 *
 * 書き換え後のpayloadには元の種別を`legacy_event_type`として残し、追跡できるようにする。
 */
class RewriteLegacyAttendanceLiveEventsCommand extends Command
{
    private const LEGACY_TYPES = [
        'attendance.clocked_in',
        'attendance.break_started',
        'attendance.break_ended',
        'attendance.clocked_out',
    ];

    protected $signature = 'attendance:rewrite-legacy-live-events
        {--dry-run : 実際には書き換えず、対象件数のみ確認する}
        {--force : 確認プロンプトを省略する}';

    protected $description = '廃止されたattendance.clocked_in等4イベントを、置き換え先の新イベント種別へ書き換える(手動実行用の一度きりのデータ移行)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $events = StoredEvent::query()
            ->whereIn('event_type', self::LEGACY_TYPES)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            $this->info('対象イベントはありません。');

            return self::SUCCESS;
        }

        $clockInStartsByDayId = $events
            ->where('event_type', 'attendance.clocked_in')
            ->mapWithKeys(fn (StoredEvent $event) => [
                (int) $event->aggregate_id => $event->payload['actual_start_at'] ?? null,
            ]);

        $counts = $events->countBy('event_type');
        foreach ($counts as $type => $count) {
            $this->info("{$type}: {$count}件");
        }

        if ($dryRun) {
            $this->info('--dry-runのため書き換えは行いません。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('stored_eventsの'.$events->count().'件を書き換えます。よろしいですか?')) {
            $this->warn('中断しました。');

            return self::FAILURE;
        }

        DB::transaction(function () use ($events, $clockInStartsByDayId) {
            foreach ($events as $event) {
                [$newEventType, $newPayload] = $this->rewrite($event, $clockInStartsByDayId);
                $event->event_type = $newEventType;
                $event->payload = $newPayload;
                $event->save();
            }
        });

        $this->info($events->count().'件を書き換えました。');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, mixed>  $clockInStartsByDayId
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function rewrite(StoredEvent $event, Collection $clockInStartsByDayId): array
    {
        $dayId = (int) $event->aggregate_id;

        return match ($event->event_type) {
            'attendance.clocked_in' => ['attendance_day.live_status_synced', [
                'attendance_day_id' => $dayId,
                'status' => 'working',
                'legacy_event_type' => 'attendance.clocked_in',
            ]],
            'attendance.break_started' => ['attendance_day.live_status_synced', [
                'attendance_day_id' => $dayId,
                'status' => 'on_break',
                'legacy_event_type' => 'attendance.break_started',
            ]],
            'attendance.break_ended' => ['attendance_day.live_status_synced', [
                'attendance_day_id' => $dayId,
                'status' => 'working',
                'legacy_event_type' => 'attendance.break_ended',
            ]],
            'attendance.clocked_out' => $this->rewriteClockedOut($event, $dayId, $clockInStartsByDayId),
        };
    }

    /**
     * @param  Collection<int, mixed>  $clockInStartsByDayId
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function rewriteClockedOut(StoredEvent $event, int $dayId, Collection $clockInStartsByDayId): array
    {
        $actualStartAt = $clockInStartsByDayId->get($dayId);
        $actualEndAt = $event->payload['actual_end_at'] ?? null;

        if ($actualStartAt !== null && $actualEndAt !== null) {
            return ['attendance_day.synced_from_punches', [
                'attendance_day_id' => $dayId,
                'actual_start_at' => $actualStartAt,
                'actual_end_at' => $actualEndAt,
                'legacy_event_type' => 'attendance.clocked_out',
            ]];
        }

        // 対応するclocked_inイベントが見つからない(データ不整合等)場合は、
        // 実績時刻を捏造せず状態遷移のみのイベントにフォールバックする。
        return ['attendance_day.live_status_synced', [
            'attendance_day_id' => $dayId,
            'status' => 'clocked_out',
            'legacy_event_type' => 'attendance.clocked_out',
        ]];
    }
}
