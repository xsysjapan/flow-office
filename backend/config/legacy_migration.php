<?php

use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\Attendance\Events\AttendancePunchCorrected;
use App\Domain\Attendance\Events\AttendancePunchDeleted;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\Attendance\Events\EmployeeShiftPlanChanged;
use App\Domain\Attendance\Events\EmployeeShiftPublished;
use App\Domain\Attendance\Events\WorkCalendarCreated;
use App\Domain\Attendance\Events\WorkCalendarDaysUpdated;
use App\Domain\Attendance\Events\WorkCalendarPublished;
use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\Attendance\Events\WorkStyleDefaultChanged;
use App\Domain\LegacyMigration\UuidMap;
use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use App\Domain\User\Events\UserHireDateSet;
use App\Domain\User\Events\UserLoggedIn;
use App\Domain\User\Events\UserMigratedFromLegacy;
use App\Domain\User\Events\UserRolesChanged;
use App\Domain\User\Events\UserSyncedFromMs365;
use App\Domain\User\Events\UserTerminationDateSet;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * 旧DBのdatetime(オフセットなし)を、Projectorが期待するオフセット付きISO8601文字列へ変換する。
 */
$withOffset = function (?string $datetime, int $utcOffsetMinutes): ?string {
    if ($datetime === null) {
        return null;
    }

    return LocalDateTime::formatWithOffsetMinutes(Carbon::parse($datetime), $utcOffsetMinutes);
};

/** 旧idが無ければnullを返すUUID解決ヘルパー。 */
$resolveOrNull = fn (UuidMap $map, string $table, mixed $legacyId): ?string => $legacyId !== null ? $map->resolve($table, $legacyId) : null;

/**
 * attendance_day集約のイベントは、旧システムでは「何が起きたか」の記録に留まり
 * (audit log)、新イベントが要求する「行を完全に再構築できるだけの全フィールド」を
 * 持たない(docs/30-legacy-data-migration.md 1節参照)。そのため、無いフィールドは
 * その集約の**現在の**行・子データから補完する(移行時点で分かる最終状態を使う近似)。
 * 現在の行が存在しない(移行後に削除された日)場合は妥当な既定値にフォールバックする。
 *
 * @return array{shift_assignment_id: ?string, status: string, source: string, utc_offset_minutes: int, work_type: ?string, work_location_type: ?string, note: ?string, breaks: array, leave_segments: array}
 */
$backfillDay = function (?stdClass $currentRow, array $children, UuidMap $map) use ($withOffset): array {
    $offset = $currentRow !== null ? (int) $currentRow->utc_offset_minutes : 540;

    return [
        'shift_assignment_id' => $currentRow?->shift_assignment_id !== null && $currentRow !== null
            ? $map->resolve('employee_shift_assignments', $currentRow->shift_assignment_id)
            : null,
        'status' => $currentRow->status ?? 'not_started',
        'source' => $currentRow->source ?? 'live',
        'utc_offset_minutes' => $offset,
        'work_type' => $currentRow->work_type ?? null,
        'work_location_type' => $currentRow->work_location_type ?? null,
        'note' => $currentRow->note ?? null,
        'breaks' => collect($children['breaks'] ?? [])->map(fn (stdClass $b) => [
            'start' => $withOffset($b->break_start_at, $offset),
            'end' => $withOffset($b->break_end_at, $offset),
        ])->values()->all(),
        'leave_segments' => collect($children['leave_segments'] ?? [])->map(fn (stdClass $s) => [
            'start' => $withOffset($s->start_at, $offset),
            'end' => $withOffset($s->end_at, $offset),
            'note' => $s->note,
        ])->values()->all(),
    ];
};

return [
    'actor_table' => 'user',

    /*
     * イベントソーシング対象外の、そのままコピーするだけのマスタテーブル。主キーの型は
     * 変わっていないため、UUID変換は不要。`legacy:convert`はこれらのテーブルの中身を
     * 一旦全削除してから legacy 側の行をそのままINSERTする(seederの既定行より
     * 旧DBの実データを正とする)。
     */
    'plain_copy_tables' => [
        'roles',
        'request_types',
        'employment_categories',
        'special_leave_types',
    ],

    /*
     * 集約ごとの定義。`legacy:convert`はこの単位で、旧`stored_events`(aggregate_type =
     * このキー)の全イベントをversion順に読み、新イベントへ変換する
     * (docs/30-legacy-data-migration.md参照)。
     *
     * - `table`: 現在の行(バックフィル・UUID採番)を読む対象テーブル。
     * - `depends_on`: 先に処理すべき他の集約キー(FK解決のため。UuidMapは遅延生成する
     *   ため必須ではないが、ログの読みやすさのために付けている)。
     * - `always_genesis`: 常に合成イベントを先頭(version 1)に記録する。旧システムでは
     *   エンティティの新規作成自体がイベント化されていないドメイン(User)で使う。
     * - `genesis`: 合成イベントを組み立てるクロージャ。`always_genesis`がfalseの場合も、
     *   その集約に旧イベントが1件も無いときの安全網として使われる(データ不整合で
     *   イベントが欠落していても、現在の行が消えてなくなることを防ぐ)。
     * - `events`: 旧`event_type`文字列 → 変換クロージャ、の対応表。クロージャは
     *   `(payload, uuidMap, currentRow, actorUuid, occurredAt, state, children)`を受け取り、
     *   `['event_class' => ..., 'properties' => [...], 'state' => [...]]`を返す
     *   (`properties`を省略するとstateの更新のみでイベントは発行しない。nullを返すと
     *   「対応不能」としてスキップ扱いになる)。対応表に無い`event_type`は自動的に
     *   スキップされ、実行時に警告として一覧表示される。
     */
    'aggregates' => [
        'user' => [
            'table' => 'users',
            'depends_on' => [],
            'always_genesis' => true,
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state): array {
                return [
                    'event_class' => UserMigratedFromLegacy::class,
                    'properties' => [
                        'attributes' => [
                            'entra_user_id' => $row->entra_user_id ?? null,
                            'name' => $row->name ?? '',
                            'email' => $row->email ?? '',
                            'department' => $row->department ?? null,
                            'job_title' => $row->job_title ?? null,
                            'employment_status' => $row->employment_status ?? 'active',
                            'hire_date' => $row->hire_date ?? null,
                            'termination_date' => $row->termination_date ?? null,
                            'timezone' => $row->timezone ?? 'Asia/Tokyo',
                            'last_login_at' => $row->last_login_at ?? null,
                        ],
                    ],
                    // user.synced_from_ms365は差分(changes)のみを持つため、既知の値を
                    // ここから積み上げていく(下記events参照)。
                    'state' => [
                        'entra_user_id' => $row->entra_user_id ?? null,
                        'name' => $row->name ?? '',
                        'email' => $row->email ?? '',
                        'department' => $row->department ?? null,
                        'job_title' => $row->job_title ?? null,
                        'employment_status' => $row->employment_status ?? 'active',
                    ],
                ];
            },
            'events' => [
                'user.roles_changed' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => UserRolesChanged::class,
                        'properties' => [
                            'previousRoleCodes' => $payload['previous_role_codes'] ?? [],
                            'newRoleCodes' => $payload['new_role_codes'] ?? [],
                            'changedByUserId' => $map->resolve('users', $payload['changed_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'user.hire_date_set' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => UserHireDateSet::class,
                        'properties' => [
                            'hireDate' => $payload['hire_date'],
                            'changedByUserId' => $map->resolve('users', $payload['changed_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'user.termination_date_set' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => UserTerminationDateSet::class,
                        'properties' => [
                            'terminationDate' => $payload['termination_date'] ?? null,
                            'changedByUserId' => $map->resolve('users', $payload['changed_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'user.logged_in' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => UserLoggedIn::class,
                        'properties' => [
                            'wasFirstLogin' => (bool) ($payload['was_first_login'] ?? false),
                            // 旧イベントには記録時刻を保持するフィールドが無いため、
                            // stored_eventsのoccurred_at(そのイベント自体が記録された日時)
                            // をそのまま採用する。
                            'loggedInAt' => Carbon::parse($occurredAt)->format('Y-m-d H:i:s'),
                        ],
                        'state' => $state,
                    ];
                },
                'user.synced_from_ms365' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    // 旧イベントは変更差分(changes)のみを持つため、genesisから積み上げてきた
                    // stateへ今回の差分をマージし、新イベントが要求する全フィールドを組み立てる。
                    $changes = $payload['changes'] ?? [];
                    $state = array_merge($state, $changes);

                    return [
                        'event_class' => UserSyncedFromMs365::class,
                        'properties' => [
                            // entra_user_idはMS365同期の差分(changes)には通常含まれない
                            // (初回リンク時にしか変わらない)ため、現在の行から補う。
                            'entraUserId' => $row->entra_user_id ?? $state['entra_user_id'] ?? '',
                            'name' => $state['name'] ?? '',
                            'email' => $state['email'] ?? null,
                            'department' => $state['department'] ?? null,
                            'jobTitle' => $state['job_title'] ?? null,
                            'employmentStatus' => $state['employment_status'] ?? 'active',
                        ],
                        'state' => $state,
                    ];
                },
            ],
        ],

        'attendance_punch' => [
            'table' => 'attendance_punches',
            'depends_on' => ['user'],
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state) use ($withOffset): array {
                return [
                    'event_class' => AttendancePunchRecorded::class,
                    'properties' => [
                        'userId' => $map->resolve('users', $row->user_id),
                        'workDate' => $row->work_date,
                        'punchType' => $row->punch_type,
                        'punchedAt' => $withOffset($row->punched_at, (int) $row->utc_offset_minutes),
                        'source' => 'legacy_migration',
                        'note' => $row->note ?? null,
                        'deviceId' => null,
                        'authenticationKeyId' => null,
                        'actorUserId' => null,
                        'offline' => false,
                        'idempotencyKey' => null,
                        'requestId' => null,
                    ],
                    'state' => $state,
                ];
            },
            'events' => [
                'attendance_punch.recorded' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => AttendancePunchRecorded::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'workDate' => $payload['work_date'],
                            'punchType' => $payload['punch_type'],
                            'punchedAt' => $payload['punched_at'],
                            'source' => $payload['source'],
                            // noteは旧イベントに含まれないため現在の行から補う(打刻は
                            // 追記専用でnoteが後から変わることは無いため、正確な値のはず)。
                            'note' => $row->note ?? null,
                            'deviceId' => null,
                            'authenticationKeyId' => null,
                            'actorUserId' => null,
                            'offline' => false,
                            'idempotencyKey' => null,
                            'requestId' => null,
                        ],
                        'state' => $state,
                    ];
                },
                'attendance_punch.corrected' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => AttendancePunchCorrected::class,
                        'properties' => [
                            'correctedPunchId' => $map->resolve('attendance_punches', $payload['corrected_punch_id']),
                            // userId/workDate/sourceは旧イベントに含まれないため、
                            // 元の打刻行(このイベントの集約=訂正対象自身)から補う。
                            'userId' => $row->user_id !== null ? $map->resolve('users', $row->user_id) : $actorUuid,
                            'workDate' => $row->work_date ?? $payload['punched_at'],
                            'punchType' => $payload['punch_type'],
                            'punchedAt' => $payload['punched_at'],
                            'source' => $row->source ?? 'legacy_migration',
                            'note' => $row->note ?? null,
                            'reason' => $payload['reason'],
                            'correctedByUserId' => $map->resolve('users', $payload['corrected_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'attendance_punch.deleted' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => AttendancePunchDeleted::class,
                        'properties' => [
                            'reason' => $payload['reason'],
                            'deletedByUserId' => $map->resolve('users', $payload['deleted_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
            ],
        ],

        'work_calendar' => [
            'table' => 'work_calendars',
            'depends_on' => [],
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state): array {
                return [
                    'event_class' => WorkCalendarCreated::class,
                    'properties' => [
                        'name' => $row->name,
                        'fiscalYear' => (int) $row->fiscal_year,
                        'startsOn' => $row->starts_on,
                        'endsOn' => $row->ends_on,
                        'weekStartsOn' => (int) $row->week_starts_on,
                        'createdByUserId' => $actorUuid,
                    ],
                    'state' => $state,
                ];
            },
            'events' => [
                'work_calendar.created' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => WorkCalendarCreated::class,
                        'properties' => [
                            'name' => $payload['name'],
                            'fiscalYear' => (int) $payload['fiscal_year'],
                            'startsOn' => $payload['starts_on'],
                            'endsOn' => $payload['ends_on'],
                            'weekStartsOn' => (int) $payload['week_starts_on'],
                            'createdByUserId' => $map->resolve('users', $payload['created_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                // 旧システムは1日ごとにwork_calendar_day.updatedを発行する。新イベントは
                // 複数日をまとめたバッチ(days配列)を期待するが、旧イベントからバッチの
                // 境界を正確に復元する手段が無いため、1件ずつ「1日だけのバッチ」として
                // 変換する(日ごとの実際の変更内容・時刻は正確に保たれる)。
                'work_calendar_day.updated' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => WorkCalendarDaysUpdated::class,
                        'properties' => [
                            'days' => [[
                                'date' => $payload['date'],
                                'day_type' => $payload['day_type'],
                                'is_working_day' => (bool) $payload['is_working_day'],
                                'is_legal_holiday' => (bool) $payload['is_legal_holiday'],
                                'is_company_holiday' => (bool) $payload['is_company_holiday'],
                            ]],
                            'updatedByUserId' => $map->resolve('users', $payload['updated_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'work_calendar.published' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => WorkCalendarPublished::class,
                        'properties' => [
                            'publishedByUserId' => $map->resolve('users', $payload['published_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
            ],
        ],

        'work_style' => [
            'table' => 'work_styles',
            'depends_on' => ['work_calendar'],
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state): array {
                return [
                    'event_class' => WorkStyleCreated::class,
                    'properties' => [
                        'attributes' => [
                            'employment_category_id' => $row->employment_category_id,
                            'code' => $row->code,
                            'name' => $row->name,
                            'work_time_system' => $row->work_time_system,
                            'prescribed_daily_minutes' => (int) $row->prescribed_daily_minutes,
                            'prescribed_weekly_minutes' => (int) $row->prescribed_weekly_minutes,
                            'deemed_daily_minutes' => $row->deemed_daily_minutes,
                            'variable_period_start_day' => $row->variable_period_start_day,
                            'settlement_start_day' => $row->settlement_start_day,
                            'core_time_enabled' => (bool) $row->core_time_enabled,
                            'core_time_start' => $row->core_time_start,
                            'core_time_end' => $row->core_time_end,
                            'flexible_time_start' => $row->flexible_time_start,
                            'flexible_time_end' => $row->flexible_time_end,
                            'default_start_time' => $row->default_start_time,
                            'default_end_time' => $row->default_end_time,
                            'default_break_minutes' => (int) $row->default_break_minutes,
                            'rounding_unit_minutes' => $row->rounding_unit_minutes,
                            'default_break_start_time' => $row->default_break_start_time,
                            'default_break_end_time' => $row->default_break_end_time,
                            'calendar_id' => $row->calendar_id !== null ? $map->resolve('work_calendars', $row->calendar_id) : null,
                            'is_shift_based' => (bool) $row->is_shift_based,
                            'is_default' => (bool) $row->is_default,
                            'system_generated' => (bool) $row->system_generated,
                            'legal_holiday_rule' => $row->legal_holiday_rule,
                            'max_consecutive_work_days' => $row->max_consecutive_work_days,
                            'four_week_period_start_date' => $row->four_week_period_start_date,
                            'auto_break_enabled' => (bool) ($row->auto_break_enabled ?? false),
                        ],
                        'createdByUserId' => $actorUuid,
                    ],
                    'state' => $state,
                ];
            },
            'events' => [
                'work_style.created' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    $attributes = $payload['attributes'];
                    if (array_key_exists('calendar_id', $attributes) && $attributes['calendar_id'] !== null) {
                        $attributes['calendar_id'] = $map->resolve('work_calendars', $attributes['calendar_id']);
                    }
                    // 新しいスキーマで追加された列(auto_break_enabled等)は旧attributesに
                    // 存在しないため、現在の行から補う。
                    $attributes['auto_break_enabled'] = (bool) ($row->auto_break_enabled ?? false);

                    return [
                        'event_class' => WorkStyleCreated::class,
                        'properties' => [
                            'attributes' => $attributes,
                            'createdByUserId' => $map->resolve('users', $payload['created_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'work_style.default_changed' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state) use ($resolveOrNull): array {
                    return [
                        'event_class' => WorkStyleDefaultChanged::class,
                        'properties' => [
                            'previousDefaultWorkStyleId' => $resolveOrNull($map, 'work_styles', $payload['previous_default_work_style_id'] ?? null),
                            'changedByUserId' => $map->resolve('users', $payload['changed_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
            ],
        ],

        'employee_shift_assignment' => [
            'table' => 'employee_shift_assignments',
            'depends_on' => ['user', 'work_style'],
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state): array {
                return [
                    'event_class' => EmployeeShiftAssigned::class,
                    'properties' => [
                        'userId' => $map->resolve('users', $row->user_id),
                        'workDate' => $row->work_date,
                        'workStyleId' => $row->work_style_id !== null ? $map->resolve('work_styles', $row->work_style_id) : null,
                        'shiftPatternId' => null,
                        'dayType' => $row->day_type,
                        'isWorkingDay' => (bool) $row->is_working_day,
                        'isLegalHoliday' => (bool) $row->is_legal_holiday,
                        'isCompanyHoliday' => (bool) $row->is_company_holiday,
                        'plannedStartAt' => $row->planned_start_at,
                        'plannedEndAt' => $row->planned_end_at,
                        'plannedBreakMinutes' => (int) $row->planned_break_minutes,
                        'plannedBreakStartAt' => $row->planned_break_start_at,
                        'plannedBreakEndAt' => $row->planned_break_end_at,
                        'isPublished' => (bool) $row->is_published,
                        'isManuallyOverridden' => (bool) $row->is_manually_overridden,
                        'assignedByUserId' => $actorUuid,
                    ],
                    'state' => $state,
                ];
            },
            'events' => [
                'employee_shift.assigned' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state) use ($resolveOrNull): array {
                    return [
                        'event_class' => EmployeeShiftAssigned::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'workDate' => $payload['work_date'],
                            'workStyleId' => $resolveOrNull($map, 'work_styles', $payload['work_style_id'] ?? null),
                            'shiftPatternId' => null,
                            'dayType' => $payload['day_type'],
                            'isWorkingDay' => (bool) $payload['is_working_day'],
                            'isLegalHoliday' => (bool) $payload['is_legal_holiday'],
                            'isCompanyHoliday' => (bool) $payload['is_company_holiday'],
                            'plannedStartAt' => $payload['planned_start_at'] ?? null,
                            'plannedEndAt' => $payload['planned_end_at'] ?? null,
                            'plannedBreakMinutes' => (int) ($payload['planned_break_minutes'] ?? 0),
                            'plannedBreakStartAt' => $payload['planned_break_start_at'] ?? null,
                            'plannedBreakEndAt' => $payload['planned_break_end_at'] ?? null,
                            // isPublished/isManuallyOverriddenは新規追加フィールドのため
                            // 旧イベントに無く、現在の行から補う。
                            'isPublished' => (bool) ($row->is_published ?? true),
                            'isManuallyOverridden' => (bool) ($row->is_manually_overridden ?? false),
                            'assignedByUserId' => $map->resolve('users', $payload['assigned_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'employee_shift.plan_changed' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => EmployeeShiftPlanChanged::class,
                        'properties' => [
                            'previousPlannedStartAt' => $payload['previous_planned_start_at'] ?? null,
                            'previousPlannedEndAt' => $payload['previous_planned_end_at'] ?? null,
                            'previousPlannedBreakMinutes' => (int) ($payload['previous_planned_break_minutes'] ?? 0),
                            'plannedStartAt' => $payload['planned_start_at'] ?? null,
                            'plannedEndAt' => $payload['planned_end_at'] ?? null,
                            'plannedBreakMinutes' => (int) ($payload['planned_break_minutes'] ?? 0),
                            'reason' => $payload['reason'],
                            'editedByUserId' => $map->resolve('users', $payload['edited_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'employee_shift.published' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => EmployeeShiftPublished::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'workDate' => $payload['work_date'],
                            'publishedByUserId' => $map->resolve('users', $payload['published_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
            ],
        ],

        'attendance_day' => [
            'table' => 'attendance_days',
            'depends_on' => ['user', 'employee_shift_assignment'],
            'children' => [
                'breaks' => [
                    'table' => 'attendance_breaks',
                    'parent_column' => 'attendance_day_id',
                ],
                'leave_segments' => [
                    'table' => 'attendance_leave_segments',
                    'parent_column' => 'attendance_day_id',
                ],
            ],
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state) use ($backfillDay): array {
                $fallback = $backfillDay($row, [], $map);

                return [
                    'event_class' => AttendanceDayCreated::class,
                    'properties' => array_merge($fallback, [
                        'shiftAssignmentId' => $fallback['shift_assignment_id'],
                        'workLocationType' => $fallback['work_location_type'],
                        'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                        'workType' => $fallback['work_type'],
                        'leaveSegments' => $fallback['leave_segments'],
                        'userId' => $row !== null ? $map->resolve('users', $row->user_id) : $actorUuid,
                        'workDate' => $row->work_date ?? null,
                        'actualStartAt' => null,
                        'actualEndAt' => null,
                        'reason' => '本番移行(旧システムからのデータ移行。合成イベント)',
                        'createdByUserId' => $actorUuid,
                    ]),
                    'state' => $state,
                ];
            },
            'events' => [
                // 旧attendance.day_createdは attendance_day_id/user_id/work_date/reason/
                // created_by_user_idのみを持ち、新イベントが要求するstatus/source/breaks等の
                // 全フィールドを持たない。現在の行・子データ(このイベントの時点ではまだ
                // 存在しない可能性のある将来の状態)から補う近似を行う
                // (docs/30-legacy-data-migration.md「1. 方針」で説明した許容トレードオフ)。
                'attendance.day_created' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state, array $children) use ($backfillDay): array {
                    $fallback = $backfillDay($row, $children, $map);

                    return [
                        'event_class' => AttendanceDayCreated::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'workDate' => $payload['work_date'],
                            'shiftAssignmentId' => $fallback['shift_assignment_id'],
                            'status' => $fallback['status'],
                            'source' => $fallback['source'],
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                            'actualStartAt' => null,
                            'actualEndAt' => null,
                            'workType' => $fallback['work_type'],
                            'workLocationType' => $fallback['work_location_type'],
                            'note' => $fallback['note'],
                            'breaks' => [],
                            'leaveSegments' => [],
                            'reason' => $payload['reason'],
                            'createdByUserId' => $map->resolve('users', $payload['created_by_user_id']),
                        ],
                        'state' => array_merge($state, ['actual_start_at' => null]),
                    ];
                },
                'attendance.clocked_in' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state) use ($backfillDay): array {
                    $fallback = $backfillDay($row, [], $map);

                    return [
                        'event_class' => AttendanceDayLiveStatusSynced::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'workDate' => $row->work_date ?? null,
                            'shiftAssignmentId' => $fallback['shift_assignment_id'],
                            'status' => 'working',
                            'source' => $fallback['source'],
                            'actualStartAt' => $payload['actual_start_at'],
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                        ],
                        // 退勤時にAttendanceDaySyncedFromPunchesを組み立てるため、
                        // 出勤時刻をstateへ憶えておく。
                        'state' => array_merge($state, ['actual_start_at' => $payload['actual_start_at']]),
                    ];
                },
                'attendance.break_started' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state) use ($backfillDay): array {
                    $fallback = $backfillDay($row, [], $map);

                    return [
                        'event_class' => AttendanceDayLiveStatusSynced::class,
                        'properties' => [
                            'userId' => $row !== null ? $map->resolve('users', $row->user_id) : $actorUuid,
                            'workDate' => $row->work_date ?? null,
                            'shiftAssignmentId' => $fallback['shift_assignment_id'],
                            'status' => 'on_break',
                            'source' => $fallback['source'],
                            'actualStartAt' => null,
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                        ],
                        'state' => $state,
                    ];
                },
                'attendance.break_ended' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state) use ($backfillDay): array {
                    $fallback = $backfillDay($row, [], $map);

                    return [
                        'event_class' => AttendanceDayLiveStatusSynced::class,
                        'properties' => [
                            'userId' => $row !== null ? $map->resolve('users', $row->user_id) : $actorUuid,
                            'workDate' => $row->work_date ?? null,
                            'shiftAssignmentId' => $fallback['shift_assignment_id'],
                            'status' => 'working',
                            'source' => $fallback['source'],
                            'actualStartAt' => null,
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                        ],
                        'state' => $state,
                    ];
                },
                'attendance.clocked_out' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state, array $children) use ($backfillDay): array {
                    $fallback = $backfillDay($row, $children, $map);

                    return [
                        'event_class' => AttendanceDaySyncedFromPunches::class,
                        'properties' => [
                            'userId' => $row !== null ? $map->resolve('users', $row->user_id) : $actorUuid,
                            'workDate' => $row->work_date ?? null,
                            'shiftAssignmentId' => $fallback['shift_assignment_id'],
                            'actualStartAt' => $state['actual_start_at'] ?? ($row->actual_start_at ?? null),
                            'actualEndAt' => $payload['actual_end_at'],
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                            'workLocationType' => $fallback['work_location_type'],
                            'breaks' => $fallback['breaks'],
                        ],
                        'state' => $state,
                    ];
                },
                'attendance_day.synced_from_punches' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state, array $children) use ($backfillDay): array {
                    $fallback = $backfillDay($row, $children, $map);

                    return [
                        'event_class' => AttendanceDaySyncedFromPunches::class,
                        'properties' => [
                            'userId' => $row !== null ? $map->resolve('users', $row->user_id) : $actorUuid,
                            'workDate' => $row->work_date ?? null,
                            'shiftAssignmentId' => $fallback['shift_assignment_id'],
                            'actualStartAt' => $payload['actual_start_at'],
                            'actualEndAt' => $payload['actual_end_at'],
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                            'workLocationType' => $fallback['work_location_type'],
                            'breaks' => $fallback['breaks'],
                        ],
                        'state' => $state,
                    ];
                },
                // 旧attendance.day_editedは「誰が・なぜ編集したか」のみを記録し、実際に
                // 何を変更したかは持たない。現在の行・子データから全フィールドを補う
                // (この編集固有の差分ではなく、移行時点の最終状態になる近似)。
                'attendance.day_edited' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state, array $children) use ($backfillDay): array {
                    $fallback = $backfillDay($row, $children, $map);

                    return [
                        'event_class' => AttendanceDayEdited::class,
                        'properties' => [
                            'utcOffsetMinutes' => $fallback['utc_offset_minutes'],
                            'actualStartAt' => $row->actual_start_at ?? null,
                            'actualEndAt' => $row->actual_end_at ?? null,
                            'status' => $fallback['status'],
                            'workType' => $fallback['work_type'],
                            'workLocationType' => $fallback['work_location_type'],
                            'workLocationTypeProvided' => $fallback['work_location_type'] !== null,
                            'note' => $fallback['note'],
                            'breaks' => $fallback['breaks'],
                            'leaveSegments' => $fallback['leave_segments'],
                            'reason' => $payload['reason'],
                            'editedByUserId' => $map->resolve('users', $payload['edited_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'attendance.day_calculated' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    $calculation = $payload;
                    unset($calculation['attendance_day_id']);

                    return [
                        'event_class' => AttendanceDayCalculated::class,
                        'properties' => [
                            'calculation' => $calculation,
                        ],
                        'state' => $state,
                    ];
                },
                'attendance.daily_calculation_adjusted' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => AttendanceDailyCalculationAdjusted::class,
                        'properties' => [
                            'prescribedWorkMinutes' => (int) $payload['prescribed_work_minutes'],
                            'statutoryWithinOvertimeMinutes' => (int) $payload['statutory_within_overtime_minutes'],
                            'statutoryExcessOvertimeMinutes' => (int) $payload['statutory_excess_overtime_minutes'],
                            'legalHolidayWorkMinutes' => (int) $payload['legal_holiday_work_minutes'],
                            'prescribedHolidayWorkMinutes' => (int) $payload['prescribed_holiday_work_minutes'],
                            'lateNightPrescribedWorkMinutes' => (int) $payload['late_night_prescribed_work_minutes'],
                            'lateNightStatutoryWithinOvertimeMinutes' => (int) $payload['late_night_statutory_within_overtime_minutes'],
                            'lateNightStatutoryExcessOvertimeMinutes' => (int) $payload['late_night_statutory_excess_overtime_minutes'],
                            'lateNightLegalHolidayWorkMinutes' => (int) $payload['late_night_legal_holiday_work_minutes'],
                            'reason' => $payload['reason'],
                            'adjustedByUserId' => $map->resolve('users', $payload['adjusted_by_user_id']),
                        ],
                        'state' => $state,
                    ];
                },
                'attendance.day_deleted' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => AttendanceDayDeleted::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'workDate' => $payload['work_date'],
                            'reason' => $payload['reason'],
                            'deletedByUserId' => $map->resolve('users', $payload['deleted_by_user_id']),
                            'punchLogAction' => $payload['punch_log_action'],
                        ],
                        'state' => $state,
                    ];
                },
            ],
        ],

        'paid_leave_grant' => [
            'table' => 'paid_leave_grants',
            'depends_on' => ['user'],
            'genesis' => function (?stdClass $row, UuidMap $map, string $actorUuid, array $state): array {
                return [
                    'event_class' => PaidLeaveGranted::class,
                    'properties' => [
                        'userId' => $map->resolve('users', $row->user_id),
                        'grantedOn' => $row->granted_on,
                        'expiresOn' => $row->expires_on,
                        'grantedDays' => (float) $row->granted_days,
                        'grantReason' => $row->grant_reason ?? null,
                    ],
                    'state' => $state,
                ];
            },
            'events' => [
                'paid_leave.granted' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => PaidLeaveGranted::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'grantedOn' => $payload['granted_on'],
                            'expiresOn' => $payload['expires_on'],
                            'grantedDays' => (float) $payload['granted_days'],
                            // grant_reasonは旧イベントに無いため現在の行から補う。
                            'grantReason' => $row->grant_reason ?? null,
                        ],
                        'state' => $state,
                    ];
                },
                'paid_leave.warning_raised' => function (array $payload, UuidMap $map, ?stdClass $row, string $actorUuid, string $occurredAt, array $state): array {
                    return [
                        'event_class' => PaidLeaveWarningRaised::class,
                        'properties' => [
                            'userId' => $map->resolve('users', $payload['user_id']),
                            'warningType' => $payload['warning_type'],
                            'message' => $payload['message'],
                        ],
                        'state' => $state,
                    ];
                },
                // paid_leave.used はpaid_leave_requests/paid_leave_usagesの変換に対応してから
                // 扱う(docs/30-legacy-data-migration.md「未実施・今後の作業」参照)。
                // このスクリプトでは意図的に対応表から除外し、自動的にスキップ+警告される。
            ],
        ],
    ],
];
