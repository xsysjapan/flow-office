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

## チェックリスト (実装後)

- [ ] Projectorはイベントに対して冪等
- [ ] Projection Tableを空にして全イベント再生しても同じ結果になる
- [ ] Controller/UIはProjectionだけを参照している
- [ ] 集計ロジックの変更は `attendance-calc-review` スキルの対象かどうか確認した
