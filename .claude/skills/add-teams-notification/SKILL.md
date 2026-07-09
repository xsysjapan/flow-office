---
name: add-teams-notification
description: Use when adding a new Teams notification type to flow-office (e.g. a new approval-request, reminder, or warning notification). Guides queuing the job via the DB queue and cron-driven worker instead of assuming a long-running process, and keeping Teams as notification-only, per docs/13-usecases-notification.md.
---

# 新しいTeams通知を追加する

flow-officeの通知はTeamsへの一方向通知のみ(チャット/掲示板/お知らせは作らない、
`docs/13-usecases-notification.md`)。XSERVER上では常駐プロセスを前提にできないため、
DB queue + cron 前提で実装する (`docs/02-tech-stack.md`)。

## 手順

1. **通知トリガーとなるイベントを特定する**: `docs/17-events.md` のどのイベントで
   通知が必要かを決める (例: `workflow_request.submitted` → 承認依頼通知)。
   既存の通知対象一覧 (`docs/13-usecases-notification.md`) と重複しないか確認する。

2. **通知ジョブをDBキューに登録する** (`notification.queued` イベント/ジョブ行を作成):
   CommandHandlerのイベント追記と同一トランザクションで、通知ジョブを積む。
   ジョブ本体でTeams APIを直接呼び出さない(トランザクション内で外部HTTP呼び出しをしない)。

3. **通知内容を決める**: 申請種別、申請者、件名、詳細URLを含める
   (`docs/13-usecases-notification.md` UC-N001 手順4)。詳細URLはログイン後に
   対象画面へ直接遷移できるものにする。

4. **cron起動のworkerで送信する**: `queue:work --stop-when-empty` で処理し、
   常駐を前提にしない。送信成功時は `notification.sent` を記録し、失敗時は
   ログに残す(自動リトライループを作らない、次回バッチ実行時にカバーする方針)。

5. **Teams専用であることを守る**: この通知経路をチャット返信や掲示板的な用途に
   拡張しない。あくまで「詳細URL付きの一方向通知」に留める。

## チェックリスト (実装後)

- [ ] 通知ジョブはDBキュー経由(常駐プロセス前提のコードがない)
- [ ] 通知本文に申請種別・申請者・件名・詳細URLを含めている
- [ ] 送信成功/失敗をログに残している
- [ ] Teamsをチャット/掲示板として使う機能を追加していない
