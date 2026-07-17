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
5. 個別上書きと生成元の区別(`employee_shift_assignments.is_manually_overridden`)、
   再生成時の「未編集日のみ再生成(既定)」「個別上書きも含めてすべて再生成」の選択
6. 実績のある日・締め済みの日は両モードとも自動上書きしない安全ガード

班単位管理(複数社員への一括割当、指示書8.6節)、AIによる自然言語からのローテーション設定
補助(指示書21.2節)は未実装(将来フェーズ)。

各Phaseの詳細ユースケースは対応するドキュメントを参照:
[06](./06-usecases-auth.md) [07](./07-usecases-attendance.md) [08](./08-usecases-calendar-shift.md)
[09](./09-usecases-paid-leave.md) [10](./10-usecases-workflow.md) [11](./11-usecases-backoffice.md)
