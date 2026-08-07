# 19. 実装順序

## Phase 1: 基盤

1. Laravelプロジェクト作成
2. MySQL接続
3. 認証の土台
4. ユーザーマスタ
5. EventStore
6. Projection 基盤
7. DB Queue
8. cron 前提のスケジューラ

## Phase 2: 汎用申請

1. 申請種別マスタ
2. 申請作成
3. 任意承認者選択
4. 承認
5. 差戻し
6. 添付ファイル
7. Teams通知

## Phase 3: バックオフィス

1. バックオフィスタスク自動生成
2. 担当者割当
3. ステータス更新
4. コメント
5. CSV出力

## Phase 4: 勤怠

1. 勤務形態
2. カレンダー
3. 社員別勤務予定
4. 打刻
5. 日次編集
6. 月次集計
7. 月次提出
8. 承認
9. 締め処理

### Phase 4追加: 会社カレンダー・従業員予定の拡張(UC-C009〜UC-C014)

1. `company_calendars`(本体)と`company_calendar_years`(年度)の分離、`company_calendar_days`への
   `schedule_state`/`is_public_holiday`の追加(既存`day_type`/`is_working_day`/
   `is_company_holiday`からの移行)。本体に`fiscal_year_start_month`/`fiscal_year_start_day`
   (既定4/1)を追加し、年度生成時にこの設定から`starts_on`/`ends_on`を計算して確定値として
   保存する(本体側の設定変更は既存年度の`starts_on`/`ends_on`に遡って影響しない)
2. `employee_calendar_entries`への`schedule_state`/`entry_type`/`source_type`/
   `bulk_operation_id`/`revision`の追加。旧`day_type`/`is_working_day`/`is_company_holiday`は
   `schedule_state`から導出可能なため廃止対象とする(`company_calendar_days`の旧カラムと同様、
   置き換え・回帰確認後に別マイグレーションで削除する2段階移行)
3. 祝日iCalendar同期(`holiday_calendar_sources`/`holiday_calendar_events`、cron駆動)
4. 複数従業員予定の一括操作(`calendar_bulk_operations`/`calendar_bulk_operation_targets`、
   プレビュー→確定→取消)への既存UC-C003・UC-C008の一括生成ロジックの統合。
   `conflict_policy`は`skip_existing`(既定)/`overwrite`/`fail_on_conflict`の3択、
   `calendar_bulk_operation_targets.result`は`applied`/`skipped_existing`/`failed`の3値とする
5. カレンダー年度の定期バッチ生成(UC-C014)。既存のcron前提のスケジューラ(Phase 1・
   祝日iCalendar同期と同じ仕組み)に日次ジョブを追加し、`company_calendars`ごとに
   年度の存在確認→無ければ現在年度・次年度を`draft`生成→既存年度が今日から6か月以内に
   終了し次年度が無ければ次年度を`draft`生成、をべき等に実行する。オンボーディングの
   標準カレンダー自動生成(UC-C011)は、この定期バッチと同じ生成ロジックをオンデマンドで
   1回実行する形に統合し、生成ロジックを入口ごとに複製しない
6. 対象年月の年度が未生成の読み取り要求に対する暫定計算フォールバック
   (レスポンスに`provisional: true`を含める。`company_calendar_years`/`company_calendar_days`
   には書き込まない。UC-C014手順5参照)
7. 旧カラム(`day_type`/`is_working_day`/`is_company_holiday`)の削除は、置き換え後の回帰
   確認が完了してから別マイグレーションで行う

## Phase 5: 有給

1. 有給付与ルール
2. 有給自動付与
3. 有給申請
4. 有給消化
5. 有効期限警告
6. 年5日取得義務警告

## Phase 6: 3交代制(実装済み)

1. シフトパターン(`shift_patterns`マスタ、日勤/準夜勤/深夜勤/公休/明け休み等)
2. 社員別シフト割当(シフトパターンの日別割当、下書き→公開)
3. 日跨ぎ勤務対応(`planned_start_at`/`planned_end_at`をdatetimeで保持)
4. 公開前チェック(法定休日不足・連続勤務・月間予定時間の警告)
5. シフト表UI(`frontend/src/pages/workCalendar/WorkStylesAndShiftsPage.tsx`)

深夜・休日・残業計算自体は日次実績(`AttendanceCalculator`)側で勤務形態横断的に扱うため、
Phase 4で実装済みのロジックをそのまま利用する(シフトパターン専用の計算ロジックは持たない)。

## Phase 7: フレックスタイム制(実装済み)

1. 勤務形態の清算期間・コアタイム・勤務可能時間帯設定(`work_styles`、UC-C002)
2. コアタイム違反判定(`attendance_daily_calculations.core_time_violation`)
3. 清算期間ダッシュボード(`FlexSettlementSummaryCalculator`、`GET /api/attendance/months/{yearMonth}`)
4. ホーム画面での表示(`frontend/src/pages/attendance/TodayAttendancePage.tsx`)

清算期間の必要労働時間の計算方式の切り替え(指示書7.3節)、週40時間の法定労働時間総枠に
基づく精密な清算期間残業計算、複数月清算は未実装(docs/07-usecases-attendance.md
「フレックスタイム制」参照)。

## Phase 8: 交代制ローテーション自動生成(実装済み)

1. ローテーションパターンマスタ(`rotation_patterns`/`rotation_pattern_items`、
   A勤・B勤・C勤・休の繰り返し周期を1つの働き方の中で管理)
2. 社員ごとのローテーション基準割当(`employee_rotation_assignments`)
3. カレンダープレビュー(`POST /rotation-patterns/{id}/preview`、永続化しない)
4. ローテーションからの月間シフト自動生成(`GenerateRotationShiftAssignments`)
5. 個別上書きと生成元の区別(`employee_calendar_entries.is_manually_overridden`)、
   再生成時の「未編集日のみ再生成(既定)」「個別上書きも含めてすべて再生成」の選択
6. 実績のある日・締め済みの日は両モードとも自動上書きしない安全ガード

班単位管理(複数社員への一括割当、指示書8.6節)、AIによる自然言語からのローテーション設定
補助(指示書21.2節)は未実装(将来フェーズ)。

各Phaseの詳細ユースケースは対応するドキュメントを参照:
[06](./06-usecases-auth.md) [07](./07-usecases-attendance.md) [08](./08-usecases-calendar-shift.md)
[09](./09-usecases-paid-leave.md) [10](./10-usecases-workflow.md) [11](./11-usecases-backoffice.md)
