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
- password (nullable。初回オンボーディング(UC-000)でSSOを設定しなかった場合のローカル
  パスワードログイン用。`hashed`キャストで自動ハッシュ化して保存する。SSOでログインする
  ユーザーはnullのまま)
- department
- job_title
- employment_status
- timezone (IANAタイムゾーン識別子。例: `Asia/Tokyo`。新規作成時は `system_settings.default_timezone`
  を初期値とする。MS365同期では上書きしない)
- hire_date (入社日。MS365に対応する属性がないため同期対象外で、管理者が個別に設定する。
  有給の自動付与(docs/09-usecases-paid-leave.md UC-P002)の継続勤務期間の基準日に使う)
- termination_date (退社日。未設定なら在籍中。入社日以降の日付だけを設定でき、月次勤怠の
  表示対象は入社月から退社月または当月までとする)
- last_login_at
- created_at / updated_at

## system_settings (システム全体設定。単一行)

- id
- default_timezone (新規作成ユーザーの初期タイムゾーン。既定値 `Asia/Tokyo`)
- default_work_style_id (nullable。`work_styles`への外部キー。`user_work_style_monthly_assignments`
  にも該当月の割当が無いユーザーの勤怠計算で使うフォールバック用の働き方)
- attendance_submission_deadline_day / attendance_month_close_deadline_day (docs/13-usecases-notification.md UC-N001)
- m365_tenant_id / m365_client_id / m365_client_secret (SSOログイン(UC-001)・MS365ユーザー
  同期(UC-002)・Graphメール送信(UC-N001)で共有するEntra IDアプリ登録の資格情報。
  `m365_client_secret`は平文で保持せず暗号化して保存する。初回オンボーディング(UC-000)で
  登録し、以後はUC-003のシステム設定画面から変更できる)
- m365_mock_enabled (ローカル開発用モックOIDC(mock-oidc/)を使うかどうか。この値はモック
  サーバーへの切替だけでなく、開発専用の危険なエンドポイント(DB初期化)のゲートも兼ねる。
  未認証の初回オンボーディングからも書き込める値のため、DBの値だけに依存せず
  `Ms365ConfigResolver::mockEnabled()`が`APP_ENV`が`local`/`testing`の場合のみtrueを返す
  ように強制する。本番・検証環境ではこの列の値に関わらず常にfalse扱いになる)
- onboarding_started_at (nullable。初回オンボーディング(UC-000)のSSOモードが開始済みかどうか。
  `onboarding_completed_at`とは別に持つことで、設定保存→実際のEntra IDログイン待ち、という
  2リクエストにまたがる状態を表現する。開始から10分経っても完了していなければ再クレームを
  許可する)
- onboarding_completed_at (nullable。初回オンボーディング(UC-000)が完了済みかどうか。
  未設定の間のみ`POST /api/onboarding/sso`・`POST /api/onboarding/local`を未認証で受け付ける)
- notification_mail_enabled (メール通知の有効/無効。falseまたはm365資格情報・送信元アドレスが
  未設定の場合は送信しない)
- notification_mail_sender_address / notification_mail_sender_name (送信元メールボックス)
- created_at / updated_at

常に1行のみ存在するシングルトン設定。Command/EventStoreを経由せず、管理者専用APIから
直接更新する([UC-003](./06-usecases-auth.md#uc-003-システム設定default_timezone等を管理する))。
初回作成(`m365_*`・`onboarding_started_at`・`onboarding_completed_at`)は
[UC-000](./06-usecases-auth.md#uc-000-初回オンボーディングを実行する)経由(未認証で呼べる
`StartOnboardingSso`/`CompleteOnboardingWithLocalPassword`コマンド)で行う。これらのコマンドは
「読んでから書く」二段階処理によるレース条件を避けるため、`SystemSetting::claimOnboarding()`/
`completeOnboarding()`という単一UPDATE文で条件判定と書き込みを同時に行うヘルパーを使う
(通常のEloquentモデル経由の更新のようにmutator/castsを自動適用しないクエリビルダ直書きの
ため、`encrypted`キャストの列は`SystemSetting`側で自前で暗号化してから書き込む)。

日次勤怠の入力画面で、打刻・勤務予定のいずれも無い日の初期値(「システムの初期設定」)は、
`system_settings`自体に列を追加せず、`default_work_style_id`が指すデフォルト働き方の
`default_start_time`/`default_end_time`/`default_break_start_time`/`default_break_end_time`
を使う(`AttendanceDayDefaultsResolver`参照)。`AttendanceCalculator`の働き方フォールバック
(その月の割当 → デフォルト働き方)と同じ解決順序を`WorkStyleFallbackResolver`として共有する。

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
- rounding_unit_minutes (nullable。`5`/`10`/`15`/`30`のいずれか。日次勤怠の入力画面で
  打刻内容を初期値として反映する際の丸め単位。未設定(またはnull)は丸めない。丸め方向は
  切上げ・切下げを区別せず、最も近い単位への四捨五入とする)
- default_break_start_time / default_break_end_time (標準休憩の開始・終了時刻。勤務予定・
  打刻のいずれも無い日の初期値(システムの初期設定)として使う。未設定なら休憩の初期値は
  表示しない)
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
- break_start_time / break_end_time (休憩の開始・終了時刻。日次勤怠の初期値(勤務予定の
  休憩を含めて表示する)に使う。未設定なら休憩の開始・終了時刻は初期値に反映しない
  (`break_minutes`のみでは開始・終了時刻を算出できないため)。深夜勤の休憩が日付を跨ぐ
  場合、`break_end_time`が`break_start_time`以前ならシフト割当時に自動的に翌日扱いにする)
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
- planned_break_start_at / planned_break_end_at (nullable。休憩の開始・終了時刻。
  `planned_break_minutes`(合計分数)とは別に持つ。カレンダー基準の一括生成(UC-C003)は
  `work_styles.default_break_start_time`/`default_break_end_time`から、3交代制シフト
  パターン割当(UC-C004)は`shift_patterns.break_start_time`/`break_end_time`から設定する。
  日次勤怠の入力画面で「打刻が無く勤務予定がある日は、その予定(休憩を含む)を初期値として
  表示する」ために使う(docs/07-usecases-attendance.md参照))
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
- work_location_type (勤務形態区分。`office`=出社 / `remote`=在宅 / `client_site`=客先 /
  `business_trip`=出張 / `direct_to_site`=直行 / `direct_from_site`=直帰 / `other`=その他。
  nullable。既存の`work_type`(有給区分: `paid_leave_full`等)とは別軸のため列を分ける。
  法令で変動する値ではないためマスタ化せず、`PunchType`と同様の定数クラス
  (`WorkLocationType`)で列挙する。時間計算には一切影響しない分類情報。共有Android打刻
  リーダー(docs/23-usecases-devices.md)経由の打刻が日次勤怠に反映される際、使用した
  端末の`devices.default_work_location_type`から自動反映されるほか、日次編集(UC-A005)・
  出勤日新規作成(UC-A016)で手動選択もできる)
- note
- locked_at
- created_at / updated_at

## attendance_breaks

- id
- attendance_day_id
- break_start_at
- break_end_at
- created_at / updated_at

## attendance_leave_segments (勤怠の正の一つ。docs/07-usecases-attendance.md「不就労時間の処理区分」)

勤務予定のうち勤務しなかった時間帯を、有給休暇以外の理由(欠勤・特別休暇)でどう処理したかを
時間帯単位で保持する。実績(`attendance_days.actual_start_at`/`actual_end_at`)の内側・
外側どちらの時間帯も表現できる。日次編集・作成のたびに全件入れ替える
(`attendance_breaks`と同じ扱い)。有給休暇(全休・半休・時間単位)はこのテーブルの対象外で、
既存の`paid_leave_requests`/`paid_leave_usages`/`attendance_days.work_type`で管理する。

- id
- attendance_day_id
- category (`absence`=欠勤 / `special_leave`=その他特別休暇)
- start_at
- end_at
- note
- created_at / updated_at

## attendance_punches (打刻ログ。参考情報、勤務実績の正ではない)

- id
- user_id
- work_date (打刻元が明示的に指定する所属業務日。例: 21:00出勤〜翌6:00退勤の夜勤は両方同じwork_date)
- punch_type (`clock_in` / `break_start` / `break_end` / `clock_out`)
- punched_at (実際に打刻が発生した日時)
- utc_offset_minutes (punched_atに適用されたUTCオフセット(分)。打刻元から送信された通りの
  値をタイムゾーン変換せずそのまま保持する (docs/03-architecture.md 3.4))
- source (打刻元。`web` / `ic_card` / `mobile` / `shared_device` / `personal_device` /
  `external_device` / `mcp` など、将来のデバイス種別を自由に追加できる文字列。表示用の
  簡易ラベル。どの端末・認証キー経由かの正確な参照は下記`device_id`/`authentication_key_id`
  を使う)
- note
- status (`active` / `corrected` / `deleted`。既定値 `active`。
  docs/07-usecases-attendance.md UC-A013/UC-A014)
- correction_reason (訂正・削除の理由。`status` が `corrected` / `deleted` の行にのみ設定)
- corrected_by_user_id (訂正・削除を行った社員。nullable)
- corrected_at (訂正・削除が行われた日時。nullable)
- superseded_by_punch_id (訂正後の打刻ログのid。`status=corrected` の行にのみ設定。
  自己参照の nullable 外部キー)
- device_id (nullable。`devices`への外部キー。共有Android打刻リーダー・個人端末・外部端末を
  経由した打刻の場合に設定する。「どこから打刻したか」の正の記録。Web画面からの打刻は
  null のまま)
- authentication_key_id (nullable。`authentication_keys`への外部キー。NFCカード・生体認証等の
  認証キーで身元を解決した打刻の場合に設定する)
- actor_user_id (nullable。操作主体の社員。対象社員(`user_id`)と異なる場合がある
  (管理者代理操作、UC-A012の「本人または管理者」原則)。共有端末で認証キーから解決した
  本人が打刻した場合は`user_id`と同じ値になる)
- integration_id (nullable。`application_integrations`への外部キー。個人API/MCP連携経由の
  打刻の場合に設定する。Claude等のAIアプリ自体は操作主体にならない(docs/03-architecture.md 3.5))
- offline (boolean。既定値false。端末がオフライン時にローカルキューされ、後から送信された
  打刻かどうか。docs/23-usecases-devices.md「オフライン対応」参照)
- idempotency_key (nullable。端末・クライアントが生成する冪等性キー。同一キーでの再送を
  重複登録として扱わない。unique制約を張るが、nullは複数許容する(MySQL/SQLite共通の挙動)。
  検証自体はCommandHandlerが「同じキーの行が既に存在するか」を確認して行う(DB制約だけに
  依存しない、既存コードベースの「業務ルールはHandlerで検証する」方針に合わせる))
- request_id (nullable。リクエスト追跡用のID。監査ログ・障害調査で使う)
- metadata_json (nullable。位置情報など、上記に当てはまらない付随情報を格納する。
  法務判断・計算に使う値をここに紛れ込ませない(あくまで参考の付随情報))
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

## 端末・認証キー・アプリ連携(マスタ/正データ)

打刻専用の端末エンティティを作らず、共有Android打刻リーダー・個人端末・外部端末(NFCリーダー、
生体認証端末、入退室管理端末、他社勤怠システム等)を共通の`devices`モデルで扱う
(docs/23-usecases-devices.md)。ユーザーに紐づく認証手段(NFC・生体認証端末の外部ID・QR・
FIDO等)は`authentication_keys`で、NFCカード専用の列を`users`に持たせない
(docs/24-usecases-authentication-keys.md)。個人/組織のAPI・MCP連携は`application_integrations`
で扱う(docs/25-usecases-integrations-mcp.md)。3つは互いに独立した概念であり、1つのテーブルに
まとめない(CLAUDE.mdの設計原則12)。

### devices (端末マスタ。正データ)

- id
- owner_type (`organization_shared` / `personal`)
- owner_user_id (nullable。`owner_type=personal`の場合のみ設定。個人端末の所有者)
- activated_by_user_id (nullable。UC-D002のペアリング用一時トークン(claim token)を発行した
  管理者。UC-D006の管理者ICカード初回登録(ブートストラップ)時に、アクティベーションを
  行った本人を判定するために使う)
- name (表示名。例:「本社1階受付」「田中太郎のスマートフォン」)
- device_type (`android` / `ios` / `web_browser` / `windows` / `macos` / `linux` /
  `nfc_reader` / `fingerprint_reader` / `face_recognition_device` / `access_control_device` /
  `iot_device` / `external_system` / `other`)
- status (`pending_pairing` / `active` / `disabled` / `revoked`)
- site_id (nullable。所属事業所。事業所マスタが未実装の間は自由文字列でも可)
- location_name (設置場所の表示名。例:「本社1階受付カウンター」。「どこから打刻したか」を
  残すための表示用情報)
- default_work_location_type (nullable。この端末で打刻した場合に`attendance_days.work_location_type`
  へ自動反映する値。上記列挙値。未設定なら自動反映しない)
- timezone (端末設置場所のタイムゾーン。未設定なら`system_settings.default_timezone`)
- allowed_punch_types (json、nullable。この端末で許可する打刻種別の制限。未設定なら全種別許可)
- allow_offline (boolean。オフライン時のローカルキュー・後日同期を許可するか)
- require_location (boolean。打刻時に位置情報の送信を必須にするか)
- auto_detect_punch_type (boolean。現在の勤怠状態から次の打刻種別を自動判定するか
  (true)、複数候補を選択させるか(false)。docs/23「自動判定と手動選択」参照)
- last_seen_at (nullable。最終通信日時)
- app_version (nullable。端末アプリのバージョン)
- paired_at (nullable。ペアリング完了日時)
- disabled_at / revoked_at (nullable)
- created_at / updated_at

`organization_shared`はマスタ(管理者が設定する参照データ、`request_types`と同じ方針で
Command/EventStoreを経由せずEloquentで直接更新)、`personal`はユーザー本人が登録・削除する
正データという性質の違いはあるが、テーブル自体は1つに統合する(役割・権限の違いは
`owner_type`と下記`device_roles`/`device_scopes`で表現し、テーブルを分けない)。

### device_roles (端末役割。1端末に複数可)

- id
- device_id
- role_type (`attendance_reader` / `authentication_device` / `access_control` /
  `personal_operation` / `admin_operation` / `external_event_source`)
- settings_json (役割ごとの追加設定)
- created_at / updated_at

### device_scopes (外部端末に付与するAPIスコープ)

- id
- device_id
- scope (例: `attendance:clock` / `attendance:read_current_state` / `attendance:read_result` /
  `identity:resolve` / `device:heartbeat`)
- created_at / updated_at

外部端末からは月次締めや管理者補正を実行できないよう、`attendance:clock`等の限定スコープ
のみを付与する(docs/23-usecases-devices.md「外部端末登録」参照)。`admin:mode`は
UC-D006の管理者モード(社員証NFCの現地登録)専用のスコープで、他の管理系APIへは
アクセスできない。

### device_admin_sessions (端末の管理者モード。UC-D006)

- id
- device_id
- admin_user_id (管理者モードを開始した管理者本人)
- authentication_key_id (nullable。NFCタップで開始した場合の認証キー。ブートストラップ
  経路の場合はnull)
- source (`bootstrap` / `nfc_tap`)
- started_at
- expires_at (有効期限。切れると管理者モードは自動的に終了する)
- ended_at (nullable。明示的な終了、または新しいセッションによる置き換え)
- created_at / updated_at

ある端末について「有効な(`ended_at`がnullかつ`expires_at`が未来の)」行が同時に
複数存在することはない(新しいセッション開始時に既存の有効なセッションを終了させる)。

### authentication_keys (認証キー。正データ)

- id
- user_id
- key_type (`nfc_uid` / `employee_card_id` / `qr_code` / `barcode` /
  `fingerprint_external_id` / `face_recognition_external_id` / `fido_credential` /
  `bluetooth_device_id` / `external_system_user_id` / `custom`)
- display_name (利用者が付けるラベル。例:「本社ICカード」「右手人差し指」)
- key_hash (認証キーの生値は保存しない。`HMAC-SHA256(app_secret, normalize(input))`のハッシュ値
  のみを保存し、検索・照合はハッシュ値で行う。docs/24-usecases-authentication-keys.md参照)
- status (`active` / `suspended` / `disabled`)
- valid_from / valid_until (nullable。有効期間)
- metadata_json (nullable。生体認証端末が発行した外部利用者IDなど、`key_hash`とは別に
  保持したい付随情報。**指紋画像・顔画像・特徴量データ等の生体情報そのものは一切含めない**)
- registered_by_user_id (本人 or 代理登録した管理者)
- registered_at
- disabled_at (nullable)
- created_at / updated_at

同一の認証キー(同一`key_type`+同一の生値からハッシュ化した`key_hash`)は、有効な
(`status=active`かつ有効期間内の)行として複数ユーザーに重複登録できない
(`RegisterAuthenticationKeyHandler`が登録時に検証する。docs/24参照)。生体情報そのものを
保存しない方針(CLAUDE.mdの設計原則12)を徹底するため、指紋認証・顔認証は必ず外部の
生体認証端末側でテンプレート照合を完結させ、その端末が発行した「認証済み外部利用者ID」
(`fingerprint_external_id`/`face_recognition_external_id`)だけをここに登録する。

### authentication_key_device_rules (認証キーの利用制限)

- id
- authentication_key_id
- device_id (nullable。特定端末のみに制限する場合)
- site_id (nullable。特定事業所のみに制限する場合)
- allow (boolean。既定値true。明示的に拒否するルールも表現できるようにする)
- created_at / updated_at

行が1件も無ければ制限なし(全端末・全事業所で利用可能)として扱う。

### application_integrations (個人/組織のAPI・MCP連携。正データ)

- id
- owner_type (`personal` / `organization`)
- owner_user_id (nullable。`owner_type=personal`の場合のみ設定)
- client_type (`api_client` / `mcp_client` / `ai_application` / `external_application`)
- client_name (利用者が付けるラベル。例:「Claude連携」)
- purpose (利用目的。任意入力)
- personal_access_token_id (この連携が発行したSanctumトークンへの外部キー)
- status (`active` / `revoked`)
- last_used_at (nullable)
- expires_at (nullable)
- registered_by_user_id
- created_at / updated_at

個人連携(`owner_type=personal`)では、原則として連携を登録した本人の情報だけを操作可能とする
(docs/25-usecases-integrations-mcp.md「個人連携の権限」参照)。管理職であっても個人トークンへ
自動的に部下の閲覧権限を付与しない。

### integration_scopes (連携に付与するスコープ)

- id
- integration_id
- scope (例: `attendance:self:read` / `attendance:self:clock` / `attendance:self:draft` /
  `attendance:self:update` / `attendance:self:validate` / `attendance:self:submit` /
  `leave:self:read` / `leave:self:create` / `schedule:self:read` / `report:self:import`)
- created_at / updated_at

## attendance_daily_calculations (Projection: 日次集計)

- id
- attendance_day_id
- planned_work_minutes
- work_minutes (実労働時間。健康管理用。常に実際の時刻から計算する)
- deemed_work_minutes (裁量労働制のみなし時間。対象外の日はnull。docs/07-usecases-attendance.md
  「裁量労働制・管理監督者」参照)
- payroll_work_minutes (給与計算上使用する労働時間。通常はwork_minutesと同じだが、
  裁量労働制はdeemed_work_minutesを採用する)
- prescribed_work_minutes
- statutory_within_overtime_minutes
- statutory_excess_overtime_minutes
- late_night_work_minutes
- late_night_prescribed_work_minutes (深夜のうち所定労働にあたる分。late_night_work_minutesの内訳)
- late_night_statutory_within_overtime_minutes (深夜のうち法定内残業にあたる分。late_night_work_minutesの
  内訳)
- late_night_statutory_excess_overtime_minutes (statutory_excess_overtime_minutesのうち深夜時間帯
  (22:00〜05:00)と重なる分。late_night_work_minutesの内訳であり別枠の加算ではない。
  late_night_prescribed_work_minutes + late_night_statutory_within_overtime_minutes +
  late_night_statutory_excess_overtime_minutes = late_night_work_minutes)
- legal_holiday_work_minutes
- prescribed_holiday_work_minutes
- late_night_legal_holiday_work_minutes
- is_manually_adjusted (日次登録後、区分ごとの時間を手動で補正したかどうか。実績
  (actual_start_at/actual_end_at/breaks)が再編集され再計算(`attendance.day_calculated`)
  されると、falseに戻る)
- adjusted_by_user_id / adjusted_at (手動補正した社員・日時。`is_manually_adjusted=false`
  の場合はnull)
- core_time_violation (フレックスタイム制(`work_time_system=flex`)でコアタイムを設定した日、
  実際の勤務がコアタイムを全てカバーしていないかどうか。労働時間の不足とは別枠の警告として
  扱う。フレックス以外の勤務形態、および出退勤の実績が無い日は常にfalse。docs/07-usecases
  -attendance.md「フレックスタイム制」参照)
- absence_minutes (欠勤時間(分)。`attendance_leave_segments`(category=absence)の区間の
  合計時間。docs/07-usecases-attendance.md「不就労時間の処理区分」参照)
- special_leave_minutes (その他特別休暇の時間(分)。同(category=special_leave)の合計時間)
- paid_leave_days (全休・半休の有給日数。`attendance_days.work_type`から算出する
  全休=1.0・半休=0.5。時間単位有給はここに含めずpaid_leave_minutesで表す)
- paid_leave_minutes (時間単位有給の消化時間(分)。対象日の`paid_leave_usages`
  (usage_type=hourly)のused_minutes合計。有給消化の正データは引き続き`paid_leave_usages`
  のままで、ここは日次集計を1テーブルで完結させるための非正規化)
- created_at / updated_at

`attendance.daily_calculation_adjusted`イベント(手動補正)で更新される列
(`prescribed_work_minutes`/`statutory_within_overtime_minutes`/`statutory_excess_overtime_minutes`/
`legal_holiday_work_minutes`/深夜内訳)も、
イベントの再生成で同じ値を再構築できる(=Projectionの原則を破らない)。実績が再編集され
`attendance.day_calculated`が再発生すると、以降のイベント再生ではその再計算結果で上書き
されるため、手動補正はあくまで「直近の実績編集以降に加えられた最新の上書き」として扱われる。

週40時間(労基法32条)判定は独立したProjectionを持たない。週次勤怠は日次勤怠の編集ビューであり
月のような集計単位ではないため、`App\Domain\Attendance\Services\WeeklyOvertimeCalculator` が
月次確認画面の表示のたびに `attendance_daily_calculations` から都度計算する参考情報として扱う
(docs/07-usecases-attendance.md「週40時間判定」、UC-C005の法定休日要件チェックと同じ考え方)。
同様に、フレックスタイム制の清算期間ダッシュボード(必要労働時間・残り労働時間等)も
`App\Domain\Attendance\Services\FlexSettlementSummaryCalculator` が表示のたびに都度計算する
参考情報であり、独立したProjectionを持たない。

月60時間超残業(労基法37条)も同様に独立したProjectionを持たない。`statutory_excess_overtime_minutes`
を月初から都度合算する参考情報として `App\Domain\Attendance\Services\MonthlyOvertimeCalculator`
が日次勤怠取得のたびに計算し、`AttendanceDayResource.monthly_overtime` として返す
(docs/07-usecases-attendance.md「月60時間超残業判定」参照)。

月次確認画面(UC-A007)には、同じ`MonthlyOvertimeCalculator::calculateCategoryTotals()`が
対象月全体の区分(所定労働時間・法定内残業時間・法定外残業時間・60時間以内/超・
深夜所定労働時間・深夜法定内残業時間・深夜法定外残業時間・法定休日労働時間・
深夜法定休日労働時間)に加え、欠勤日数(absence_days)・欠勤時間(absence_minutes)・
有給日数(paid_leave_days)・有給時間(paid_leave_minutes)・その他特別休暇時間
(special_leave_days)・その他特別休暇時間(special_leave_minutes)を合算した結果を
`monthly_calculation_totals`として都度計算して返す(`AttendanceController::month()`。
提出前でも進捗の目安として表示する)。欠勤日数は、欠勤時間がその日の所定労働時間以上に
なった日を「終日欠勤」として数える(1時間の欠勤を1日欠勤として扱わないため)。
特別休暇日数も、特別休暇時間がその日の所定労働時間以上になった日を数える。
週40時間判定とは異なり、この60時間以内/超の按分は`statutory_excess_overtime_minutes`の単純な
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

## 作業報告書からの月次勤怠作成(docs/26-usecases-monthly-import.md)

作業報告書等から月次勤怠を作成する機能の下書き・インポートセッションのデータ
(`monthly_attendance_drafts`/`attendance_import_sessions`/`attendance_import_items`/
`field_provenances`相当)は、backend/の本テーブル群には含めない。この機能はMCPサーバー
(`mcp/`)経由でのみ使う想定であり、backend/には「AI下書き段階のためだけの正データ」を
持たせず、MCPの一時的な業務(下書き作成・差異照合)をbackendの本来の勤怠データに持ち込まない
(CLAUDE.mdの設計原則9)。該当データは`mcp/`自身の独立したDBで保持する。詳細は
`mcp/README.md`とdocs/26を参照。

backend/側は、既存の日次編集(UC-A005)・月次提出(UC-A008)のAPIと、`AttendanceCalculator`を
再利用したステートレスな検証エンドポイント(何も保存せず、入力に対する計算結果のみを返す)を
提供するだけで、この機能専用のテーブル・エンドポイントを追加しない。

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

## agreement_36_rules (36協定ルールマスタ)

- id
- name
- monthly_limit_minutes (原則: 月45時間=2700分)
- monthly_limit_with_special_clause_minutes (特別条項発動時の月間上限。例: 100時間=6000分)
- yearly_limit_minutes (年間上限。例: 720時間=43200分)
- special_clause_max_times_per_year (特別条項を適用できる回数の上限。例: 6回)
- effective_from / effective_to
- created_at / updated_at

法定外残業の月次累計に対する警告閾値の判定に使う (docs/13-usecases-notification.md UC-N001)。
値は法令・労使協定の内容に応じて変わるため、`MonthlyOvertimeCalculator`の月60時間
(労基法37条の割増率区分、docs/07-usecases-attendance.md)とは別のマスタとして扱う。
最終的な数値設定は社労士確認を前提とする (docs/08-usecases-calendar-shift.md、
docs/20-implementation-notes.md と同様の注記)。

## notifications (通知一覧のProjection)

- id
- recipient_user_id
- notification_type (`approval_request` / `return` / `approval_completed` /
  `backoffice_task_assigned` / `paid_leave_expiry_warning` / `five_day_obligation_warning` /
  `month_end_unsubmitted` / `month_close_deadline_warning` / `punch_inconsistency` /
  `overtime_36agreement` / `pending_approval_reminder`)
- subject_type / subject_id (対象への参照。例: `attendance_day` / `workflow_request`)
- title
- summary
- detail_url
- queued_at
- sent_at (nullable)
- confirmed_at (nullable。本人が通知一覧またはメールのリンクから確認した日時)
- resolved_at (nullable。対象の不備・申請そのものが解消された日時。confirmed_atとは独立に更新される)
- created_at / updated_at

`stored_events`の`notification.queued` / `notification.sent` / `notification.confirmed`
イベントから再生成できるProjection (docs/13-usecases-notification.md)。`resolved_at`は
対象ドメイン側の状態変化(日次編集の完了、申請の承認等)をトリガーに更新する。

## テーブル分類の考え方

| 分類 | テーブル | 特徴 |
|---|---|---|
| EventStore (正) | `stored_events` | 全ドメインイベントの唯一の正。削除・改変しない。 |
| マスタ | `request_types`, `work_calendars`, `work_calendar_days`, `employment_categories`, `work_styles`, `shift_patterns`, `rotation_patterns`, `rotation_pattern_items`, `paid_leave_grant_rules`, `paid_leave_grant_rule_steps`, `system_settings`, `devices`(`owner_type=organization_shared`), `device_roles`, `device_scopes`, `agreement_36_rules` | 管理者が設定する参照データ。 |
| 正データ (書き込み対象) | `users`, `workflow_requests`, `backoffice_tasks`, `employee_shift_assignments`, `employee_rotation_assignments`, `attendance_days`, `attendance_breaks`, `attendance_leave_segments`, `attendance_months`, `legal_holiday_designations`, `paid_leave_grants`, `paid_leave_requests`, `paid_leave_usages`, `attachments`, `devices`(`owner_type=personal`), `authentication_keys`, `authentication_key_device_rules`, `application_integrations`, `integration_scopes`, `monthly_attendance_drafts`, `attendance_import_sessions`, `attendance_import_items`, `field_provenances` | Command経由でのみ更新。`attendance_months`はProjectorを持たず、CommandHandlerが直接書き込む。 |
| 参考ログ (正ではない) | `attendance_punches` | 矛盾があっても記録される生ログ。矛盾なく組み立てられた場合のみ正データ (`attendance_days`) に反映される。 |
| Projection (再生成可能) | `attendance_daily_calculations`, `notifications` | `stored_events` + 正データから再計算できる派生データ。`projections:rebuild`で再生成できる。 |
