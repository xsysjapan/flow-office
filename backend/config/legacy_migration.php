<?php

use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\Attendance\Events\WorkCalendarCreated;
use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\LegacyMigration\UuidMap;
use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\User\Events\UserMigratedFromLegacy;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * 旧DBのdatetime(オフセットなし)を、Projectorが期待するオフセット付きISO8601文字列へ
 * 変換する。旧DBには元々どのタイムゾーンで打刻されたかの情報が無いため、その行自身の
 * utc_offset_minutes(既定540=JST)をそのまま採用する。
 */
$withOffset = function (?string $datetime, int $utcOffsetMinutes): ?string {
    if ($datetime === null) {
        return null;
    }

    return LocalDateTime::formatWithOffsetMinutes(Carbon::parse($datetime), $utcOffsetMinutes);
};

/**
 * 本番カットオーバー移行(docs/30-legacy-data-migration.md)専用の設定。
 *
 * `legacy:export`(旧DBから現在の行をJSONへ書き出す) → `legacy:convert`
 * (JSONを読み、新スキーマのUUIDへ変換した上でstored_eventsへ直接INSERTする)の2段階で使う。
 *
 * ここに載っているテーブルは、このリポジトリの一連のspatie移行で主キーがUUID化された
 * 「真の集約」(docs/29-event-sourcing-framework-migration.md参照)のうち、このスクリプトが
 * 対応済みのものだけ。残りのテーブルも同じ形の定義を追加すれば同じ仕組みで移行できる
 * (docs/30-legacy-data-migration.md「対応済みドメインと未対応ドメイン」参照)。
 *
 * 各テーブル定義:
 * - `table`: 旧DB・新DB共通のテーブル名。
 * - `depends_on`: 先に変換が必要な他テーブル定義キー(外部キー解決のため)。
 * - `event_class`: この行の「作成」を表す既存のイベントクラス(通常の業務イベントを
 *   そのまま使う。UserだけはUserMigratedFromLegacyという移行専用イベントを使う。
 *   理由はdocs/30-legacy-data-migration.md参照)。
 * - `map`: 旧行(stdClass、旧カラム名のまま) + UuidMap + 移行実行者UUID を受け取り、
 *   イベントのコンストラクタ引数配列を返すクロージャ。
 * - `children`(任意): 子テーブル(attendance_breaks等、そのもの単体では集約ではない
 *   テーブル)を、親行のUUID解決時に一緒に埋め込むための定義。
 */
return [
    'actor_table' => 'users',

    /*
     * イベントソーシング対象外の、そのままコピーするだけのマスタテーブル。主キーの型は
     * 変わっていないため、UUID変換は不要。ただし新スキーマ側で既にseederが投入済みの行と
     * 衝突する可能性がある(例: RoleSeeder/RequestTypeSeeder/SystemSettingSeeder)ため、
     * `legacy:convert`はこれらのテーブルの中身を一旦全削除してから legacy 側の行をそのまま
     * INSERTする(=本番カットオーバーではこれらのマスタもseederではなく旧DBの実データを
     * 正とする)。
     */
    'plain_copy_tables' => [
        'roles',
        'request_types',
        'employment_categories',
        'special_leave_types',
    ],

    'tables' => [
        'users' => [
            'table' => 'users',
            'depends_on' => [],
            'event_class' => UserMigratedFromLegacy::class,
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid): array {
                return [
                    'attributes' => [
                        'entra_user_id' => $row->entra_user_id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'department' => $row->department,
                        'job_title' => $row->job_title,
                        'employment_status' => $row->employment_status,
                        'hire_date' => $row->hire_date,
                        'termination_date' => $row->termination_date,
                        'timezone' => $row->timezone,
                        'last_login_at' => $row->last_login_at,
                    ],
                ];
            },
        ],

        'work_calendars' => [
            'table' => 'work_calendars',
            'depends_on' => [],
            'event_class' => WorkCalendarCreated::class,
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid): array {
                return [
                    'name' => $row->name,
                    'fiscalYear' => (int) $row->fiscal_year,
                    'startsOn' => $row->starts_on,
                    'endsOn' => $row->ends_on,
                    'weekStartsOn' => (int) $row->week_starts_on,
                    'createdByUserId' => $actorUuid,
                ];
            },
        ],

        'work_styles' => [
            'table' => 'work_styles',
            'depends_on' => ['work_calendars'],
            'event_class' => WorkStyleCreated::class,
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid): array {
                return [
                    'attributes' => [
                        // employment_category_idは移行対象外(int PKのまま)なのでそのまま転記する。
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
                ];
            },
        ],

        'employee_shift_assignments' => [
            'table' => 'employee_shift_assignments',
            'depends_on' => ['users', 'work_styles'],
            'event_class' => EmployeeShiftAssigned::class,
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid): array {
                return [
                    'userId' => $map->resolve('users', $row->user_id),
                    'workDate' => $row->work_date,
                    'workStyleId' => $row->work_style_id !== null ? $map->resolve('work_styles', $row->work_style_id) : null,
                    // shift_patternsは本スクリプト未対応のため、旧データに割当があっても
                    // nullにする(移行後にshift_patternsを追加対応した時点で埋め直す)。
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
                ];
            },
        ],

        'attendance_days' => [
            'table' => 'attendance_days',
            'depends_on' => ['users', 'employee_shift_assignments'],
            'event_class' => AttendanceDayCreated::class,
            'children' => [
                'breaks' => [
                    'table' => 'attendance_breaks',
                    'parent_column' => 'attendance_day_id',
                ],
                'leave_segments' => [
                    'table' => 'attendance_leave_segments',
                    'parent_column' => 'attendance_day_id',
                ],
                'calculations' => [
                    'table' => 'attendance_daily_calculations',
                    'parent_column' => 'attendance_day_id',
                ],
            ],
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid, array $children) use ($withOffset): array {
                $offset = (int) $row->utc_offset_minutes;

                return [
                    'userId' => $map->resolve('users', $row->user_id),
                    'workDate' => $row->work_date,
                    'shiftAssignmentId' => $row->shift_assignment_id !== null
                        ? $map->resolve('employee_shift_assignments', $row->shift_assignment_id)
                        : null,
                    'status' => $row->status,
                    'source' => $row->source,
                    'utcOffsetMinutes' => $offset,
                    'actualStartAt' => $withOffset($row->actual_start_at, $offset),
                    'actualEndAt' => $withOffset($row->actual_end_at, $offset),
                    'workType' => $row->work_type,
                    'workLocationType' => $row->work_location_type ?? null,
                    'note' => $row->note,
                    'breaks' => collect($children['breaks'])->map(fn (stdClass $b) => [
                        'start' => $withOffset($b->break_start_at, $offset),
                        'end' => $withOffset($b->break_end_at, $offset),
                    ])->values()->all(),
                    'leaveSegments' => collect($children['leave_segments'])->map(fn (stdClass $s) => [
                        'start' => $withOffset($s->start_at, $offset),
                        'end' => $withOffset($s->end_at, $offset),
                        'note' => $s->note,
                    ])->values()->all(),
                    'reason' => '本番移行(旧システムからのデータ移行)',
                    'createdByUserId' => $actorUuid,
                ];
            },
            'extra_events' => function (stdClass $row, UuidMap $map, string $actorUuid, array $children): array {
                $calc = $children['calculations'][0] ?? null;
                if ($calc === null) {
                    return [];
                }

                if ((bool) $calc->is_manually_adjusted) {
                    // 手動補正されている日は、このスクリプトではAttendanceDayCalculatedの
                    // 再計算結果側ではなく手動補正値を正としたいが、AttendanceDailyCalculationAdjusted
                    // への変換は未対応(このスクリプトの対応範囲外)。docs/30-legacy-data-migration.md
                    // に従い、実際の移行までに対応するか、移行後に手で補正し直すこと。
                    throw new RuntimeException(
                        "attendance_daily_calculations.attendance_day_id={$row->id} は手動補正済み".
                        '(is_manually_adjusted=true)のため、この簡易マップでは移行できません。'
                    );
                }

                return [[
                    'event_class' => AttendanceDayCalculated::class,
                    'properties' => [
                        'calculation' => [
                            'planned_work_minutes' => (int) $calc->planned_work_minutes,
                            'work_minutes' => (int) $calc->work_minutes,
                            'deemed_work_minutes' => $calc->deemed_work_minutes !== null ? (int) $calc->deemed_work_minutes : null,
                            'payroll_work_minutes' => (int) $calc->payroll_work_minutes,
                            'prescribed_work_minutes' => (int) $calc->prescribed_work_minutes,
                            'statutory_within_overtime_minutes' => (int) $calc->statutory_within_overtime_minutes,
                            'statutory_excess_overtime_minutes' => (int) $calc->statutory_excess_overtime_minutes,
                            'late_night_work_minutes' => (int) $calc->late_night_work_minutes,
                            'late_night_prescribed_work_minutes' => (int) $calc->late_night_prescribed_work_minutes,
                            'late_night_statutory_within_overtime_minutes' => (int) $calc->late_night_statutory_within_overtime_minutes,
                            'late_night_statutory_excess_overtime_minutes' => (int) $calc->late_night_statutory_excess_overtime_minutes,
                            'legal_holiday_work_minutes' => (int) $calc->legal_holiday_work_minutes,
                            'prescribed_holiday_work_minutes' => (int) $calc->prescribed_holiday_work_minutes,
                            'late_night_legal_holiday_work_minutes' => (int) $calc->late_night_legal_holiday_work_minutes,
                            'core_time_violation' => (bool) $calc->core_time_violation,
                            'absence_minutes' => (int) $calc->absence_minutes,
                            'special_leave_minutes' => (int) $calc->special_leave_minutes,
                            'paid_leave_days' => (float) $calc->paid_leave_days,
                            'paid_leave_minutes' => (int) $calc->paid_leave_minutes,
                            'special_leave_days' => (float) ($calc->special_leave_days ?? 0),
                        ],
                    ],
                ]];
            },
        ],

        'attendance_punches' => [
            'table' => 'attendance_punches',
            'depends_on' => ['users'],
            'event_class' => AttendancePunchRecorded::class,
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid) use ($withOffset): array {
                return [
                    'userId' => $map->resolve('users', $row->user_id),
                    'workDate' => $row->work_date,
                    'punchType' => $row->punch_type,
                    'punchedAt' => $withOffset($row->punched_at, (int) $row->utc_offset_minutes),
                    'source' => 'legacy_migration',
                    'note' => $row->note,
                    // device_id/authentication_key_idは旧DBのスキーマ次第(このリポジトリの
                    // main分岐時点では未実装のため列自体が無い)。存在すれば同じ考え方
                    // (別テーブルをUUID解決)でresolveする。
                    'deviceId' => null,
                    'authenticationKeyId' => null,
                    'actorUserId' => $row->corrected_by_user_id !== null
                        ? $map->resolve('users', $row->corrected_by_user_id)
                        : null,
                    'offline' => false,
                    'idempotencyKey' => null,
                    'requestId' => null,
                ];
            },
        ],

        'paid_leave_grants' => [
            'table' => 'paid_leave_grants',
            'depends_on' => ['users'],
            'event_class' => PaidLeaveGranted::class,
            'map' => function (stdClass $row, UuidMap $map, string $actorUuid): array {
                if ((float) $row->used_days > 0) {
                    // このスクリプトはpaid_leave_usagesまで変換していないため、消化済みの
                    // 付与が混ざっているとused_days/remaining_daysが一致しなくなる。
                    // 実際の本番カットオーバーではpaid_leave_usagesも同じ枠組みで変換し、
                    // PaidLeaveUsedイベントを追加で発行すること
                    // (docs/30-legacy-data-migration.md参照)。
                    throw new RuntimeException(
                        "paid_leave_grants.id={$row->id} はused_days>0のため、この簡易マップでは移行できません。".
                        'paid_leave_usagesの変換に対応してから再実行してください。'
                    );
                }

                return [
                    'userId' => $map->resolve('users', $row->user_id),
                    'grantedOn' => $row->granted_on,
                    'expiresOn' => $row->expires_on,
                    'grantedDays' => (float) $row->granted_days,
                    'grantReason' => $row->grant_reason,
                ];
            },
        ],
    ],
];
