---
name: add-domain-event
description: Use when adding a new domain event to flow-office's CQRS + Event Sourcing system (e.g. adding a new attendance/workflow/paid-leave state transition). Guides creating the Command, CommandHandler, Event class, and wiring it into stored_events and the relevant Projector, following docs/03-architecture.md.
---

# 新しいドメインイベントを追加する

flow-office は Command → CommandHandler → EventStore (`stored_events`) → Projector →
Projection Table の流れを守る (`docs/03-architecture.md`)。新しい業務イベントを追加する
際は必ずこの順序で実装する。

## 手順

1. **命名を決める**: `<aggregate>.<past_tense_verb>` 形式 (例: `attendance.clocked_in`,
   `paid_leave.granted`)。既存の一覧は `docs/17-events.md` を参照し、重複や表記ゆれが
   ないか確認する。追加したら `docs/17-events.md` にも追記する。

2. **Command を定義する**: 意図を表す入力オブジェクト。集約ID (aggregate_id) と
   バリデーション対象の値のみを持たせる。副作用を持たせない。

3. **CommandHandler を実装する**:
   - 業務ルールを検証する (例: 締め後は編集不可、有給残数が足りない場合は申請不可など)。
     ルールは `docs/07〜docs/09` の該当ユースケースを確認する。
   - 検証を通過したら、`stored_events` に1件以上のイベントを追記する。
     `aggregate_type` / `aggregate_id` / `version` / `event_type` / `payload` /
     `metadata` / `occurred_at` を必ず埋める。
   - **状態変更をイベント追記なしに行わない**。CommandHandlerの外で直接
     正データ(例: `attendance_days`)を更新する処理を書かない。

4. **正データを更新する**: 集約の「正」テーブル (`docs/16-database-schema.md` の
   「正データ」分類を参照) をイベントと同一トランザクションで更新する。

5. **Projector を更新する**: このイベントが画面表示に影響する場合、対応する
   Projector にハンドラを追加し、Projection Table を更新する。Projection は
   いつでも `stored_events` から再生成できることを確認する
   (再生成コマンドがあれば実行して差分がないか確認する)。

6. **通知が必要なら** `add-teams-notification` スキルを使って通知ジョブをキューイングする。

7. **監査ログとの整合性を確認する**: `docs/15-usecases-admin.md` の UC-M003 は
   `stored_events` を参照する前提。新イベントが監査ログ画面で意味の通る形で
   表示されるか確認する。

## チェックリスト (実装後)

- [ ] `docs/17-events.md` に新イベントを追記した
- [ ] CommandHandler以外で正データを直接更新している箇所がない
- [ ] イベントを書かずに状態だけ変える経路がない
- [ ] Projectionはイベントから再生成可能 (直接書き換えていない)
- [ ] `docs/20-implementation-notes.md` のチェックリストに抵触していない
