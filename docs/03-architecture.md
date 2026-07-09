# 3. アーキテクチャ方針

## 3.1 書き込みと読み取りを分離する

書き込みは Command → CommandHandler → EventStore の流れにする。

読み取りは Projection Table を参照する。

```
Command
  ↓
CommandHandler
  ↓
EventStore
  ↓
Projector
  ↓
Projection Table
  ↓
Controller / UI
```

## 3.2 EventStore を正とする

業務イベントは `stored_events` に保存する。

Projection は画面表示用の派生データであり、再生成可能なものとして扱う。

## 3.3 勤怠の正は日次実績・勤務予定・有給付与

勤怠では以下を正とする。

- 勤務予定: `employee_shift_assignments`
- 勤務実績: `attendance_days` / `attendance_breaks`
- 有給付与: `paid_leave_grants`
- イベント履歴: `stored_events`

月次勤怠はこれらの集計結果であり、直接の入力元にはしない。

## 実装上のポイント

- CommandHandler はドメインルールを検証した上で、必ず1つ以上のイベントを `stored_events` に
  追記する。イベントを書かない状態変更は存在しない。
- Projector はイベントを購読し、Projection Table (読み取り専用の非正規化テーブル) を更新する。
  Projection は障害時・スキーマ変更時にイベントから再生成できる設計にする
  (再生成コマンド `projections:rebuild` のようなものを Phase 1 で用意する)。
- Controller / UI は Projection Table のみを参照し、書き込みは Command 経由のみとする。
- `attendance_daily_calculations` や `attendance_months.snapshot_json` のような集計結果も
  Projection の一種であり、日次実績・勤務予定・有給付与から再計算可能であることを保証する。
