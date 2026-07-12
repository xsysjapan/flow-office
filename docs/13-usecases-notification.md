# 13. 通知ユースケース

## UC-N001: Teams通知を送る

1. イベント発生時に通知ジョブをDBキューへ登録する
2. cron が queue worker を起動する
3. Teams に通知する
4. 通知には申請種別、申請者、件名、詳細URLを含める
5. 成功・失敗をログに残す

### 通知対象

- 承認依頼
- 差戻し
- 承認完了
- バックオフィスタスク割当
- 有給期限警告
- 年5日取得義務警告 (docs/09-usecases-paid-leave.md UC-P006)
- 勤怠未提出 (`system_settings.attendance_submission_deadline_day`を過ぎても前月分が
  未提出の在籍社員に、解消するまで毎日通知する)
- 月次締め前警告 (`system_settings.attendance_month_close_deadline_day`の直前
  (既定3日前)になっても前月分が締められていない場合に通知する)

## 実装上のポイント

- 通知は Teams Webhook または Microsoft Graph API を使い、DB queue (`notification.queued` →
  `notification.sent`) を経由する。常駐ワーカーを前提にしないため、cronで
  `queue:work --stop-when-empty` を定期実行する。
- 通知failureはリトライではなくログに残し、再送は手動または次回バッチでカバーする方針とする
  (XSERVERの実行時間制約を考慮)。
- Teamsは通知専用。チャット返信・スレッド機能・お知らせ配信は本システムのスコープ外。
