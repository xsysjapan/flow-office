# 16. 主要テーブル案

このテーブル一覧はマイグレーション作成前のドラフト。列の型・NULL可否・インデックスは
実装時のマイグレーションで確定させる。`created_at` / `updated_at` は全テーブル共通。

## stored_events (EventStore / 正)

全ドメインイベントの記録。Projectionはここから再生成可能。

- id
- event_id
- aggregate_type
- aggregate_id
- version
- event_type
- payload
- metadata
- occurred_at
- created_at / updated_at

## users

- id
- entra_user_id
- name
- email
- department
- job_title
- employment_status
- last_login_at
- created_at / updated_at

## workflow_requests

- id
- request_type_id
- title
- applicant_user_id
- approver_user_id
- status
- form_data
- submitted_at
- approved_at
- returned_at
- cancelled_at
- created_at / updated_at

## request_types

- id
- code
- name
- description
- form_schema
- requires_backoffice_task
- backoffice_task_type
- is_active
- created_at / updated_at

## backoffice_tasks

- id
- source_type
- source_id
- task_type
- title
- status
- assigned_department
- assigned_user_id
- due_on
- completed_at
- created_at / updated_at

## work_calendars

- id
- name
- fiscal_year
- starts_on
- ends_on
- week_starts_on
- status
- created_at / updated_at

## work_calendar_days

- id
- calendar_id
- date
- day_type
- is_working_day
- is_legal_holiday
- is_company_holiday
- note
- created_at / updated_at

## work_styles

- id
- code
- name
- work_time_system
- prescribed_daily_minutes
- prescribed_weekly_minutes
- default_start_time
- default_end_time
- default_break_minutes
- calendar_id
- is_shift_based
- created_at / updated_at

## shift_patterns

- id
- code
- name
- start_time
- end_time
- crosses_midnight
- break_minutes
- prescribed_work_minutes
- created_at / updated_at

## employee_shift_assignments (勤務予定の正)

- id
- user_id
- work_date
- work_style_id
- shift_pattern_id
- day_type
- is_working_day
- is_legal_holiday
- is_company_holiday
- planned_start_at
- planned_end_at
- planned_break_minutes
- created_at / updated_at

## attendance_days (勤務実績の正)

- id
- user_id
- work_date
- shift_assignment_id
- status
- source (`live` / `manual` / `punch`。actual_start_at等をどの経路で最後に確定したか)
- actual_start_at
- actual_end_at
- work_type
- note
- locked_at
- created_at / updated_at

## attendance_breaks

- id
- attendance_day_id
- break_start_at
- break_end_at
- created_at / updated_at

## attendance_punches (打刻ログ。参考情報、勤務実績の正ではない)

- id
- user_id
- work_date (打刻元が明示的に指定する所属業務日。例: 21:00出勤〜翌6:00退勤の夜勤は両方同じwork_date)
- punch_type (`clock_in` / `break_start` / `break_end` / `clock_out`)
- punched_at (実際に打刻が発生した日時)
- source (打刻元。`web` / `ic_card` / `mobile` など、将来のデバイス種別を自由に追加できる文字列)
- note
- created_at / updated_at

同一user_id・work_dateに対して重複・矛盾した打刻が記録されることを前提とし、一意制約は
設けない。矛盾なく1日分の勤務として組み立てられた場合のみ `attendance_days` /
`attendance_breaks` に反映される (docs/07-usecases-attendance.md UC-A012)。

## attendance_daily_calculations (Projection: 日次集計)

- id
- attendance_day_id
- planned_work_minutes
- actual_work_minutes
- prescribed_work_minutes
- non_statutory_overtime_minutes
- statutory_overtime_minutes
- late_night_minutes
- legal_holiday_work_minutes
- company_holiday_work_minutes
- legal_holiday_late_night_minutes
- created_at / updated_at

## attendance_months (Projection: 月次スナップショット)

- id
- user_id
- year_month
- status
- approver_user_id
- submitted_at
- approved_at
- returned_at
- closed_at
- snapshot_json
- created_at / updated_at

## paid_leave_grant_rules

- id
- name
- work_style_id
- min_attendance_rate
- first_grant_after_months
- grant_cycle_months
- is_active
- created_at / updated_at

## paid_leave_grant_rule_steps

- id
- rule_id
- continuous_service_months
- grant_days
- created_at / updated_at

## paid_leave_grants (有給付与の正)

- id
- user_id
- granted_on
- expires_on
- granted_days
- used_days
- remaining_days
- grant_reason
- created_at / updated_at

## paid_leave_usages

- id
- user_id
- attendance_day_id
- paid_leave_grant_id
- used_on
- used_days
- used_minutes
- usage_type
- created_at / updated_at

## attachments

- id
- owner_type
- owner_id
- uploaded_by
- file_name
- stored_path
- mime_type
- file_size
- created_at / updated_at

## テーブル分類の考え方

| 分類 | テーブル | 特徴 |
|---|---|---|
| EventStore (正) | `stored_events` | 全ドメインイベントの唯一の正。削除・改変しない。 |
| マスタ | `request_types`, `work_calendars`, `work_calendar_days`, `work_styles`, `shift_patterns`, `paid_leave_grant_rules`, `paid_leave_grant_rule_steps` | 管理者が設定する参照データ。 |
| 正データ (書き込み対象) | `users`, `workflow_requests`, `backoffice_tasks`, `employee_shift_assignments`, `attendance_days`, `attendance_breaks`, `paid_leave_grants`, `paid_leave_usages`, `attachments` | Command経由でのみ更新。 |
| 参考ログ (正ではない) | `attendance_punches` | 矛盾があっても記録される生ログ。矛盾なく組み立てられた場合のみ正データ (`attendance_days`) に反映される。 |
| Projection (再生成可能) | `attendance_daily_calculations`, `attendance_months` | `stored_events` + 正データから再計算できる派生データ。 |
