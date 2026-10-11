<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 作り直しの読み書き(DB)。変換そのものは EventStoreRebuilder(純粋)に任せる。
 *
 * - readRows(): stored_events の生の行を id順に読む。
 * - readAttendanceDays(): ReadModel(attendance_days)の現在値を読む(勤怠の変換 2.4 の入力)。
 * - assertPreconditions(): 事前条件(論点18 7章1)。新しい種類の休暇イベント・補正ログ・既存の作り直し表が無いこと。
 * - write(): 新しい表(既定 stored_events_rebuilt)に書く。既にあれば失敗する。
 * - verify(): 作り直し後の表の自己整合性(id・版の連番、新しい種類が残っていないこと)を確かめる。
 * - swap(): stored_events と入れ替える。元の表は stored_events_before_rebuild_YYYYMMDDHHMMSS として残す。
 */
final class EventStoreRebuildWriter
{
    public const TABLE = 'stored_events_rebuilt';

    public const CORRECTION_LOG = 'stored_event_corrections';

    private const CHUNK = 500;

    /** @return list<StoredEventRow> */
    public function readRows(): array
    {
        $rows = [];
        DB::table('stored_events')->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$rows): void {
            foreach ($chunk as $row) {
                $rows[] = StoredEventRow::fromDatabaseRow($row);
            }
        });

        return $rows;
    }

    /**
     * ReadModel(attendance_days)の現在値(勤怠日ID => 現在値)。旧コードが直接書いた勤怠の事実はReadModelにしか無いため、
     * 勤怠の変換(論点18 2.4)の入力に使う。休憩・休暇区間・打刻の有無も読む。
     *
     * @return array<string, AttendanceDayCurrent>
     */
    public function readAttendanceDays(): array
    {
        if (! Schema::hasTable('attendance_days')) {
            return [];
        }

        $breaks = [];
        if (Schema::hasTable('attendance_breaks')) {
            foreach (DB::table('attendance_breaks')->orderBy('id')->get() as $row) {
                $breaks[(string) $row->attendance_day_id][] = [
                    'start' => (string) $row->break_start_at,
                    'end' => $row->break_end_at !== null ? (string) $row->break_end_at : null,
                ];
            }
        }
        $segments = [];
        if (Schema::hasTable('attendance_leave_segments')) {
            foreach (DB::table('attendance_leave_segments')->orderBy('id')->get() as $row) {
                $segments[(string) $row->attendance_day_id][] = [
                    'start' => (string) $row->start_at,
                    'end' => (string) $row->end_at,
                    'note' => $row->note !== null ? (string) $row->note : null,
                ];
            }
        }
        $punched = [];
        if (Schema::hasTable('attendance_punches')) {
            foreach (DB::table('attendance_punches')->select('user_id', 'work_date')->distinct()->get() as $row) {
                $punched[(string) $row->user_id.'|'.substr((string) $row->work_date, 0, 10)] = true;
            }
        }

        $days = [];
        foreach (DB::table('attendance_days')->orderBy('id')->get() as $row) {
            $id = (string) $row->id;
            $workDate = substr((string) $row->work_date, 0, 10);
            $days[$id] = new AttendanceDayCurrent(
                id: $id,
                userId: (string) $row->user_id,
                workDate: $workDate,
                calendarEntryId: $row->calendar_entry_id !== null ? (string) $row->calendar_entry_id : null,
                status: (string) $row->status,
                source: (string) $row->source,
                utcOffsetMinutes: (int) ($row->utc_offset_minutes ?? 0),
                actualStartAt: $row->actual_start_at !== null ? (string) $row->actual_start_at : null,
                actualEndAt: $row->actual_end_at !== null ? (string) $row->actual_end_at : null,
                workType: $row->work_type !== null ? (string) $row->work_type : null,
                workLocationType: $row->work_location_type !== null ? (string) $row->work_location_type : null,
                dayClassification: $row->day_classification !== null ? (string) $row->day_classification : null,
                note: $row->note !== null ? (string) $row->note : null,
                breaks: $breaks[$id] ?? [],
                leaveSegments: $segments[$id] ?? [],
                hasPunch: isset($punched[(string) $row->user_id.'|'.$workDate]),
            );
        }

        return $days;
    }

    /** @return list<string> 満たしていない事前条件の説明(空なら満たしている) */
    public function preconditionViolations(): array
    {
        $violations = [];
        if (Schema::hasTable(self::TABLE)) {
            $violations[] = self::TABLE.' が既に存在する(前回の作り直しの結果を確認して削除してから実行する)';
        }
        if (Schema::hasTable(self::CORRECTION_LOG) && DB::table(self::CORRECTION_LOG)->exists()) {
            $violations[] = self::CORRECTION_LOG.' に補正ログがある(作り直しの前に対応表を確認する)';
        }
        $newKinds = DB::table('stored_events')->where('event_class', 'like', 'paid_leave_request.%')->count();
        if ($newKinds > 0) {
            $violations[] = "新しい種類の休暇申請イベント(paid_leave_request.*)が {$newKinds} 件ある(本番には無い前提)";
        }

        return $violations;
    }

    /**
     * 新しい表に書く。stored_events と同じ構造(id は変換で振った値をそのまま使う)。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function write(array $rows, string $table = self::TABLE): void
    {
        if (Schema::hasTable($table)) {
            throw new RuntimeException("{$table} が既に存在します。");
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();
            $blueprint->uuid('aggregate_uuid')->nullable();
            $blueprint->unsignedBigInteger('aggregate_version')->nullable();
            $blueprint->unsignedTinyInteger('event_version')->default(1);
            $blueprint->string('event_class');
            $blueprint->json('event_properties');
            $blueprint->json('meta_data');
            $blueprint->timestamp('created_at');
            $blueprint->unique(['aggregate_uuid', 'aggregate_version'], "{$table}_stream_unique");
        });

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert(array_map(fn (array $row) => [
                'id' => $row['id'],
                'aggregate_uuid' => $row['aggregate_uuid'],
                'aggregate_version' => $row['aggregate_version'],
                'event_version' => $row['event_version'],
                'event_class' => $row['event_class'],
                'event_properties' => json_encode($row['event_properties'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'meta_data' => json_encode($row['meta_data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $row['created_at'],
            ], $chunk));
        }
    }

    /**
     * 作り直し後の表の自己整合性。違反の説明の一覧を返す(空なら問題なし)。
     *
     * @return list<string>
     */
    public function verify(string $table = self::TABLE): array
    {
        if (! Schema::hasTable($table)) {
            return ["{$table} が存在しない"];
        }

        $problems = [];
        $expectedId = 1;
        $versions = [];
        DB::table($table)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$problems, &$expectedId, &$versions): void {
            foreach ($chunk as $row) {
                if ((int) $row->id !== $expectedId) {
                    $problems[] = "id が連番ではない: 期待 {$expectedId}、実際 {$row->id}";
                    $expectedId = (int) $row->id;
                }
                $expectedId++;

                if ($row->aggregate_uuid !== null) {
                    $expected = ($versions[$row->aggregate_uuid] ?? 0) + 1;
                    if ((int) $row->aggregate_version !== $expected) {
                        $problems[] = "集約 {$row->aggregate_uuid} の版が連番ではない: 期待 {$expected}、実際 {$row->aggregate_version}";
                    }
                    $versions[$row->aggregate_uuid] = $expected;
                }
                if (in_array($row->event_class, EventStoreRebuilder::LEGACY_CLASSES, true)) {
                    $problems[] = "旧の休暇イベントが残っている: id={$row->id} {$row->event_class}";
                }
            }
        });

        return $problems;
    }

    /**
     * stored_events と入れ替える。元の表は $backupTable として残す。MySQLは RENAME TABLE を1文で行い、
     * SQLiteは同じ順序の2つの改名を1つのトランザクションで行う。
     */
    public function swap(string $backupTable, string $table = self::TABLE): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("{$table} が存在しない");
        }
        if (Schema::hasTable($backupTable)) {
            throw new RuntimeException("{$backupTable} が既に存在する");
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("RENAME TABLE `stored_events` TO `{$backupTable}`, `{$table}` TO `stored_events`");
        } else {
            DB::transaction(function () use ($backupTable, $table): void {
                Schema::rename('stored_events', $backupTable);
                Schema::rename($table, 'stored_events');
            });
        }

        $next = (int) DB::table('stored_events')->max('id') + 1;
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `stored_events` AUTO_INCREMENT = {$next}");
        }
    }
}
