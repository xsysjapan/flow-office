<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\Reconcile\SeededDataCurrent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 作り直しの読み書き(DB)。変換そのものは EventStoreRebuilder(純粋)に任せる。
 *
 * - readRows(): stored_events の生の行を id順に読む。
 * - readAttendanceDays(): ReadModel(attendance_days)の現在値を読む(勤怠の変換 2.4 の入力)。
 * - readPaidLeaveSchedule(): ReadModel(paid_leave_schedule_entries・paid_leave_schedule_assessments)の現在値を読む(旧版の付与予定イベントの置き換えの入力)。
 * - readSeededData(): ReadModel(group_types・groups・roles・permission_role・role_features・role_assignments・group_feature_assignments・users・memberships・external_identities・field_authorities・company_calendar_years・days)の現在の行を読む(照合による補完の入力。論点19 決定1)。
 * - readCalendarDayIndex(): ReadModel(company_calendar_days)の id => (年の id・日付)の対応を読む(祝日の同期イベントの dayChanges に補う入力。論点19 決定8-2)。
 * - assertPreconditions(): 事前条件(論点18 7章1)。新しい種類の休暇・口座イベント・補正ログ・既存の作り直し表・スナップショットが無いこと。
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
     * stored_events の全行を、作り直し後の行と同じ形(RebuildResult::$rows の Row形)で id順に読む。PaidLeaveStreamRekeyer の入力。
     *
     * @return list<array{id: int, aggregate_uuid: ?string, aggregate_version: ?int, event_version: int, event_class: string, event_properties: array<string, mixed>, meta_data: array<string, mixed>, created_at: string}>
     */
    public function readRowArrays(string $table = 'stored_events'): array
    {
        $rows = [];
        DB::table($table)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$rows): void {
            foreach ($chunk as $row) {
                $rows[] = [
                    'id' => (int) $row->id,
                    'aggregate_uuid' => $row->aggregate_uuid !== null ? (string) $row->aggregate_uuid : null,
                    'aggregate_version' => $row->aggregate_version !== null ? (int) $row->aggregate_version : null,
                    'event_version' => (int) ($row->event_version ?? 1),
                    'event_class' => (string) $row->event_class,
                    'event_properties' => json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR),
                    'meta_data' => json_decode((string) ($row->meta_data ?? '[]'), true, 512, JSON_THROW_ON_ERROR),
                    'created_at' => (string) $row->created_at,
                ];
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

    /**
     * ReadModel(paid_leave_schedule_entries・paid_leave_schedule_assessments)の現在値(行id => 現在値)。
     * 旧版の付与予定イベントを現行の形のイベントに置き換える(論点19 決定3)入力に使う。
     *
     * @return array<string, PaidLeaveScheduleCurrent>
     */
    public function readPaidLeaveSchedule(): array
    {
        if (! Schema::hasTable('paid_leave_schedule_entries')) {
            return [];
        }

        $assessments = [];
        if (Schema::hasTable('paid_leave_schedule_assessments')) {
            foreach (DB::table('paid_leave_schedule_assessments')->orderBy('created_at')->orderBy('id')->get() as $row) {
                $assessments[(string) $row->schedule_entry_id][] = [
                    'id' => (string) $row->id,
                    'periodStart' => $row->period_start !== null ? (string) $row->period_start : null,
                    'periodEnd' => $row->period_end !== null ? (string) $row->period_end : null,
                    'denominatorDays' => $row->denominator_days !== null ? (int) $row->denominator_days : null,
                    'attendanceDays' => $row->attendance_days !== null ? (int) $row->attendance_days : null,
                    'excludedDays' => $row->excluded_days !== null ? (int) $row->excluded_days : null,
                    'attendanceRate' => $row->attendance_rate !== null ? (float) $row->attendance_rate : null,
                    'policyVersion' => $row->policy_version !== null ? (string) $row->policy_version : null,
                    'automaticResult' => $row->automatic_result !== null ? (string) $row->automatic_result : null,
                    'finalResult' => $row->final_result !== null ? (string) $row->final_result : null,
                    'overrideReason' => $row->override_reason !== null ? (string) $row->override_reason : null,
                    'overriddenByUserId' => $row->overridden_by_user_id !== null ? (string) $row->overridden_by_user_id : null,
                    'createdAt' => $row->created_at !== null ? (string) $row->created_at : null,
                ];
            }
        }

        $entries = [];
        foreach (DB::table('paid_leave_schedule_entries')->orderBy('id')->get() as $row) {
            $id = (string) $row->id;
            $entries[$id] = new PaidLeaveScheduleCurrent(
                id: $id,
                userId: $row->user_id !== null ? (string) $row->user_id : null,
                scheduledOn: $row->scheduled_on !== null ? (string) $row->scheduled_on : null,
                category: $row->category !== null ? (string) $row->category : null,
                candidateGrantDays: $row->candidate_grant_days !== null ? (float) $row->candidate_grant_days : null,
                status: $row->status !== null ? (string) $row->status : null,
                isManuallyOverridden: (bool) $row->is_manually_overridden,
                manualOverrideReason: $row->manual_override_reason !== null ? (string) $row->manual_override_reason : null,
                manualOverrideByUserId: $row->manual_override_by_user_id !== null ? (string) $row->manual_override_by_user_id : null,
                grantId: $row->grant_id !== null ? (string) $row->grant_id : null,
                cancelledReason: $row->cancelled_reason !== null ? (string) $row->cancelled_reason : null,
                createdAt: $row->created_at !== null ? (string) $row->created_at : null,
                manualOverrideAt: $row->manual_override_at !== null ? (string) $row->manual_override_at : null,
                updatedAt: $row->updated_at !== null ? (string) $row->updated_at : null,
                assessments: $assessments[$id] ?? [],
            );
        }

        return $entries;
    }

    /**
     * 照合による補完(論点19 決定1)の入力として、ReadModelの現在の行を読む(読み取りのみ。テーブルが無ければ空)。
     * users のパスワード・remember_token は読み込まない。
     */
    public function readSeededData(): SeededDataCurrent
    {
        $rows = function (string $table, ?string $orderBy = 'id'): array {
            if (! Schema::hasTable($table)) {
                return [];
            }
            $query = DB::table($table);
            if ($orderBy !== null && Schema::hasColumn($table, $orderBy)) {
                $query->orderBy($orderBy);
            }

            return array_map(fn ($row) => (array) $row, $query->get()->all());
        };

        $users = array_map(function (array $row): array {
            unset($row['password'], $row['remember_token']);

            return $row;
        }, $rows('users'));

        return new SeededDataCurrent(
            groupTypes: $rows('group_types'),
            groups: $rows('groups'),
            roles: $rows('roles'),
            permissionRole: $rows('permission_role', null),
            roleFeatures: $rows('role_features'),
            roleAssignments: $rows('role_assignments'),
            groupFeatureAssignments: $rows('group_feature_assignments'),
            users: $users,
            memberships: $rows('memberships'),
            externalIdentities: $rows('external_identities'),
            fieldAuthorities: $rows('field_authorities'),
            companyCalendarIds: Schema::hasTable('company_calendars') ? array_map('strval', DB::table('company_calendars')->pluck('id')->all()) : [],
            calendarYears: $rows('company_calendar_years'),
            calendarDays: $rows('company_calendar_days'),
            companyCalendars: array_map(
                fn (array $row) => array_intersect_key($row, array_flip(['id', 'is_default', 'created_at'])),
                $rows('company_calendars'),
            ),
            defaultTimezone: Schema::hasTable('system_settings') && Schema::hasColumn('system_settings', 'default_timezone')
                ? (($tz = DB::table('system_settings')->value('default_timezone')) !== null ? (string) $tz : null) : null,
            m365TenantId: Schema::hasTable('system_settings') && Schema::hasColumn('system_settings', 'm365_tenant_id')
                ? (($tenant = DB::table('system_settings')->value('m365_tenant_id')) !== null && $tenant !== '' ? (string) $tenant : null) : null,
        );
    }

    /**
     * ReadModel(company_calendar_days)の現在の行から、日の id => 年の id・日付(Y-m-d)の対応を読む(読み取りのみ。テーブルが無ければ空)。
     * 祝日の同期イベント(holiday_calendar_source.synced・sync_reverted)の dayChanges・dayReverts に companyCalendarYearId・date を補う入力
     * (論点19 決定8-2)。
     *
     * @return array<int, array{yearId: string, date: string}>
     */
    public function readCalendarDayIndex(): array
    {
        if (! Schema::hasTable('company_calendar_days')) {
            return [];
        }

        $index = [];
        foreach (DB::table('company_calendar_days')->select('id', 'calendar_id', 'date')->orderBy('id')->get() as $row) {
            $index[(int) $row->id] = ['yearId' => (string) $row->calendar_id, 'date' => substr((string) $row->date, 0, 10)];
        }

        return $index;
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

        // cutover後の paid_leave_account.* は変換の対象(新しい種類へ変換する)なので数えない。
        $newAccountKinds = DB::table('stored_events')
            ->where(fn ($query) => $query
                ->where('event_class', 'like', 'special_leave_account.%')
                ->orWhere('event_class', 'like', 'compensatory_leave_account.%'))
            ->count();
        if ($newAccountKinds > 0) {
            $violations[] = "新しい種類の口座イベント(special_leave_account.*・compensatory_leave_account.*)が {$newAccountKinds} 件ある(本番には無い前提)";
        }
        if (Schema::hasTable('snapshots') && DB::table('snapshots')->exists()) {
            $violations[] = 'snapshots テーブルに行がある(集約のスナップショットは作り直し後の履歴と一致しないため、削除してから実行する)';
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

        // SQLiteの索引名はデータベース全体で一意。swap後の stored_events は前回の作り直し表の索引名を持ち続けるので、同名なら避ける。
        $indexName = "{$table}_stream_unique";
        if (collect(Schema::getIndexes('stored_events'))->pluck('name')->contains($indexName)) {
            $indexName .= '_next';
        }

        Schema::create($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->id();
            $blueprint->uuid('aggregate_uuid')->nullable();
            $blueprint->unsignedBigInteger('aggregate_version')->nullable();
            $blueprint->unsignedTinyInteger('event_version')->default(1);
            $blueprint->string('event_class');
            $blueprint->json('event_properties');
            $blueprint->json('meta_data');
            $blueprint->timestamp('created_at');
            $blueprint->unique(['aggregate_uuid', 'aggregate_version'], $indexName);
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
