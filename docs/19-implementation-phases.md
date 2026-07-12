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
5. シフト表UI(`frontend/src/pages/WorkStylesAndShiftsPage.tsx`)

深夜・休日・残業計算自体は日次実績(`AttendanceCalculator`)側で勤務形態横断的に扱うため、
Phase 4で実装済みのロジックをそのまま利用する(シフトパターン専用の計算ロジックは持たない)。

各Phaseの詳細ユースケースは対応するドキュメントを参照:
[06](./06-usecases-auth.md) [07](./07-usecases-attendance.md) [08](./08-usecases-calendar-shift.md)
[09](./09-usecases-paid-leave.md) [10](./10-usecases-workflow.md) [11](./11-usecases-backoffice.md)
