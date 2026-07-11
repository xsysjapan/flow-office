# 16. 主要テーブル案

このテーブル一覧はマイグレーション作成前のドラフト。列の型・NULL可否・インデックスは
実装時のマイグレーションで確定させる。`created_at` / `updated_at` は全テーブル共通。

**日時カラムの扱い**: `_at` で終わるカラムはタイムゾーン情報を持たない壁時計表記で保存する。
API境界(リクエスト・レスポンスの両方)では常にオフセット付きISO8601形式で日時をやり取りし、
変換は `App\Support\LocalDateTime` に集約する(詳細は docs/03-architecture.md 3.4)。
どのオフセットで解釈するかは2系統ある。

- 一般的な日時(`users.last_login_at`, `workflow_requests.submitted_at` 等): システムの
  デフォルトタイムゾーン(`system_settings.default_timezone`)で解釈する。画面表示は
  `users.timezone`(ユーザーごとの表示用タイムゾーン設定)に変換して見せる。
- 勤怠の勤務実績(`attendance_days` / `attendance_breaks` / `attendance_punches`): 固定の
  タイムゾーン名ではなく、その勤務日・打刻自身が保持するUTCオフセット(`utc_offset_minutes`、
  分単位の整数)で解釈する。海外出張などで勤務日ごとに現地時刻(オフセット)が変わるため。
  画面表示もこのオフセットのまま(ユーザーの既定タイムゾーンには変換しない)。

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
- timezone (IANAタイムゾーン識別子。例: `Asia/Tokyo`。新規作成時は `system_settings.default_timezone`
  を初期値とする。MS365同期では上書きしない)
- hire_date (入社日。MS365に対応する属性がないため同期対象外で、管理者が個別に設定する。
  有給の自動付与(docs/09-usecases-paid-leave.md UC-P002)の継続勤務期間の基準日に使う)
- last_login_at
- created_at / updated_at

## system_settings (システム全体設定。単一行)

- id
- default_timezone (新規作成ユーザーの初期タイムゾーン。既定値 `Asia/Tokyo`)
- created_at / updated_at

常に1行のみ存在するシングルトン設定。Command/EventStoreを経由せず、管理者専用APIから
直接更新する([UC-003](./06-usecases-auth.md#uc-003-システム設定default_timezoneを管理する))。

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
- utc_offset_minutes (actual_start_at/actual_end_at/breaksに適用されたUTCオフセット(分)。
  例: `+09:00` なら540、`-05:00` なら-300。社員本人の既定タイムゾーン(users.timezone)とは
  別に、勤務日ごとに保持する。海外出張などで現地時刻が変わるため(docs/03-architecture.md 3.4))
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
- utc_offset_minutes (punched_atに適用されたUTCオフセット(分)。打刻元から送信された通りの
  値をタイムゾーン変換せずそのまま保持する (docs/03-architecture.md 3.4))
- source (打刻元。`web` / `ic_card` / `mobile` など、将来のデバイス種別を自由に追加できる文字列)
- note
- status (`active` / `corrected` / `deleted`。既定値 `active`。
  docs/07-usecases-attendance.md UC-A013/UC-A014)
- correction_reason (訂正・削除の理由。`status` が `corrected` / `deleted` の行にのみ設定)
- corrected_by_user_id (訂正・削除を行った社員。nullable)
- corrected_at (訂正・削除が行われた日時。nullable)
- superseded_by_punch_id (訂正後の打刻ログのid。`status=corrected` の行にのみ設定。
  自己参照の nullable 外部キー)
- created_at / updated_at

同一user_id・work_dateに対して重複・矛盾した打刻が記録されることを前提とし、一意制約は
設けない。全ての打刻が同一のutc_offset_minutesであることも「矛盾がない」ことの条件の1つと
する(オフセットが混在すると壁時計時刻どうしの前後比較に意味がなくなるため)。矛盾なく1日分
の勤務として組み立てられた場合のみ `attendance_days` / `attendance_breaks` に反映される
(docs/07-usecases-attendance.md UC-A012)。

打刻ログは追記のみで、既存の行を直接書き換えない。訂正(UC-A013)は元の行を`status=corrected`
にした上で、訂正後の値を新しい行として追記する。削除(UC-A014)は行を物理削除せず
`status=deleted` にするだけで、どちらも理由・実行者・日時を保持したまま参照できる。
`status=active` の行のみが日次勤怠への反映(組み立て直し)の対象になる。

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
- expiry_warned_at (UC-P005: 消滅警告を通知済みの日時。重複通知防止用)
- five_day_obligation_warned_at (UC-P006: 年5日取得義務警告を通知済みの日時。重複通知防止用)
- created_at / updated_at

## paid_leave_requests (有給申請の正)

- id
- user_id
- approver_user_id
- status (`submitted` / `approved` / `returned` / `cancelled`)
- leave_type (`full` / `am_half` / `pm_half` / `hourly`)
- target_date
- hours (leave_type=hourlyのときのみ使用)
- requested_days (取得日数。full=1.0、half=0.5、hourly=hours÷所定労働時間から計算)
- reason
- submitted_at / approved_at / returned_at / cancelled_at
- created_at / updated_at

汎用申請(workflow_requests)・バックオフィス処理(backoffice_tasks)と同様、独立した
ステータス系列で管理する (docs/09-usecases-paid-leave.md UC-P003/UC-P004)。

## paid_leave_usages

- id
- user_id
- attendance_day_id
- paid_leave_grant_id
- paid_leave_request_id
- used_on
- used_days
- used_minutes
- usage_type
- created_at / updated_at

1件の `paid_leave_requests` の承認が、有効期限が近い複数の `paid_leave_grants` にまたがって
消化される場合、grantごとに1行作成される。

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
| マスタ | `request_types`, `work_calendars`, `work_calendar_days`, `work_styles`, `shift_patterns`, `paid_leave_grant_rules`, `paid_leave_grant_rule_steps`, `system_settings` | 管理者が設定する参照データ。 |
| 正データ (書き込み対象) | `users`, `workflow_requests`, `backoffice_tasks`, `employee_shift_assignments`, `attendance_days`, `attendance_breaks`, `paid_leave_grants`, `paid_leave_requests`, `paid_leave_usages`, `attachments` | Command経由でのみ更新。 |
| 参考ログ (正ではない) | `attendance_punches` | 矛盾があっても記録される生ログ。矛盾なく組み立てられた場合のみ正データ (`attendance_days`) に反映される。 |
| Projection (再生成可能) | `attendance_daily_calculations`, `attendance_months` | `stored_events` + 正データから再計算できる派生データ。 |
