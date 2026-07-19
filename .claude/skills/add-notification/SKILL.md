---
name: add-notification
description: Use when adding a new email notification type to flow-office (e.g. a new approval-request, reminder, or warning notification). Guides queuing the job via the DB queue and cron-driven worker instead of assuming a long-running process, and keeping notifications one-directional (no reply channel), per docs/13-usecases-notification.md.
---

# 新しいメール通知を追加する

flow-officeの通知はメール(Microsoft Graph API `sendMail`)への一方向通知のみ
(`docs/13-usecases-notification.md`)。XSERVER上では常駐プロセスを前提にできないため、
DB queue + cron 前提で実装する (`docs/02-tech-stack.md`)。

## 手順

1. **通知トリガーとなるイベントを特定する**: `docs/17-events.md` のどのイベントで
   通知が必要かを決める (例: `workflow_request.submitted` → 承認依頼通知)。
   既存の通知対象一覧 (`docs/13-usecases-notification.md`) と重複しないか確認する。

2. **宛先(recipient)を決める**: メールは個人宛てに送るため、`App\Models\User`を1件
   特定する必要がある(申請者・承認者・対象社員など)。複数人に送る場合(部門宛て等)は
   `App\Domain\Notification\NotificationRecipients::byRoles()`でロールから解決する。
   宛先が見つからない場合は通知をスキップする(存在しないユーザーへは送らない)。

3. **通知ジョブをDBキューに登録する** (`notification.queued` イベント/ジョブ行を作成):
   CommandHandlerのイベント追記と同一トランザクションで、`App\Jobs\SendNotificationJob::enqueue()`
   を呼ぶ。ジョブ本体でGraph APIを直接呼び出さない(トランザクション内で外部HTTP呼び出しをしない)。

4. **通知内容を決める**: 件名・概要・詳細URLを渡す(`docs/13-usecases-notification.md`
   UC-N001 手順4)。詳細URLはログイン後に対象画面へ直接遷移できるものにする。本文はHTMLで
   整形される(`resources/views/emails/notification.blade.php`)。

5. **cron起動のworkerで送信する**: `queue:work --stop-when-empty` で処理し、
   常駐を前提にしない。送信成功時は `notification.sent` を記録し、失敗時は
   ログに残す(自動リトライループを作らない、次回バッチ実行時にカバーする方針)。
   `system_settings`でメール通知が未設定・無効の場合は`App\Domain\Notification\GraphMailNotifier`が
   ログ出力のみに留め、送信自体は行わない。

6. **一方向通知であることを守る**: この通知経路をチャット返信や掲示板的な用途に
   拡張しない。あくまで「詳細URL付きの一方向通知」に留める。

## チェックリスト (実装後)

- [ ] 通知ジョブはDBキュー経由(常駐プロセス前提のコードがない)
- [ ] 宛先が見つからない場合はスキップしている(nullチェック)
- [ ] 通知本文に件名・概要・詳細URLを含めている
- [ ] 送信成功/失敗をログに残している
- [ ] チャット/掲示板として使う機能を追加していない
