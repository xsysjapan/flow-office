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
- 履歴管理は既存`company_calendars`関連の実装(`WorkCalendarAggregate`/
  `EmployeeShiftAssignmentAggregate`)に揃える。この実装は**既にspatie方式
  (`stored_events`)へ移行済み**であり、レガシー自前イベントソーシング(`legacy_stored_events`)
  は使っていない。新設する`CompanyCalendarYearAggregate`等もspatie方式で実装する
  (docs/29の「未移行ドメインはレガシーのまま」という一般則の対象外)。

## backendリネーム(完了)

`work_calendars`/`work_calendar_days`/`employee_shift_assignments`系の実装を
`company_calendars`/`company_calendar_days`/`employee_calendar_entries`系へ実コードで
リネーム済み(移行用マイグレーション`2026_08_10_010000_rename_work_calendars_to_company_calendars`)。
本体/年度の2階層分離(`company_calendar_years`新設)、`fiscal_year_start_month`/
`fiscal_year_start_day`追加、`calendar_bulk_operations`の`conflict_policy`3択
(`skip_existing`/`overwrite`/`fail_on_conflict`)、祝日iCalendar同期
(`holiday_calendar_sources`/`holiday_calendar_events`)、UC-C014定期バッチも実装済み。
`php artisan test`は666/666成功。

## 会社カレンダー・従業員予定機能で残っている既知の制約・未実装事項

- **旧カラムの削除(2段階目)**: `company_calendar_days.day_type`/`is_working_day`/
  `is_company_holiday`、`employee_calendar_entries.day_type`/`is_working_day`/
  `is_company_holiday`は、新カラム(`schedule_state`/`is_public_holiday`等)と併存させたまま
  (Projectorが両方に整合する値を書き込む)。既存参照箇所を`schedule_state`ベースに置き換え、
  回帰確認が完了してから別マイグレーションで削除する。
- **祝日同期の取消(UC-C012手順4後半)**: 同期実行1回分の内容は`holiday_calendar_source.synced`
  イベントのpayload(`event_changes`/`day_changes`/`protected_conflicts`)に記録されるが、
  「その実行が変更した日だけを同期前の状態へ取り消す」操作自体は未実装。
- **祝日同期の差分確認画面(UC-C023、`GET /holiday-calendar-sources/{id}/sync-preview`相当)**:
  同期結果・競合は`stored_events`に記録されているが、専用の読み取りAPI・画面はまだ無い
  (Phase 5でUIと合わせて実装する)。
- **ICSのRRULE非対応**: 繰り返しルールを持つVEVENTは展開せず無視し、ログに警告を出すのみ。
  日本の祝日iCalendarフィードは通常年ごとに単発VEVENTのため実用上の支障は無いが、RRULEを
  使う祝日ソースには対応できない。
- **一括操作の取消で「元々行が無かった対象」を復元する際の簡略化**: 取消前に
  `employee_calendar_entries`の行が存在しなかった対象(新規作成された行)を取消すと、行自体を
  削除せず`schedule_state=UNASSIGNED`に戻す(`EmployeeCalendarEntryAggregate`に削除の
  プリミティブが無いため)。
- **暫定計算(`provisional: true`)フォールバックの簡略化**: 対象社員個別の勤務形態に紐づく
  会社カレンダーではなく、`company_calendars.is_default`の1件だけを参照して標準曜日ルールを
  適用する。従業員ごとの`work_styles.company_calendar_id`解決までは行っていない。
- **一括操作のGROUPスコープ制限は実装しない**(`docs/05-user-roles.md`参照。既存の
  `attendance.manage`がGLOBALスコープのみのため)。
