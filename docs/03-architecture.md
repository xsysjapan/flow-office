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

`attendance_punches` (打刻ログ) は正ではない。ICカード端末やモバイル端末など複数の経路から
届く打刻を、矛盾があっても必ず記録できる参考ログであり、矛盾なく1日分の勤務として組み立て
られた場合にのみ `attendance_days` / `attendance_breaks` に反映される
(docs/07-usecases-attendance.md UC-A012)。日次の記録(`attendance_days`)こそが最終的な
整合性を保証する正データであることに変わりはない。

## 3.4 日時はユーザーごとのタイムゾーンで解釈し、API境界では常にオフセットを明示する

システムは複数のタイムゾーンで働く社員を前提とする。日時の扱いは以下のルールに統一する。

- **DB内部はタイムゾーンなし(ナイーブ)の壁時計時刻で保存する**。`attendance_days.actual_start_at`
  や `attendance_punches.punched_at` のような日時カラムは、その値が属するレコードの所有者
  (通常は `user_id` が指す社員)にとっての現地時刻の数字をそのまま保存する。DBカラム自体には
  タイムゾーンを持たせない。
- **タイムゾーンはユーザーごとに保持する**(`users.timezone`)。ある社員の日時カラムの数字を
  「実際にいつのことか」に変換するには、必ずその社員の `timezone` を使う。
- **新規ユーザーはシステム設定のデフォルトタイムゾーンで作成する**(`system_settings.default_timezone`)。
  SSO初回ログイン・MS365同期のどちらで作成される場合も同じ既定値を使う。既存ユーザーの
  タイムゾーンはMS365同期で上書きしない([UC-002](./06-usecases-auth.md#uc-002-ms365ユーザーを同期する))。
  管理者は [UC-003](./06-usecases-auth.md#uc-003-システム設定default_timezoneを管理する) で
  デフォルトタイムゾーンを変更できる。
- **APIで使われる日時型は、リクエスト・レスポンスの両方で必ずオフセット付きISO8601
  (例: `2026-07-10T21:00:00+09:00`)とする**。オフセットなしの日時文字列はAPIの入力として
  拒否する。
- 上記の変換ロジックは `App\Support\LocalDateTime` に集約し、個々のCommandHandler・
  Controller・Resourceが直接タイムゾーン変換を書かないようにする。

「今日」の判定(出勤時にどの `work_date` に属するか等)も、サーバーやUTCの日付ではなく、
その社員の `timezone` における現在の日付を基準に行う。

## 実装上のポイント

- CommandHandler はドメインルールを検証した上で、必ず1つ以上のイベントを `stored_events` に
  追記する。イベントを書かない状態変更は存在しない。
- Projector はイベントを購読し、Projection Table (読み取り専用の非正規化テーブル) を更新する。
  Projection は障害時・スキーマ変更時にイベントから再生成できる設計にする
  (再生成コマンド `projections:rebuild` のようなものを Phase 1 で用意する)。
- Controller / UI は Projection Table のみを参照し、書き込みは Command 経由のみとする。
- `attendance_daily_calculations` や `attendance_months.snapshot_json` のような集計結果も
  Projection の一種であり、日次実績・勤務予定・有給付与から再計算可能であることを保証する。
