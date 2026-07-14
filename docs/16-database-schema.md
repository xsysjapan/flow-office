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
- default_work_style_id (nullable。`work_styles`への外部キー。`user_work_style_monthly_assignments`
  にも該当月の割当が無いユーザーの勤怠計算で使うフォールバック用の働き方)
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

## employment_categories

- id
- code (`regular` / `contract` / `part_time` / `temporary` / `commissioned` / `other`)
- name
- created_at / updated_at

雇用区分マスタ。労働時間制度(`work_styles.work_time_system`)とは独立した軸として管理し、
雇用区分だけで残業計算・適用除外を決定しない。

## work_styles

- id
- employment_category_id (雇用区分。nullable。「正社員・通常」「パート・シフト勤務」のように
  雇用区分と労働時間制度の組み合わせを`work_styles`の1レコードとして表現する)
- code
- name
- work_time_system (`fixed`=通常勤務 / `monthly_variable`=1か月単位変形労働時間制 /
  `discretionary`=裁量労働制 / `manager_supervisor`=管理監督者 / `flex`=フレックスタイム制。
  シフト制かどうかは`work_time_system`ではなく`is_shift_based`で表現する。旧値
  `shortened`/`shift_based`はデータ移行済みで新規作成時は受け付けない)
- prescribed_daily_minutes
- prescribed_weekly_minutes
- deemed_daily_minutes (裁量労働制のみなし時間。`work_time_system=discretionary`のみ使用。
  8時間を超える設定の場合、超過分は毎稼働日の法定時間外として自動計上する)
- default_start_time
- default_end_time
- default_break_minutes
- calendar_id (nullable。シフト制などカレンダーに依存しない勤務形態を許容する)
- is_shift_based
- is_default (会社のデフォルト働き方かどうか。常に高々1件のみtrue。切り替え時は
  `SetDefaultWorkStyle`コマンドで既存のデフォルトを解除してから設定する。
  `system_settings.default_work_style_id`(勤怠計算のフォールバック先)と同期させる。
  未設定(=デフォルトがどの働き方にも設定されていない)状態と、デフォルトが適用されている
  状態は明確に区別し、未設定を暗黙的な固定時間勤務として扱わない)
- system_generated (初回オンボーディングで自動生成された働き方であることの印。
  編集後も通常の働き方として利用でき、この値自体が変わることはない)
- legal_holiday_rule (`weekly`=毎週1日 / `four_weeks_four_days`=4週4日以上の変形休日制 /
  `undetermined`=決めない方式(UC-C007参照)。`is_shift_based`の勤務形態にのみ意味を持つ。
  UC-C005参照)
- four_week_period_start_date (`legal_holiday_rule`が`four_weeks_four_days`の場合の
  4週間の起算日)
- variable_period_start_day (`work_time_system=monthly_variable`の変形期間の起算日。
  暦月の何日を起算日にするか(1〜31)。1なら暦月と一致。他の労働時間制度では未使用)
- max_consecutive_work_days (nullable。3交代制シフト表(UC-C004)の公開前チェックで使う
  連続勤務日数の警告しきい値。法令上の一律の上限は無いため会社の就業規則次第でマスタ化
  する。未設定ならチェックしない)
- settlement_start_day (`work_time_system=flex`の清算期間の起算日。暦月の何日を起算日に
  するか(1〜31)。未設定なら1日。`variable_period_start_day`と同じ考え方だが、変形労働時間制
  とフレックスタイム制は別の制度のため列を分けている)
- core_time_enabled (フレックスタイム制でコアタイムを設定するかどうか)
- core_time_start / core_time_end (コアタイムの開始・終了時刻。`core_time_enabled=true`の
  場合は両方必須。勤務可能時間帯(`flexible_time_start`/`flexible_time_end`)が設定されて
  いる場合はその範囲内でなければならない)
- flexible_time_start / flexible_time_end (勤務可能時間帯。任意設定で、フルフレックス
  (時間帯を限定しない)の場合は未設定のままでよい)
- created_at / updated_at

労使協定・本人同意の管理は本システムのスコープ外とする(適法性の証跡管理ではなく、勤怠を
正しく計算することを目的とするため)。管理監督者・裁量労働制も、みなし時間・適用除外の
計算ロジックのみを実装し、協定期限・同意状態のチェックは行わない。

### 一覧画面の集計列(指示書16.1節)

`GET /api/work-styles`(働き方一覧)は、`WorkStyleUsageSummaryCalculator`が以下を都度計算し
レスポンスに含める。他のエンドポイント(作成・デフォルト設定等、単体のWorkStyleを返す経路)
ではこれらの値は含まれない(`null`/空配列)。

- applied_employee_count (今月時点でこの働き方が適用される社員数。
  `user_work_style_monthly_assignments`で明示的にこの働き方を指定している社員数に加え、
  対象がデフォルト働き方の場合は「今月どの働き方も明示的に指定していない全社員」も含める)
- active_shift_pattern_count (`is_shift_based`の働き方のみ。`employee_shift_assignments`で
  実際に使われている`shift_pattern_id`の種類数。シフト制でない場合はnull)
- configuration_warnings (設定不備の警告文の配列。現状は「シフト制なのに勤務予定に
  一度も使われていない」の1種類のみ実装。指示書16.1節・19章・20章が挙げるその他の警告
  (夜勤シフト不足等)は未実装)

指示書16.1節・16.2節が求める「有効期間」「状態(下書き/有効/将来有効/無効/廃止)」は、
`work_styles`にライフサイクル管理の概念(`is_active`・バージョニング等)が無いため未対応
(働き方は物理削除しない方針(指示書24章)自体は既に守られているが、無効化・廃止の状態遷移
そのものが未実装)。

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

## rotation_patterns (UC-C008: 交代制勤務のローテーションパターン)

- id
- work_style_id (シフト制の勤務形態のみ登録できる)
- name
- cycle_length (周期の長さ。`rotation_pattern_items`の件数と一致させる)
- created_at / updated_at

A勤・B勤・C勤・休のような繰り返し周期を1つの働き方の中でまとめて管理する
(A勤・B勤・C勤を別々の働き方として作らない、指示書8.1節)。

## rotation_pattern_items (ローテーションパターンを構成する各順序)

- id
- rotation_pattern_id
- sequence (0始まり。`rotation_pattern_id`との組でunique)
- shift_pattern_id
- created_at / updated_at

## employee_rotation_assignments (社員ごとのローテーション基準。正データ)

- id
- user_id (unique。1人につき現在有効な基準は1件のみ)
- rotation_pattern_id
- rotation_start_date (この日にrotation_start_position番目のパターンが適用される)
- rotation_start_position (0始まり)
- assigned_by_user_id
- created_at / updated_at

指定日がローテーション周期の何番目にあたるかは
`(rotation_start_position + rotation_start_dateからの経過日数) % cycle_length` で機械的に
算出する(`EmployeeRotationAssignment::sequenceIndexFor`)。将来の班単位管理(複数社員へ
同じローテーションを一括割当てる、指示書8.6節)を妨げないよう、社員個別の基準として
独立させている。

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
- is_published (3交代制シフトパターン割当(UC-C004)の下書き/公開フラグ。カレンダー基準の
  一括生成(UC-C003)からの行は常にtrue。シフトパターン日別割当は公開されるまでfalse)
- is_manually_overridden (UC-C004のシフトパターン個別割当を経由した日はtrue。ローテーション
  からの一括生成(UC-C008)は、既定(`skip_edited`)ではこのフラグがtrueの日を自動上書き
  しない。ローテーション生成自体はfalseのまま書き込む)
- created_at / updated_at

`is_legal_holiday`は「決める方式」(`work_styles.legal_holiday_rule`が`weekly`/
`four_weeks_four_days`)でのみ意味を持つ事前設定値。「決めない方式」(`undetermined`)では
この列を使わず、`LegalHolidayResolver`が`legal_holiday_designations`または
`is_working_day=false`の自動推定から都度解決する(UC-C007参照)。

## user_work_style_monthly_assignments (ユーザーの月次働き方割当。正データ)

- id
- user_id
- year_month (`YYYY-MM`)
- work_style_id
- assigned_by_user_id
- created_at / updated_at

ユーザーがどの月にどの働き方(`work_styles`)に属するかを月単位で記録する正データ。
例えば10月までは通常勤務、11月からシフト勤務のように働き方を切り替えても、過去月の
割当は変更されずに履歴として残る(`user_id` + `year_month`のunique制約。月次で
`AssignUserWorkStyleForMonth`コマンドにより追加・更新する)。

月次の割当は自動的には後続の月へ引き継がれない(対象の年月に行が無ければ
`system_settings.default_work_style_id`にフォールバックする)ため、恒常的な切り替えは
対象月以降の各月を個別に割り当てる必要がある。フロント(`WorkStylesAndShiftsPage.tsx`
`MonthlyWorkStyleAssignmentCard`)は保存前に、対象月の現在の働き方→変更後の働き方の
差分と、次の2点を影響範囲として表示する(指示書 14章)。

- 対象月より前の月の割当・勤怠には影響しない。
- 対象月内で既に打刻・日次編集済みの日の`attendance_daily_calculations`は、働き方の
  割当を変更しただけでは自動的に再計算されない(`AttendanceCalculator`はClockOut/
  EditAttendanceDay等のコマンド実行時にのみ呼ばれるため)。反映するには対象日を
  日次編集から保存し直す必要がある。

保存時に「この働き方をもとに勤務予定を自動生成する」を選択すると、対象月の1日〜末日で
`GenerateEmployeeShiftAssignments`(UC-C003)を続けて実行する。この一括生成は既存の
`employee_shift_assignments`を条件なく上書きするため(打刻・実績の有無を見ない)、
既に実績のある日を含む月に対して安易に使わないよう画面上で明示する。

指示書 13章: 社員個別の画面(`UserRoleEditPage.tsx`)では、今月について
「●会社のデフォルトを使用/○別の働き方を指定」の二択で表示・編集できる。「別の働き方を
指定」を選んで保存すると`AssignUserWorkStyleForMonth`で今月の行を作成・更新し、
「会社のデフォルトを使用」に戻すと`RemoveUserWorkStyleMonthlyAssignment`で今月の行を
削除する(=システムのデフォルト働き方へのフォールバックに戻す)。過去月の履歴を書き換え
させないため、対象年月が今月より前の行は削除できない(`RemoveUserWorkStyleMonthlyAssignmentHandler`
がガードする)。削除も`user_work_style_monthly_assignment.removed`イベントとして記録するため、
「デフォルトを使用する場合でも適用結果が追跡可能」という指示書13章の要件を満たす。

`employee_shift_assignments`にその日の`work_style_id`が無い場合、勤怠計算
(`AttendanceCalculator`)はまずその勤務日が属する年月の本テーブルの割当を参照し、
それも無ければ`system_settings.default_work_style_id`にフォールバックする。

## legal_holiday_designations (法定休日「決めない方式」の指定。正データ)

- id
- user_id
- week_start_date (指定対象の週の起算日)
- designated_date (その週の法定休日として指定する日。week_start_dateから7日以内)
- reason
- designated_by (指定した社員のuser_id)
- created_at / updated_at

同一user_id・week_start_dateへの再指定は既存の指定を置き換える(unique制約)。
`work_styles.legal_holiday_rule=undetermined`の勤務形態にのみ意味を持つ。

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
- actual_work_minutes (実労働時間。健康管理用。常に実際の時刻から計算する)
- deemed_work_minutes (裁量労働制のみなし時間。対象外の日はnull。docs/07-usecases-attendance.md
  「裁量労働制・管理監督者」参照)
- payroll_work_minutes (給与計算上使用する労働時間。通常はactual_work_minutesと同じだが、
  裁量労働制はdeemed_work_minutesを採用する)
- prescribed_work_minutes
- non_statutory_overtime_minutes
- statutory_overtime_minutes
- late_night_minutes
- statutory_overtime_late_night_minutes (法定外深夜。statutory_overtime_minutesのうち深夜時間帯
  (22:00〜05:00)と重なる分。late_night_minutesの内訳であり別枠の加算ではない)
- legal_holiday_work_minutes
- company_holiday_work_minutes
- legal_holiday_late_night_minutes
- core_time_violation (フレックスタイム制(`work_time_system=flex`)でコアタイムを設定した日、
  実際の勤務がコアタイムを全てカバーしていないかどうか。労働時間の不足とは別枠の警告として
  扱う。フレックス以外の勤務形態、および出退勤の実績が無い日は常にfalse。docs/07-usecases
  -attendance.md「フレックスタイム制」参照)
- created_at / updated_at

週40時間(労基法32条)判定は独立したProjectionを持たない。週次勤怠は日次勤怠の編集ビューであり
月のような集計単位ではないため、`App\Domain\Attendance\Services\WeeklyOvertimeCalculator` が
月次確認画面の表示のたびに `attendance_daily_calculations` から都度計算する参考情報として扱う
(docs/07-usecases-attendance.md「週40時間判定」、UC-C005の法定休日要件チェックと同じ考え方)。
同様に、フレックスタイム制の清算期間ダッシュボード(必要労働時間・残り労働時間等)も
`App\Domain\Attendance\Services\FlexSettlementSummaryCalculator` が表示のたびに都度計算する
参考情報であり、独立したProjectionを持たない。

月60時間超残業(労基法37条)も同様に独立したProjectionを持たない。`statutory_overtime_minutes`
を月初から都度合算する参考情報として `App\Domain\Attendance\Services\MonthlyOvertimeCalculator`
が日次勤怠取得のたびに計算し、`AttendanceDayResource.monthly_overtime` として返す
(docs/07-usecases-attendance.md「月60時間超残業判定」参照)。

月次確認画面(UC-A007)には、同じ`MonthlyOvertimeCalculator::calculateCategoryTotals()`が
対象月全体の9区分(実働・所定内残業・法定外残業・60時間以内/超・深夜・法定外深夜・
法定休日労働・所定休日労働・法定休日深夜)を合算した結果を`monthly_calculation_totals`
として都度計算して返す(`AttendanceController::month()`。提出前でも進捗の目安として表示する)。
週40時間判定とは異なり、この60時間以内/超の按分は`statutory_overtime_minutes`の単純な
按分であり別の集計軸との二重計上が起きないため、提出時(UC-A008)は同じ集計結果を
`attendance_months.snapshot_json`にも確定値として保存する。

## attendance_months (イベント駆動の状態テーブル。正データ)

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

`attendance_daily_calculations`とは異なり、`attendance_months`は対応するProjector・
`projections:rebuild`での再生成経路を持たない。`SubmitAttendanceMonthHandler`/
`ApproveAttendanceMonthHandler`/`ReturnAttendanceMonthHandler`/`CloseAttendanceMonthHandler`
が`stored_events`への追記と同一トランザクションで直接更新する「正データ」であり、
「Projection(再生成可能)」には分類しない。

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
| マスタ | `request_types`, `work_calendars`, `work_calendar_days`, `employment_categories`, `work_styles`, `shift_patterns`, `rotation_patterns`, `rotation_pattern_items`, `paid_leave_grant_rules`, `paid_leave_grant_rule_steps`, `system_settings` | 管理者が設定する参照データ。 |
| 正データ (書き込み対象) | `users`, `workflow_requests`, `backoffice_tasks`, `employee_shift_assignments`, `employee_rotation_assignments`, `attendance_days`, `attendance_breaks`, `attendance_months`, `legal_holiday_designations`, `paid_leave_grants`, `paid_leave_requests`, `paid_leave_usages`, `attachments` | Command経由でのみ更新。`attendance_months`はProjectorを持たず、CommandHandlerが直接書き込む。 |
| 参考ログ (正ではない) | `attendance_punches` | 矛盾があっても記録される生ログ。矛盾なく組み立てられた場合のみ正データ (`attendance_days`) に反映される。 |
| Projection (再生成可能) | `attendance_daily_calculations` | `stored_events` + 正データから再計算できる派生データ。`projections:rebuild`で再生成できる。 |
