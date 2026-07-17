---
name: add-projection
description: Use when adding a new read-model / Projection Table for the UI in flow-office (e.g. a new list/summary screen backed by stored_events). Guides creating the Projector, migration, and a rebuild path so the projection stays regeneratable from the EventStore, per docs/03-architecture.md.
---

# 新しいProjection Tableを追加する

flow-office は Projection (画面表示用テーブル) を「イベントから再生成可能な派生データ」
として扱う (`docs/03-architecture.md` 3.2節)。新しい一覧画面・集計画面を作る際は、
正データやEventStoreを直接いじらず、この手順で Projection を追加する。

## 手順

1. **どのイベントから作るか特定する**: `docs/17-events.md` を見て、この画面に必要な
   情報を持つイベント種別を洗い出す。既存の正データ (`docs/16-database-schema.md`) だけで
   足りるか、イベント履歴からの集計が必要かを判断する。

2. **Projection Table のマイグレーションを作る**: 非正規化してよい (JOIN不要な形に
   フラット化する)。画面表示に不要な正規化はしない。

3. **Projector を実装する**: 対象イベントを受け取り、Projection Table に
   upsert/update する。Projector は冪等に書く (同じイベントを2回適用しても
   結果が変わらないようにする) — 再生成時に最初から全イベントを流し直せるようにするため。

4. **再生成経路を用意する**: Projection Table を空にして `stored_events` を
   `occurred_at` 順に全件流し直せば同じ状態になることを確認する
   (`attendance_daily_calculations` や `attendance_months.snapshot_json` のような
   集計系Projectionは特に重要、`docs/07-usecases-attendance.md` 参照)。

5. **Controller / UI からはProjectionのみを参照する**: 正データやEventStoreを
   直接読みに行くコードを書かない。

## 集約ルート自体をProjector化する場合: 主キーをUUID化する

対象が`attendance_daily_calculations`のような「既存の正データに紐づく集計テーブル」ではなく、
`workflow_requests` / `backoffice_tasks`のように「テーブル自体が集約ルートで、行の新規作成
イベントも含めて全ライフサイクルを再生成したい」場合は、主キーをDB自動採番にしてはいけない。
CommandHandlerは行を作ってIDを確定させてからでないとイベントに書くaggregate_idを持てず、
作成イベントだけはProjectorの再生パスに乗らない(鶏卵問題)。

この場合は主キーをコマンド側生成のUUID(Eloquentの`HasUuids`、`$incrementing = false`,
`$keyType = 'string'`)にする。UUIDはDB書き込みなしに発番できるため、CommandHandlerは
「UUIDを発番してイベントに積むだけ」で済み、行の新規作成もProjectorに完全に委ねられる。
`App\Domain\Workflow\Projectors\WorkflowRequestProjector` /
`App\Domain\BackOffice\Projectors\BackOfficeTaskProjector` が実装例。

ただし、1回のCommandHandlerが複数集約にまたがる副作用(他の正データの新規作成・別集約への
追加イベント追記など)を持つ場合は、単純な「イベント1件→行upsert」に収まらないため
Projector化しない(`ApprovePaidLeaveRequestHandler`のように、承認1件で
`paid_leave_request`・`paid_leave_grant`・`attendance_day`の3集約にまたがる更新を行うケースが
該当)。この場合は正データとしてCommandHandlerが直接読み書きする側に留める。
詳細は`docs/03-architecture.md`「集約ルート自体をProjector化する場合は主キーをUUIDにする」参照。

## チェックリスト (実装後)

- [ ] Projectorはイベントに対して冪等
- [ ] Projection Tableを空にして全イベント再生しても同じ結果になる
- [ ] Controller/UIはProjectionだけを参照している
- [ ] 集計ロジックの変更は `attendance-calc-review` スキルの対象かどうか確認した
