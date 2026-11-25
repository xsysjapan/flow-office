# correction-pending-maintenance(補正待ちの間のメンテナンス表示と、命名統一の管理画面からの実行)

ステータス: 実装中(backend・docs実装済み。frontendは別担当)

## 変更要望(原文)
> 今後は不整合データの移行スクリプトを必ず管理画面実行できるように開発ワークフローを整備してください。
> イベント・ReadModel両方の名称が変わっていると想定していますが、それはログインから運用管理画面に遷移し、コマンドを実行するまでに影響がでるものですか？

命名統一(変更セット`20261123-boolean-column-naming`、docs/27 §3.8)は、今の手順では `php artisan down` と SSH で書き換えを行う。規則(data-correction「実行の経路」)に合わせ、管理画面から実行できるようにする。

## 調査結果
- 配備のとき、表示用の表(ReadModel)の列名は migration で改名され、データは保たれる。新しいコードは新しい列名を読むので、画面の表示と権限の判定は動く。
- 旧い項目名のまま残るのは、`stored_events` のイベントだけ。新しいコードは、改名した対象の集約の履歴を読むときだけ失敗する。改名した対象は、端末・ロール割当・働き方・会社カレンダー・グループ種別・外部連携・採番ルール・勤怠の日次の計算と打刻。
- ログイン → 管理画面 → 運用コマンドの依頼と実行は、これらの集約を読まない。ログインは利用者の集約、管理画面は表示用の表、運用コマンドの依頼は通常の表を使う。そのため、補正の前でも動く(コードの読解。下のテストで確かめる)。
- 失敗しうるのは、配備から書き換えまでの間の、上の対象を読み書きする操作。端末の打刻、ロールの変更、働き方の編集、毎分の cron の一部(端末の死活の確認など)が当たる。
- 既存の仕組み: `BlockDuringReadModelRebuild` は、作り直しのコマンドの実行中だけ、決まった GET 以外の API を 503(メンテナンス)にする。

## 仕様検討

### 論点1: 補正待ちの間のメンテナンス
- 決定: 旧形式のイベントの検出器(`LegacyBooleanPropertyDetector`・`LegacyUserSettingsDetector`・`LegacyCalendarBulkOperationTargetIdDetector`)のどれかが陽性の間は、補正待ちのメンテナンスとし、API を 503 にする。
  - 通すもの:
    - ログインと認証(`auth/*` の POST を含む)
    - `auth/me`・`access/me`・メンテナンスの状態
    - 運用コマンドの一覧・実行記録の GET と、実行の依頼の POST(権限 `admin_command.execute`)
  - 画面は今のメンテナンスの表示を使う。文は「データの補正の待ち」が分かるものにする。
  - cron: 運用コマンドの実行(`admin-commands:run-pending`)だけを動かし、他の定期処理は補正待ちの間は飛ばす。
  - 端末の API: 503 を返す。端末アプリはオフラインの打刻としてためる(端末アプリの既存の動き。docs/23 で確かめる)。
- 検出器の判定は軽くする。毎リクエストで `stored_events` を数えないよう、件数を短い時間だけキャッシュし、補正のコマンドが終わったら消す。
- 理由:
  - 管理者はログインして、管理画面から補正を実行できる。
  - 補正の前の失敗しうる操作は、まとめて止める。`php artisan down` が要らなくなる。
- 未確定・要確認事項: 端末アプリが 503 でオフラインの打刻に切り替わるか(docs/23 と端末アプリの仕様で確かめる。切り替わらない場合は、配備の前に端末の打刻を止める案内を手順に書く)

### 論点2: 命名統一の手順を管理画面にする
- 決定: docs/27 §3.8 を次の手順にする。
  1. 配備する(migration で列名を改名する)。補正待ちのメンテナンスに自動で入る。
  2. 管理者がログインし、管理画面で `events:rename-boolean-properties` を試し実行する。
  3. 結果を確かめ、本番の実行の許可を得る。
  4. 管理画面で実行する。
  5. 作り直しの試し実行で、差分が0になることを確かめる。
  6. 検出器が0になると、メンテナンスが自動で解ける。
- `php artisan down` と SSH は使わない。
- 前提: 中間版のリリース(§3.7、`events:fix-rebuild-determinism`)が済んでいる。
- 未確定・要確認事項: なし

### 論点3: テスト(単体)
- backend の Feature テストで次を確かめる。旧い項目名の状態は、`stored_events` に旧い項目名の payload を直接入れて作る。
  - 補正の前に、ログイン・`auth/me`・運用コマンドの依頼と実行(`events:rename-boolean-properties` の試し実行・実行)ができる。
  - 補正の前に、改名した対象の API(例: 端末の打刻・ロールの変更)は 503 になる。
  - 補正の後は、メンテナンスが解けて通常どおり動く。
  - 3つの検出器のどれでも同じ動きになる。
- E2E は足さない。

## 対象外
- 旧い項目名を読み込むときに新しい名前に読み替える仕組み(補正待ちの間は止めるため不要)

## ドキュメントへの影響
- docs/27(§3.7・§3.8)、docs/23(端末の 503 の扱い)
- `.claude/skills/data-correction` の「実行の経路」に、補正待ちのメンテナンスを1行

## 実装結果

### 未確定事項の決定: 端末アプリは 503 でオフラインの打刻としてためるか
- 確認できなかった。端末アプリ(共有Android打刻リーダー)はリポジトリ外にあり、仕様書・backendのコードに「503を受けたときの動き」の定めが無い。
  - docs/23-usecases-devices.md:17 `allows_offline`(オフライン利用可否の設定)、:277 `is_offline`(打刻の入力)、:278 `allows_offline`(ペアリング・心拍の応答)は、設定項目と項目名の定義だけ。
  - docs/07-usecases-attendance.md:354-357 は、`is_offline` の記録と `idempotency_key` による「端末のオフラインキューからの再送の吸収」を述べるが、ネットワーク断が前提で、503は扱わない。
  - docs/16-database-schema.md:655-656・:707 は「docs/23『オフライン対応』参照」と書くが、docs/23にその節は無い(参照切れ)。
  - backend: `DevicePunchController`(`routes/api.php:597`)は`is_offline`と`idempotency_key`を受けるだけで、端末の再送の挙動は決めない。
- 決定: 「503でためて再送する」とは仮定しない。窓の間の打刻は失われるものとして扱い、配備の前に利用者へ案内する
  (窓の後に勤怠の編集で入力)。手順は docs/27 §3.8 手順2、端末の扱いは docs/23「補正待ちの間の端末」に書いた。
  端末アプリの実装でためて再送することを確認できたら、docs/23の節を更新する。

### フロントとの関係(決定)
- 補正待ちの 503 の `code` は `maintenance` ではなく `correction_pending` にした。理由: フロントは `code === 'maintenance'` で全画面のメンテナンス表示
  (`MaintenanceGate`/`MaintenanceNotice`)を出し、`GET /maintenance-status` が `maintenance:false` になると画面を再読込する。補正待ちは `maintenance-status` が false のため
  再読込を繰り返し、管理者が運用コマンドの画面を操作できなくなる。`maintenance-status` の応答は変えていない。
- フロント側の対応(別担当): `ApiError` の `code === 'correction_pending'` を、全画面ではなく、管理者が運用コマンド画面へ進める案内(メッセージはAPIの`message`)として出す。

### backend の実装
- `CorrectionPendingService`(`app/Services/EventSourcing/`): 3つの検出器のどれかが陽性か。結果を60秒キャッシュ(`correction-pending`)。`forget()`でキャッシュを消す。
  補正のコマンドが終わるとき(成功・失敗とも)に消す: `AdminCommandRunner::execute`の`finally`(管理画面の経路)、`ReadModelRebuildRunService::markSucceeded/markFailed`(CLIの直接実行)。
- `BlockDuringReadModelRebuild`を拡張(新しいミドルウェアは足さない)。補正待ちの間、次以外は503(`code=correction_pending`、本文は`CorrectionPendingService::MESSAGE`)。
  - 作り直しの実行中に通すGET: `maintenance-status`・`auth/me`・`access/me`・`onboarding/status`・`admin/commands`・`admin/command-runs`
  - `auth/*`(メソッド問わず。`auth/local-login`・`auth/token`・`auth/logout`・`auth/microsoft/*`)
  - POST `admin/commands/{command}/runs`(運用コマンドの実行の依頼。権限`admin_command.execute`は従来どおり)
  - 作り直しなど補正のコマンドの実行中(`isRunning()`)は従来どおり(GETの一部だけ通す)。
- cron(`routes/console.php`): `admin-commands:run-pending`以外の全スケジュールに、補正待ちの間は飛ばす条件を足した。
- テスト: `tests/Feature/EventSourcing/CorrectionPendingMaintenanceTest.php`。
