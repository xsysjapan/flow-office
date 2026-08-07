# 20. 実装時の注意

- 月次勤怠に直接勤務時間を入力しない
- 週次勤怠は日次勤怠の編集ビューにする
- 月次提出時は集計スナップショットを保存する
- 締め後の日次勤怠はロックする
- 月次が承認済み以降(承認済み・締め済み)の日次勤怠は、締め(ロック)の有無によらず
  通常の編集・削除をできなくする(修正申請ワークフローを使う)
- 承認者は申請時に任意社員から選択可能にする
- バックオフィス処理は承認とは別ステータスにする
- Teamsは通知だけにする
- 掲示板・チャットは作らない
- EventStoreを正とし、Projectionは再生成可能にする
- 法務判断が必要な設定値はマスタ化し、ハードコードしない
- 法令・就業規則・36協定・変形労働時間制の運用は会社ごとに異なるため、最終設定は
  社労士確認を前提にする

これらは全ドメインで共通の設計制約。新機能を追加する際は必ず見直すこと。
コードレビュー・PRレビューでもこのチェックリストを使う。

## 会社カレンダー・従業員予定拡張時の既存設計との整理(UC-C009〜UC-C013)

会社カレンダー本体/年度の分離・祝日iCalendar同期・従業員予定の一括操作を追加する際は、
新しいテナント概念や新規テーブルを安易に増やさず、既存資産を拡張する方針を優先する。

- 単一テナント設計(`company_id`列は導入しない。複数拠点は`company_calendars`の複数レコードで
  表現する)。
- カレンダー本体と年度の分離は、新規テーブルを作らず既存`company_calendars`を本体として残し、
  年度依存フィールドを`company_calendar_years`へ移す2階層に再設計する
  (docs/16-database-schema.md参照)。
- 祝日属性(外部由来の事実)と会社の勤務区分判断は別の列(`is_public_holiday`/
  `schedule_state`)として持ち、同じ列に混在させない。
- 従業員予定の個別上書きと一括操作は同じ`employee_calendar_entries`テーブルを使い、
  `entry_type`/`source_type`/`bulk_operation_id`で由来を区別する(別テーブルに二重化しない)。
- 履歴管理は既存`company_calendars`関連の実装(レガシー自前イベントソーシング、
  `legacy_stored_events`)に揃え、新規spatie方式(`stored_events`)とは混在させない。

## Phase 2実装時のbackendリネーム対象(`work_calendars`/`employee_shift_assignments`系)

本ドキュメント群は、既存backend実装が使っている旧テーブル名・旧クラス名
(`work_calendars`系・`employee_shift_assignments`系)を、新仕様の名称
(`company_calendars`系・`employee_calendar_entries`系)に統一して記述している。ただし
本番環境ではカレンダー機能を未使用のため、今回のdocs更新に合わせてbackendの実装(migrations・
Aggregate・Event・Projector等)を今すぐ変更することはしない。以下は、Phase 2実装時
(本機能に着手するタイミング)に通常のマイグレーション・クラスリネームでテーブル名・クラス名を
新名称へ揃えるべき既存ファイルの一覧(本番未使用のため安全に移管できる)。

- `backend/database/migrations/2026_07_09_151953_create_work_calendars_table.php`
- `backend/database/migrations/2026_07_09_151954_a1_create_work_calendar_days_table.php`
- `backend/database/migrations/2026_07_09_151954_a3_create_employee_shift_assignments_table.php`
- `backend/database/migrations/2026_07_12_130001_add_shift_pattern_id_and_is_published_to_employee_shift_assignments_table.php`
- `backend/database/migrations/2026_07_13_120003_add_is_manually_overridden_to_employee_shift_assignments_table.php`
- `backend/database/migrations/2026_07_14_160102_add_planned_break_times_to_employee_shift_assignments_table.php`
- `backend/app/Domain/Attendance/Aggregates/WorkCalendarAggregate.php`
  (→`CompanyCalendarAggregate`)
- `backend/app/Domain/Attendance/Aggregates/EmployeeShiftAssignmentAggregate.php`
  (→`EmployeeCalendarEntryAggregate`)
- `backend/app/Domain/Attendance/Events/WorkCalendarCreated.php`
- `backend/app/Domain/Attendance/Events/WorkCalendarPublished.php`
- `backend/app/Domain/Attendance/Events/WorkCalendarDaysUpdated.php`
- `backend/app/Domain/Attendance/Events/EmployeeShiftAssigned.php`
- `backend/app/Domain/Attendance/Projectors/WorkCalendarProjector.php`
- `backend/app/Domain/Attendance/Projectors/EmployeeShiftAssignmentProjector.php`
- `backend/app/Domain/Attendance/Services/AttendanceCalculator.php` ほか、`work_calendars`/
  `employee_shift_assignments`系を参照している既存Controller・Service・Requestなど、リネーム
  着手時に`grep -rn "work_calendar\|employee_shift_assignment" backend/`で洗い出した参照元一式
  (Migration名・クラス名・テーブル名・カラム名(`work_styles.calendar_id` →
  `work_styles.company_calendar_id`含む)・既存テストを合わせて変更する)

このリネームと合わせて、本ドキュメントで追加した新要件(本体への`fiscal_year_start_month`/
`fiscal_year_start_day`追加、カレンダー年度定期バッチ生成〔UC-C014〕、`conflict_policy`の
3択整理〔`skip_existing`/`overwrite`/`fail_on_conflict`〕)もPhase 2実装時に反映する。
