# 13. 通知ユースケース

## UC-N001: メール通知を送る

1. イベント発生時、または日次バッチでの検知時に、通知ジョブをDBキューへ登録する
2. cron が queue worker を起動する
3. 対象ユーザー本人(`users.email`)宛てにメールを送る
4. 通知には通知種別、件名、概要、詳細URLを含める
5. 成功・失敗をログに残す

通知チャネルはメールのみとする。Teams向けの実装は行わない。

### 通知対象(即時)

イベント発生と同時にキュー投入するもの。

- 承認依頼(申請提出時、承認者へ)
- 差戻し(申請者へ)
- 承認完了(申請者へ)
- バックオフィスタスク割当(担当者へ)
- 有給期限警告 (docs/09-usecases-paid-leave.md UC-P006)
- 年5日取得義務警告 (docs/09-usecases-paid-leave.md UC-P006)

### 通知対象(日次バッチで検知)

`schedule:run` から日次で起動するバッチが対象を検知し、キュー投入するもの。バッチは
CommandHandler側で判定する(原則9・11: 判定ロジックはAPI側に集約し、通知は結果を配るだけ)。

| 通知種別 | 検知条件 | 宛先 | 再通知ルール |
|---|---|---|---|
| 打刻不備(矛盾) | `attendance_punches`の内容が`attendance_days`に反映できず、前日時点で1日経っても未編集で残っている | 本人 | 解消(日次編集)されるまで毎日 |
| 36協定超過 | 当月の法定外残業累計が `agreement_36_rules` の月次上限に対し閾値(注意: 80%到達、警告: 100%到達)を超えた | 本人 + 上長(承認者) | 閾値をまたいだ回(注意→警告)のみ。同じ閾値内での再通知はしない |
| 勤怠未提出 | `system_settings.attendance_submission_deadline_day`を過ぎても前月分が未提出の在籍社員 | 本人 | 解消するまで毎日(既存踏襲) |
| 月次締め前警告 | `system_settings.attendance_month_close_deadline_day`の直前(既定3日前)になっても前月分が締められていない | 管理部(締め処理担当) | 解消するまで毎日(既存踏襲) |
| 承認依頼の滞留 | 自分宛ての`workflow_requests`(`status = pending`相当)が一定時間(24時間)・一定日数(3日)経過しても未処理 | 承認者 | 24時間経過時・3日経過時の2段階のみ(閾値をまたいだ回) |

「打刻不備」「36協定超過」「承認依頼の滞留」は今回新規に追加する。

## 個人通知一覧・確認機能

送信した通知はユーザーごとに一覧・確認できるようにする。詳細は下記「実装上のポイント」の
テーブル設計を参照。

- 画面: 自分宛ての通知一覧(未確認/確認済みで絞り込み可能)を表示し、各行に詳細URLへの
  リンクと「確認」ボタンを置く。メール本文にも同じ詳細URLを載せる。
- 「送信済み(sent)」「確認済み(confirmed)」「解消済み(resolved)」は別概念として扱う。
  メールが届いた/一覧を見た、という行為(confirmed)と、対象の不備そのものが直っている
  という状態(resolved、例: 矛盾のある日を編集した、申請が承認された)は独立して管理する。
  確認しても不備が解消されていなければ、日次バッチは引き続き検知・再通知の対象とする。

## 実装上のポイント

- 通知は DB queue (`notification.queued` → `notification.sent` → `notification.confirmed`)
  を経由する。常駐ワーカーを前提にしないため、cronで `queue:work --stop-when-empty` を
  定期実行する。
- 送信失敗はリトライではなくログに残し、再送は手動または次回バッチでカバーする方針とする
  (XSERVERの実行時間制約を考慮)。
- `notification.queued`のpayloadに、宛先ユーザー(`recipientUserId`)・通知種別
  (`notificationType`)・対象(`subjectType`/`subjectId`、例: `attendance_day`/`workflow_request`)
  を持たせ、個人通知一覧のProjectionをここから再生成できるようにする(原則1・2)。
- 36協定の上限値は `agreement_36_rules` マスタで管理し、コードにハードコードしない
  (原則8)。最終的な閾値・特別条項の扱いは社労士確認を前提とする(docs/08・docs/20と同様)。
- メール送信はSMTPではなく**Microsoft Graph API (`sendMail`)** を使う。Exchange OnlineはSMTP
  AUTH(Basic認証)の廃止を進めているため、アプリ専用トークン(クライアントクレデンシャル)で
  HTTPS経由により送信する。本文はHTML。
- 送信に使うテナントID・クライアントID/シークレットは、SSOログイン(UC-001)・MS365ユーザー
  同期(UC-002)と共有する`system_settings.m365_tenant_id`/`m365_client_id`/`m365_client_secret`
  を使う(初回オンボーディングUC-000で登録)。メール通知固有の設定は`notification_mail_enabled`
  (有効/無効)と送信元アドレス/表示名のみで、`.env`ではなく`system_settings`(管理者専用API、
  UC-003)で管理する。`notification_mail_enabled`がfalse、またはm365資格情報・送信元アドレスの
  いずれかが未設定の場合はメール通知自体を送らず、ログ出力のみに留める(通知処理自体は
  失敗させない)。
- **実装状況**: 宛先ユーザーを指定してのメール送信基盤(`GraphMailNotifier`・
  `SendNotificationJob`・システム設定)は実装済み。「打刻不備」「36協定超過」「承認依頼の滞留」の
  日次バッチ検知、および個人通知一覧・確認機能(`notifications` Projection、
  `notification.confirmed`)は今後の実装対象として設計のみ済み。
