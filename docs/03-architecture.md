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

## 3.4 日時の扱い: 内部はナイーブ、APIは常にオフセット付き、勤務実績は勤務日ごとに現地時刻を持つ

システムは複数のタイムゾーンで働く社員、および海外出張などで勤務日ごとに現地時刻が変わる
社員を前提とする。日時の扱いは以下のルールに統一する。

- **DB内部はタイムゾーンなし(ナイーブ)の壁時計時刻で保存する**。datetimeカラムはDBカラム
  自体にタイムゾーンを持たせず、常に「その値の文脈における現地時刻」の数字をそのまま保存する。
- **APIで使われる日時型は、リクエスト・レスポンスの両方で必ずオフセット付きISO8601
  (例: `2026-07-10T21:00:00+09:00`)とする**。オフセットなしの日時文字列はAPIの入力として
  拒否する。ただし、どのオフセットを使うかは以下の2系統に分かれる。

### 一般的な日時(last_login_at, submitted_at 等)

- ナイーブな壁時計時刻は、**システムのデフォルトタイムゾーン**(`system_settings.default_timezone`)
  における現地時刻として保存・解釈する。API出力時のオフセットもこのタイムゾーンを使う。
- **タイムゾーンはユーザーごとに保持する**(`users.timezone`)。これは画面表示専用の設定であり、
  APIが返す絶対時刻(ISO8601)を画面でどのタイムゾーンに変換して見せるかを決める
  (画面表示する時間はユーザーのタイムゾーンの時間)。
- **新規ユーザーはシステム設定のデフォルトタイムゾーンで作成する**(`system_settings.default_timezone`)。
  SSO初回ログイン・MS365同期のどちらで作成される場合も同じ既定値を使う。既存ユーザーの
  タイムゾーンはMS365同期・SSO再ログインで上書きしない([UC-002](./06-usecases-auth.md#uc-002-ms365ユーザーを同期する))。
  管理者は [UC-003](./06-usecases-auth.md#uc-003-システム設定default_timezoneを管理する) で
  デフォルトタイムゾーンを変更できる。

### 勤怠の勤務実績(attendance_days / attendance_breaks / attendance_punches)

- 勤務実績の日時(`actual_start_at` / `actual_end_at` / 休憩 / 打刻)は、社員本人の既定
  タイムゾーン(`users.timezone`)ではなく、**その勤務日自身が保持するUTCオフセット**
  (`attendance_days.utc_offset_minutes` / `attendance_punches.utc_offset_minutes`、分単位の整数)
  で解釈する。海外出張などで深夜残業の判定に使う「現地時刻」が勤務日ごとに変わるため、
  固定のタイムゾーン名では表現できない。
- 画面から入力・打刻デバイスから送信されるオフセット付きISO8601は、タイムゾーン変換をせず
  「入力された通りの現地時刻」としてそのまま保存し、そのオフセットを勤務日/打刻に記録する。
  1つの勤務日に複数の異なるオフセットが混在することは許さない(1回の編集で送る全ての時刻は
  同じオフセットである必要がある。打刻は複数件が集まって初めて1日分の勤務として組み立てる
  ため、その全件のオフセットが一致しない場合は矛盾ありとして日次勤怠に反映しない)。
- 画面表示も、社員本人の既定タイムゾーンには変換せず、その勤務日に記録されたオフセットの
  ままの時刻を表示する(※勤務時間は当該作業日のオフセット時間で表示)。

いずれの系統も、変換ロジックは `App\Support\LocalDateTime` に集約し、個々のCommandHandler・
Controller・Resourceが直接タイムゾーン変換を書かないようにする。

「今日」の判定(出勤時にどの `work_date` に属するか等)は、サーバーやUTCの日付ではなく、
その社員の `users.timezone` における現在の日付を基準に行う(勤務実績のオフセットとは別の
話であることに注意。「今日」の判定は今まさに出勤しようとしている社員本人の基準時刻であり、
既に確定した勤務実績のオフセットは事後的に勤務日ごとへ個別に記録される)。

## 実装上のポイント

- CommandHandler はドメインルールを検証した上で、必ず1つ以上のイベントを `stored_events` に
  追記する。イベントを書かない状態変更は存在しない。
- Projector はイベントを購読し、Projection Table (読み取り専用の非正規化テーブル) を更新する。
  Projection は障害時・スキーマ変更時にイベントから再生成できる設計にする
  (再生成コマンド `projections:rebuild` のようなものを Phase 1 で用意する)。
- Controller / UI は Projection Table のみを参照し、書き込みは Command 経由のみとする。
- `attendance_daily_calculations` や `attendance_months.snapshot_json` のような集計結果も
  Projection の一種であり、日次実績・勤務予定・有給付与から再計算可能であることを保証する。

### 集約ルート自体をProjector化する場合は主キーをUUIDにする

`workflow_requests` / `backoffice_tasks` のように、テーブル自体が集約ルート(行の作成イベントも
含めて全ライフサイクルをイベントから再生成したい対象)である場合、主キーをDB自動採番の
連番にしてはいけない。CommandHandlerは「行を作ってIDを確定させてからイベントに書く」しか
できず、作成イベント自体はProjectorの再生パスに乗らない(Projectorはイベントを見てから行を
作る側なので、イベントに書くべきIDがまだ存在しないという鶏卵問題になる)。

そのため、こうした集約ルートは主キーをコマンド側で生成できるUUID(Eloquentの`HasUuids`)に
する。UUIDはDBへの書き込みなしに生成できるため、CommandHandlerは行を直接作らず「UUIDを
発番してイベントに積む」だけで済み、行の新規作成も含めて対応するProjectorに完全に委ねられる。
`WorkflowRequestProjector` / `BackOfficeTaskProjector` がこのパターンの実装例
(`.claude/skills/add-projection`参照)。

一方、`attendance_days` / `paid_leave_requests` / `special_leave_requests` のように、1回の
CommandHandlerが複数集約にまたがる副作用(他の正データの新規作成・別集約への追加イベント
追記など)を持つ場合は、単純な「イベント1件→行upsert」に収まらないためProjector化しない。
これらは引き続き正データとしてCommandHandlerが直接読み書きする
(`App\Domain\PaidLeave\Handlers\ApprovePaidLeaveRequestHandler`が例: 承認1件で
`paid_leave_request`・`paid_leave_grant`・`attendance_day`の3集約にまたがる更新を行う)。
