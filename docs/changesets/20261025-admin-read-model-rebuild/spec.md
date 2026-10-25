# 管理画面からReadModelを作り直す(全テーブルを空にしてevent-sourcing:replay)

ステータス: 完了

## 変更要望(原文)

```
php artisan event-sourcing:replayを管理画面からできるように変更してください。その際、自動的に各テーブルをtruncateしてください。
```

(仕様の確認への回答)
- 実行中の他の利用者の操作:「自動でメンテナンス中にする」
- テーブルを空にする方法:「1つのトランザクションで削除」
- 試し実行:「dry-runをつけてもいいですが、即時実行できるようにもしておいてください。」

## 背景・目的

ReadModel(Projection)をイベントから作り直すには、現在はサーバーにSSHして、`leave:rebuild-event-store --list-readmodels`で
出る全テーブルを手で空にしてから`php artisan event-sourcing:replay --force`を流す必要がある(docs/27-release-runbook.md)。
Projectorの不具合修正(例:変更セット20261024-fix-revoked-grant-remaining)を本番データに反映するたびにこの手作業が要るため、
管理画面から安全に実行できるようにする。

## 現状(As-Is)

- 再生:`event-sourcing:replay`(Spatie)。Projectorだけを呼び、Reactorは呼ばない
  (`vendor/spatie/laravel-event-sourcing/src/Console/ReplayCommand.php`、`Projectionist::replay`)。
  トランザクションでは囲まれない。`projections:rebuild`(`backend/app/Console/Commands/RebuildProjectionsCommand.php`)は
  replayへ委譲するだけで、テーブルを空にしない。
- 空にする対象の一覧:`App\Domain\EventSourcing\Rebuild\ReadModelTableLister::tables()`
  (`backend/app/Domain/EventSourcing/Rebuild/ReadModelTableLister.php`)。Projectorのソースを静的に走査し、書き込み先テーブルを集める。
  `NOT_READ_MODELS`(features, permissions, permission_scope_types, system_settings, stored_events, snapshots, user_credentials)を除く。
  現在88テーブル。`--list-readmodels`はこれを出力する。
- 件数とハッシュ:`ReadModelFingerprinter`(同ディレクトリ)。`--readmodel-fingerprint`で使う。
- テーブルを空にする既存のコードは無い(手作業。docs/27-release-runbook.md 137〜138行、docs/32-stored-event-history-normalization.md 194〜205行)。
  ReadModel同士に外部キーがある(例:`paid_leave_requests.attendance_day_id → attendance_days`、
  `paid_leave_schedule_entries.granted_paid_leave_grant_id → paid_leave_grants`)。
- Projectorの中の`onPaidLeaveUsageAllocated`などは既存行へ足し込むため、空にせずにreplayすると値が二重になる。
- 管理画面から時間のかかる処理を流す既存の型:
  - 有給付与予定の判定(`PaidLeaveScheduleAssessmentRunController` → `ScheduleAssessmentRunService` → `paid-leave:assess-schedules --pending`)。
    記録は`admin_command_runs`(`AdminCommandRun`。status: queued/running/succeeded/failed、summary(json))。
    管理画面は記録を`queued`で作るだけで、スケジューラーが毎分`--pending`を`runInBackground()`・`withoutOverlapping(180)`で起動し、取り出して実行する。
    二重実行は`Cache::lock`の中で`queued`/`running`の有無を見て防ぐ。応答の無い記録は失敗とみなす(running 120分、queued 30分)。
    DBキュー+`queue:work`は既定60秒で打ち切られるため使っていない。
  - 運用コマンド(`AdminCommandController`、`RunAdminCommandJob`、DBキュー)。60秒の打ち切りがある。
- 管理画面:`frontend/src/pages/admin/`(SystemSettingsPage・AuditLogPage・AdminCommandsPage)、ナビは
  `frontend/src/components/AdminLayout/adminNavGroups.ts`の「システム」グループ。権限は`AccessControlCatalog`
  (`system_settings.*`、`audit_log.*`、`admin_command.view`/`admin_command.execute`)。
- 管理者専用の操作の監査:`SystemSettingController::update`が同じトランザクションで`system_settings.updated`を`stored_events`へ記録する。
- スケジューラー:`backend/routes/console.php`(`queue:work --stop-when-empty --max-time=50`を毎分、各種の日次バッチ、判定の`--pending`を毎分)。

## 設計前提の検証

- 正データと派生データ:空にして作り直すのは、Projectorが書くテーブル(派生データ)だけ。正は`stored_events`。
  ただし、`ReadModelTableLister`は静的解析なので、Projector以外の経路で書かれた行を持つテーブルも「Projectorが書く」だけで一覧に入る。
  その行は作り直すと消える。例:開発用の`DatabaseSeeder`は`admin@example.com`を`User::firstOrCreate`で直接作る(イベントが無い)。
  本番でイベントを経ずに作られた行があれば、それも消える(原則2の違反データで、作り直しで初めて表に出る)。
  → 試し実行で件数の差を見せることと、実行者自身のアカウントが作り直し後に無い場合は中止すること(論点6)で守る。
- `attendance_days`は原則3で「勤怠の正」とされているが、`AttendanceDayProjector`が書くReadModelでもあり、イベントから作り直せる
  (既存の全体リビルドの手順・`LeaveEventStoreRebuildScenarioTest`でも空にして作り直している)。今回の対象に含めてよい。
- イベントを経ずに書かれたReadModelの行(原則2の違反)は、作り直すと消える。確認できたもの:
  `database/migrations/2026_08_20_120000_create_admin_command_runs_table.php`が`admin_command.view`/`admin_command.execute`を
  管理者ロールへ`permission_role`に直接`insertOrIgnore`している(`permission_role`は`AccessControlProjector`が書くReadModel)。
  `leave:rebuild-event-store`の`RoleSetReconciler`がこの行を`role.permissions_changed`へイベント化するため、PR#121のリリース
  (stored_eventsの作り直し)を済ませた環境では消えないが、済ませていない環境で作り直すと消える。
  → 今回追加する権限はイベントで付与する(論点7)。作り直し後に依頼者が`read_model.rebuild`を失う場合は中止する(論点6)。
  試し実行の結果で、利用者・所属・ロール・権限のテーブルに変化があれば警告する(論点5)。
- Projectorは現在の`system_settings`(タイムゾーン・M365のテナントID)を読む。作り直しは現在の設定値で計算されるため、
  設定を変えた後は過去の行の値が変わり得る(試し実行で見える)。Projectorの中で`now()`を直接使う箇所は無い。
- 原因の分類:新機能。既存の手作業の手順(docs/27)を、同じ一覧のまま自動化する。新しい「正」を作らない。

## 仕様検討

### 論点1: どこで実行するか(XSERVERの制約)
- 選択肢:
  - A. HTTPリクエストの中で同期実行。PHPの実行時間・メモリの上限とWebサーバーのタイムアウトで途中で切られる恐れがある。
  - B. DBキュー+`queue:work`(運用コマンドと同じ)。既定60秒で打ち切られる。
  - C. 有給付与予定の判定と同じ型。管理画面は`admin_command_runs`に`queued`の記録を作るだけにし、スケジューラーが毎分起動する
    artisanコマンドが取り出して、CLIのプロセスで実行する(`runInBackground()`・`withoutOverlapping`)。
- 決定: C
- 理由: XSERVERでは常駐workerを使えず(backend/CLAUDE.md)、CLIなら実行時間の上限に掛からない。判定の実行で使っている型をそのまま写せる。
  開始まで最大1分待つが、作り直しの用途では問題ない。
- 未確定・要確認事項: なし

### 論点2: テーブルの空にし方と作り直しの単位
- 選択肢:
  - A. TRUNCATE。速いが、MySQLでは暗黙にコミットされ取り消せない。
  - B. 1つのトランザクションの中で、一覧の全テーブルをDELETEし、replayまで行い、成功したらコミット、失敗したらロールバック。
- 決定: B(ユーザーの回答)
- 理由: 途中で失敗しても元のデータに戻る。トランザクションの間、他の接続(MySQL InnoDB)はコミット前の古いデータを読める。
- 細部:
  - 外部キー:トランザクションの前に`Schema::disableForeignKeyConstraints()`、終わった後(成功・失敗とも)に`enableForeignKeyConstraints()`。
    SQLite(開発・テスト)は同じ呼び出しで`PRAGMA foreign_keys`を切り替える(トランザクションの外で呼ぶ必要があるため、この順にする)。
  - 対象:`ReadModelTableLister::tables()`の全テーブル(`--list-readmodels`と同じ一覧)。DBに存在しないテーブルは飛ばす。
    `admin_command_runs`・`jobs`・`personal_access_tokens`・`cache`・`sessions`・`migrations`は一覧に入らないので触らない。
  - replay:トランザクションの中で`Projectionist`の全Projectorを、`stored_events`の先頭から再生する
    (`event-sourcing:replay --force`と同じ。artisan呼び出しでも`Projectionist::replay()`の直接呼び出しでもよいが、同じ接続・同じトランザクションの中で行う)。
- 未確定・要確認事項: なし

### 論点3: 実行中の他の利用者の操作(メンテナンス中)
- 選択肢:
  - A. Laravelの`php artisan down`。管理者の画面(進み具合の確認)も止まり、終わった後の`up`もCLIが要る。
  - B. アプリの中で「作り直しの実行中」を判定し、APIを503で止める。
  - C. 止めない。
- 決定: B(ユーザーの回答「自動でメンテナンス中にする」)
- 理由: 実行中に記録された操作(イベント)は、トランザクションのロック待ちや再生との競合で、反映が漏れたり二重になったり、デッドロックで失敗したりする。
  管理者が進み具合を見られるよう、アプリの中で止める。
- 細部:
  - 「実行中」:`admin_command_runs`に、作り直しの記録(`command_name = 'read-models:rebuild'`)で`status = 'running'`のものがあり、
    かつ心拍(論点6)が10分以内に更新されている間。`queued`の間は止めない(取り出された時点で`running`にし、そのコミットの後に始まる)。
    プロセスが落ちて心拍が途絶えれば、記録が`running`のままでも10分でメンテナンスが解ける
    (落ちたプロセスのトランザクションは接続の切断でロールバックされている)。
  - 止めるもの:全API(`/api/*`)に503と`{"code":"maintenance","message":"システムのメンテナンス(データの作り直し)中です。しばらくしてから再度お試しください。"}`を返す。
    打刻端末・外部連携・MCPからのAPIも同じく503。
  - 止めないもの(GETのみ):`GET /maintenance-status`(新設。認証不要。`{"maintenance": bool}`)、作り直しの状態の
    `GET /admin/read-model-rebuilds`・`GET /admin/read-model-rebuilds/{id}`、フロントが起動時に呼ぶ`GET /auth/me`・`GET /access/me`・
    `GET /onboarding/status`。書き込みを伴うAPIは、作り直しを依頼するAPIを含めてすべて止める。
  - 実行中にも書き込みが続く経路への対処:
    - スケジューラー:作り直しの実行中(上の判定)は、`routes/console.php`の作り直し以外の全スケジュール
      (`queue:work`・日次バッチ・判定の`--pending`等)を`->skip(...)`で飛ばす。新しく起動されるものだけが止まる。
    - すでに動いているもの(ミドルウェアを通過済みのHTTPリクエスト、`queue:work --max-time=50`、日次バッチ):取り出して`running`をコミットした後、
      60秒(設定値。テストでは0)待ってからテーブルを空にする。
    - 有給付与予定の判定(最大120分動く):判定の記録が`queued`/`running`の間は、作り直しの記録を取り出さない(`queued`のまま待つ)。
  - 日次バッチ(`users:sync-ms365` 1:00、`paid-leave:roll-schedules` 2:00、`special-leave:grant-scheduled` 2:05、`paid-leave:warn-*` 2:10・2:20、
    判定の定期実行など)は、作り直しの実行中に時刻が来るとその日は飛ばされ、後から追い付かない。画面の説明文に、これらの時刻を避けて実行する旨を出す
    (追い付かせる仕組みは対象外)。
  - 画面:フロントのAPIクライアントが503かつ`code === 'maintenance'`を受けたら、全画面に「メンテナンス中」の表示を出す
    (`ui-interaction-patterns`のError/Permissionの扱いに合わせる)。表示の間は`GET /maintenance-status`を15秒ごとに読み、
    `maintenance: false`になったら画面を再読込する。作り直しの管理画面(`/admin/read-model-rebuild`)ではこの全画面の表示を出さず
    (他のクエリが503になっても進み具合を隠さない)、状態のAPIだけで進み具合を出す。
  - 打刻端末(共有Android打刻リーダー)は、メンテナンス中の打刻が503になる。端末側の再送は今回の対象外(対象外に記載)。
- 未確定・要確認事項: なし

### 論点4: 試し実行(dry-run)と即時実行
- 選択肢:
  - A. 試し実行を必須にする(試し実行の後でしか本実行できない)。
  - B. 試し実行と本実行を、どちらも単独で依頼できるようにする。
- 決定: B(ユーザーの回答「dry-runをつけてもいいですが、即時実行できるようにもしておいてください。」)
- 理由: 差分を見てから実行したい場合と、すぐ反映したい場合の両方に使えるようにする。
- 細部:
  - 試し実行:本実行と同じ処理(メンテナンス中にする・同じトランザクションで空にしてreplay)をし、最後にコミットせずロールバックする。
    結果(summary)にはテーブルごとの「作り直し前の件数・作り直し後の件数・内容が変わったか(`ReadModelFingerprinter`のハッシュの比較)」を残す。
    試し実行もトランザクションとロックを使うため、メンテナンス中にする。
  - 本実行:同じ処理の最後にコミットする。summaryは試し実行と同じ形。
  - 「即時実行」は試し実行を経ずに本実行を依頼できる、という意味とする(開始はスケジューラーの次の起動、最大約1分後)。
- 未確定・要確認事項: なし

### 論点5: 画面とAPI
- 決定:
  - 画面:管理画面の「システム」グループに「データの作り直し」(`/admin/read-model-rebuild`)を追加する。
    - 説明文:何をするか(全ReadModelをイベントから作り直す)、実行中は全利用者がメンテナンス中になること、所要時間はデータ量による旨。
    - ボタン「試し実行」と「作り直す」。どちらも確認ダイアログを出す(「作り直す」は破壊的操作のダイアログ。`ui-interaction-patterns`)。
      `queued`/`running`の記録がある間は両方とも押せない。
    - 説明文には、日次バッチの時刻を避けること(論点3)と、作り直しは現在のシステム設定で計算されることも書く。
    - 実行の履歴(新しい順、直近20件):種別(試し実行/本実行)・状態・依頼者・依頼日時・開始・終了・エラー。
      行を開くと、テーブルごとの件数の前後と「変更あり」の一覧(変更のあったテーブルを上に)。
      利用者・権限に関わるテーブル(`users`・`memberships`・`groups`・`roles`・`role_assignments`・`permission_role`・`role_features`)に
      変更があれば、一覧の上に警告(「利用者・権限のデータが変わります。本実行の前に内容を確認してください。」)を出す。
    - `queued`/`running`の記録がある間は5秒ごとに再取得する(判定の画面と同じ)。
  - API(`routes/api.php`、新しい権限`read_model.rebuild`で守る):
    - `GET /admin/read-model-rebuilds`:直近20件。
    - `GET /admin/read-model-rebuilds/{id}`:1件(summaryを含む)。
    - `POST /admin/read-model-rebuilds`(body:`{ "dry_run": boolean }`):`queued`の記録を作り202。`queued`/`running`があれば409。
  - 応答の形は判定の実行のAPI(`PaidLeaveScheduleAssessmentRunController`)に合わせる(包みの有無を含めて実装時に既存と同じにする)。
- 未確定・要確認事項: なし

### 論点6: 安全装置
- 決定:
  - 本実行・試し実行とも、replayの後、コミット(またはロールバック)の前に、依頼者の`users`の行があり、かつ依頼者が実効権限として
    `read_model.rebuild`(global)を持つかを、トランザクションの中のデータで確かめる(権限の判定は既存のアクセス制御のサービスを使う)。
    満たさなければ本実行もロールバックし、失敗(`error_message`「作り直すと依頼者のアカウントまたは権限が失われるため中止しました。
    イベントを経ずに作られたデータがあります。」)にする。(作り直すと管理者がログイン・操作できなくなる事故を防ぐ。依頼者が`null`の記録は無い。)
  - 心拍:replayの間、1000イベントごと(`Projectionist::replay`の`$onEventReplayed`で数える)と各段階(空にした・replayを終えた)で、
    `Cache::store('file')`に心拍の時刻を書く(既定のキャッシュはDBで、トランザクションの中の書き込みは他から見えないため、ファイルのキャッシュを使う)。
    - メンテナンスの判定(論点3)とstaleの判定は心拍を見る:`running`で心拍が10分より古ければ失敗とみなす(`STALE_MESSAGE`と同じ扱い)。
    - 開始から180分を超えたら、コミットせずにロールバックして失敗(「時間の上限を超えたため中止しました。」)にする
      (メンテナンスが解けた後にコミットしないため)。上限は設定値。
  - 二重実行:判定と同じく`Cache::lock`の中で`queued`/`running`の有無を確かめてから記録を作る。取り出しも同じロックの中で`running`にする。
  - 応答の無い記録:`running`は心拍が10分より古いとき、`queued`は作成から30分を超えたとき(判定の実行を待っている間は除く)に失敗とみなす。
  - 例外時:ロールバックし、外部キーの制約を戻し、記録を`failed`にして`error_message`に例外のメッセージを残す。
- 未確定・要確認事項: なし

### 論点7: 権限と監査
- 選択肢:
  - A. 既存の`admin_command.execute`で守る。
  - B. 新しい権限`read_model.rebuild`(scope: global)を作り、システム管理者のグループに付ける。
- 決定: B
- 理由: 全利用者を止めて全データを作り直す、影響の大きい操作。運用コマンドの実行者と分けて付与できるようにする。
- 付与:新しい権限は、`permissions`への行の追加(参照マスタ。`ReadModelTableLister`の対象外)と、管理者ロール(`roles.code = 'admin'`)の権限への追加を、
  migrationで行う。ロールの権限の追加は`permission_role`へ直接書かず、`ChangeRolePermissions`(現在のロールの権限の集合+`read_model.rebuild`の全置換。
  既に含まれていれば何もしない)をCommandBusで発行し、`role.permissions_changed`を記録する(作り直しで消えないように)。
  新規環境では`AccessControlSeeder`の`Role::ADMIN => array_keys(AccessControlCatalog::PERMISSIONS)`で付く。
  (migrationからCommandを発行する既存の例は無い。実装時にmigrationの実行時点で集約・Projectorが動くことをテストで確かめる。
  動かせない場合は、同じ処理を行う一度きりのartisanコマンドにし、リリース手順に追加する。)
- 監査:依頼時に、記録の作成と同じトランザクションで監査イベント`read_model_rebuild.requested`(依頼者・試し実行か・記録のID)を`stored_events`へ記録し、
  終了時に`read_model_rebuild.finished`(記録のID・結果(succeeded/failed)・試し実行か・変更のあったテーブル数)を記録する。
  集約は`SystemSettingsAuditAggregate`と同じ作りの監査専用の集約を新設する(名前は実装時に衝突確認)。
  終了のイベントは作り直しのトランザクションの外(コミット/ロールバックの後)で記録する。Projectorは作らない(監査ログ画面は`stored_events`を読む)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- backend
  - 業務ルール・処理の置き場所:`App\Domain\EventSourcing\Rebuild\ReadModelRebuilder`(新設。名前は衝突確認)。
    `rebuild(bool $dryRun, string $requestedByUserId): array`(summaryを返す)。処理:
    1. `(new ReadModelTableLister)->tables(app_path('Domain'))`(引数の要否は実装に合わせる)から存在するテーブルを得る。
       各テーブルの件数とハッシュ(`ReadModelFingerprinter`)を取る。
    2. `Schema::disableForeignKeyConstraints()` → `DB::beginTransaction()`。
    3. 全テーブルを`DELETE`。心拍。
    4. 全Projectorで`stored_events`の先頭からreplay(同じ接続。`$onEventReplayed`で1000件ごとに心拍と時間の上限の確認)。心拍。
    5. 件数とハッシュを取り直す。依頼者の行と権限を確かめ、満たさなければ例外(論点6の文言)。時間の上限を超えていれば例外。
    6. 試し実行ならロールバック、本実行ならコミット。例外ならロールバックして再送出。
    7. `finally`で`Schema::enableForeignKeyConstraints()`。
    - 実装時の確認:一覧外のテーブルから一覧内のテーブルへの外部キーが無いか(あれば、外部キーの検査を切った状態で参照が宙に浮く)を
      migrationで確かめ、結果を実装結果に書く。
    - summary:`{ "dry_run": bool, "tables": [{ "table": string, "before": int, "after": int, "changed": bool }], "changed_tables": int }`。
  - 記録と取り出し:`App\Services\EventSourcing\ReadModelRebuildRunService`(新設)。`ScheduleAssessmentRunService`の`createManualRun`・
    取り出し・stale判定・ロックを写す。`command_name = 'read-models:rebuild'`、`parameters = { "dry_run": bool }`。
    依頼時に監査イベント`read_model_rebuild.requested`を同じトランザクションで記録。
  - コマンド:`read-models:rebuild --pending`(新設)。判定の実行が`queued`/`running`でなければ、`queued`の記録を1件取り出して`running`にし(コミット)、
    心拍を書き、60秒(設定値)待ってから`ReadModelRebuilder`を実行し、
    `succeeded`/`failed`とsummary・`error_message`を書き、`read_model_rebuild.finished`を記録する。`routes/console.php`に毎分・
    `withoutOverlapping(240)`・`runInBackground()`で登録。
  - メンテナンス:`ReadModelRebuildRunService::isRunning()`(作り直しの`running`の記録があり、心拍が10分以内)。
    - ミドルウェア(新設)を全APIに掛け、`isRunning()`なら503と論点3のJSON。除外:論点3の「止めないもの」のGET 6本。
    - `GET /maintenance-status`(新設、認証不要)。
    - `routes/console.php`の作り直し以外の全スケジュールに`->skip(fn () => app(ReadModelRebuildRunService::class)->isRunning())`。
  - 権限:`AccessControlCatalog`に`'read_model.rebuild' => ['name' => 'データの作り直し', 'scopes' => ['global']]`。
    システム管理者グループへの付与は既存の権限の付け方(`AccessControlSeeder`・本番向けのmigration/イベントの要否)に合わせる。
  - イベント:`read_model_rebuild.requested`・`read_model_rebuild.finished`を`config/event-sourcing.php`の`event_class_map`に登録。
  - API:論点5の3本。Controllerは`App\Http\Controllers\Api\ReadModelRebuildController`(新設)。
- frontend
  - `src/api/readModelRebuilds.ts`・`src/hooks/useReadModelRebuilds.ts`(`add-api-hook`)。
  - `src/pages/admin/ReadModelRebuildPage.tsx`+story+test(`add-page`)。ナビ「システム」に「データの作り直し」(権限`read_model.rebuild`)。
  - APIクライアント(`src/api/client.ts`)で503・`code === 'maintenance'`を判別できるエラーにし、アプリ全体で「メンテナンス中」の表示を出す
    (`GET /maintenance-status`を15秒ごとに読み、解けたら再読込。`/admin/read-model-rebuild`では出さない)。
- テスト(`domain-test`。SQLite+Eloquent):
  - `ReadModelRebuilder`のテストは`RefreshDatabase`ではなく`DatabaseMigrations`を使う(`RefreshDatabase`の外側のトランザクションの中では、
    SQLiteの`PRAGMA foreign_keys`を切り替えられず、親テーブルのDELETEが外部キー違反になるため)。
  - `ReadModelRebuilder`:
    - 本実行:Projectorの値をわざと壊した(取消済みの付与の`remaining_days`を付与日数にした等、イベントを経ずに書き換えた)状態から作り直すと、
      イベントどおりの値に戻り、summaryの該当テーブルが`changed=true`。変更の無いテーブルは`changed=false`・件数が同じ。
    - 足し込みのあるProjector(`paid_leave_usage_allocations`)が二重にならない(作り直しを2回流しても同じ値)。
    - 試し実行:summaryは本実行と同じ内容になるが、DBは作り直し前のまま。
    - 依頼者の`users`の行がイベントから作り直せない(直接作った利用者が依頼者)場合、本実行でもロールバックされ例外。
    - 依頼者の`read_model.rebuild`がイベントから作り直せない(`permission_role`に直接書いた)場合も同じ。
    - 時間の上限を0にすると、ロールバックされ失敗になる。
    - 外部キーのあるテーブル(`paid_leave_requests`→`attendance_days`等)を含めて失敗しない。
  - コマンド・サービス:二重依頼で409、取り出しで`running`→`succeeded`、例外で`failed`+`error_message`、心拍が古い`running`のstale判定、
    判定の実行が`running`の間は取り出さない、監査イベント2件の記録。
  - メンテナンス:`running`かつ心拍が新しい間、通常のAPIは503+`code=maintenance`、除外のGETは通る。心拍が古い・`succeeded`後は通る。
  - 権限の付与:migration(または一度きりのコマンド)の後、管理者ロールが`read_model.rebuild`を持ち、作り直し後も持っている。
  - スケジュール:`running`の間、他のスケジュールが飛ばされる(`Schedule`の`skip`の判定を直接呼んで確かめる程度でよい)。
  - API:権限の無い利用者は403、`POST`で202と`queued`の記録、`GET`で一覧・1件。
  - frontend:画面のテスト(ボタンの押下で`POST`の`dry_run`が正しい・実行中は押せない・summaryの表示)、クライアントの503判別のテスト。
  - e2e:対象外(作り直しの間、全APIが止まり、serialで流す他のシナリオに影響するため。必要なら別途)。

## 受け入れ条件

- 管理画面の「データの作り直し」で、試し実行・本実行のどちらも単独で依頼でき、約1分以内に開始される。
- 実行中は、他の利用者のAPIが503(メンテナンス中)になり、画面に「メンテナンス中」が出る。依頼者は進み具合を見られる。終われば自動で戻る。
- 本実行の後、ReadModelはイベントから作り直した値になり、二重にならない(取消済みの付与の残日数が0になる等)。
- 試し実行の後、DBは変わらず、テーブルごとの件数の前後と変更の有無が見られる。
- 途中で失敗すると元のデータのまま残り、記録が失敗になり、メンテナンスが解ける。
- 依頼者のアカウントが作り直せない場合は中止される。
- 権限`read_model.rebuild`の無い利用者は画面・APIを使えない。
- 依頼と終了が監査ログに残る。
- 追加したテスト・既存のテスト(backendはCIと同じ条件の全件、frontendはvitest・lint・tsc)がPASSする。

## 対象外

- 打刻端末・外部連携・MCPがメンテナンス中に受けた503の再送(各クライアントの再試行に任せる)。
- 一部のProjectorだけの作り直し(全体のみ)。
- `stored_events`自体の作り直し(`leave:rebuild-event-store`)を画面から行うこと。
- 所要時間の見積りの表示(実測が無い)。
- 作り直しの実行中に飛ばされた日次バッチの追い付き(画面で時刻を避けるよう案内するだけ)。
- `admin_command.*`の権限の付与を直接書いている既存のmigrationの修正(PR#121のリリースの`RoleSetReconciler`でイベント化される)。
- 作り直しのe2e。

## ドキュメントへの影響

- `docs/27-release-runbook.md`:ReadModelの作り直しの手順に、管理画面からの実行(試し実行→本実行)を追記する。手作業の手順は残す(SSHで行う場合)。
- `docs/32-stored-event-history-normalization.md`:作り直しの手順の節に、管理画面からも実行できる旨を1文追記。
- `docs/16-database-schema.md`:`admin_command_runs`の説明に`command_name = 'read-models:rebuild'`と`summary`の形を追記。
- `docs/17-events.md`:`read_model_rebuild.requested`・`read_model_rebuild.finished`を追加。
- 権限の一覧を載せているドキュメント(実装時に`grep 'admin_command.execute' docs`で特定)に`read_model.rebuild`を追加。

## モック・アセット

なし

## 実装対象

- backend:`app/Domain/EventSourcing/Rebuild/ReadModelRebuilder.php`(新規)、`app/Services/EventSourcing/ReadModelRebuildRunService.php`(新規)、
  `app/Console/Commands/RebuildReadModelsCommand.php`(新規、`read-models:rebuild`)、監査の集約・イベント(新規)、
  `app/Http/Controllers/Api/ReadModelRebuildController.php`(新規)、メンテナンスのミドルウェア(新規)、`routes/api.php`、`routes/console.php`、
  `bootstrap/app.php`(ミドルウェアの登録)、`config/event-sourcing.php`、`AccessControlCatalog`・権限の付与、テスト。
- frontend:`src/api/readModelRebuilds.ts`、`src/hooks/useReadModelRebuilds.ts`、`src/pages/admin/ReadModelRebuildPage.tsx`(+story+test)、
  `src/App.tsx`、`src/components/AdminLayout/adminNavGroups.ts`、`src/api/client.ts`とメンテナンス表示、テスト。
- docs:上記「ドキュメントへの影響」。

## 検証方法

- `cd backend && cp .env.example .env && php artisan key:generate && php artisan test`(CIと同じ条件。後で`.env`とsqliteを消す)
- `cd frontend && npx vitest run && npm run lint && npx tsc -p tsconfig.app.json --noEmit`
- 手動:開発環境で試し実行と本実行を依頼し、`php artisan schedule:run`(または`read-models:rebuild --pending`)で実行させ、画面と503を確認する。
- リリース前(本番の複製DB。リハーサルの手順に追加する):試し実行を流し、所要時間・PHPのメモリの最大値・MySQLのトランザクションの大きさ
  (undo・binlogのキャッシュの上限に掛からないか)を測り、本番の実行可否と`memory_limit`の要否を決める。差分(summary)も確かめる。

## レビュー履歴

- 初版
- 2026-10-25: 独立設計レビューの指摘を反映。
  (高1)権限を`permission_role`へ直接書くと作り直しで消える → 付与をイベントで行い(論点7)、安全装置に依頼者の権限の確認を追加(論点6)、
  利用者・権限のテーブルの変化を警告(論点5)。
  (高2)180分のstale判定では、落ちたプロセスで最大3時間止まり、長い実行は解除後にコミットされ得る → ファイルのキャッシュの心拍と、時間の上限での中止(論点6)。
  (高3)`running`の後も書き込みが続く経路 → 60秒の待ち、判定の実行中は取り出さない(論点3)。
  (中4)SQLiteの外部キーと`RefreshDatabase` → テストは`DatabaseMigrations`。
  (中5・中6)メンテナンスの解除の検知と除外APIの特定 → `GET /maintenance-status`と除外6本、作り直しの画面では全画面表示を出さない。
  (中7)日次バッチが飛ばされる → 時刻を避ける案内、追い付きは対象外。
  (低8〜10)現在の設定値で計算されること、`tables()`の引数、一覧外からの外部キーの確認、複製DBでの測定を追記。
  前提(replayは外側のトランザクションの中で動く・Reactorを呼ばない・副作用のあるProjectorは無い・除外したGETは書かない・本番の利用者と通知の既読はイベント経由)は問題なしと確認。
- 2026-10-25: 変更セット`20261025-read-model-rebuild-admin-command`で置き換えた。専用画面・専用API・権限`read_model.rebuild`・監査イベント`read_model_rebuild.*`・
  `read-models:rebuild --pending`を削除し、運用コマンド`read-models:rebuild {--dry-run}`と実行役`admin-commands:run-pending`にした(依頼者の確認も削除)。

## 実装結果

### コミット
- `2909045` feat(frontend): 「データの作り直し」画面(`/admin/read-model-rebuild`)・API hook・全画面のメンテナンス表示(`MaintenanceGate`/`MaintenanceNotice`、`client.ts`の503判別)
- `d48621b` feat(backend): `ReadModelRebuilder`・`ReadModelRebuildRunService`・`read-models:rebuild --pending`・API 3本と`GET /maintenance-status`・
  ミドルウェア`BlockDuringReadModelRebuild`・監査イベント2種(`App\Domain\ReadModelRebuild`)・権限`read_model.rebuild`の追加migration・テスト・docs
- `8e66c88` fix(frontend): 実行履歴でエラーを全幅の行に出し、表を画面幅に収める(実機確認で見つかった表示の不具合)

### 実装上の決定(仕様の範囲内)
- 権限の付与はmigration(`2026_10_25_100000_add_read_model_rebuild_permission.php`)で、`permissions`の行の追加と、adminロールへの`ChangeRolePermissions`の発行(イベント)。
  `permission_role`へ直接書いていない。作り直し後も管理者が持ち続けることをテストで確認。
- 設定値:`config/read_model_rebuild.php`(待ち60秒・時間の上限180分・心拍の古さ10分・queuedの上限30分。envで上書き可)。
- 一覧外のテーブルから一覧内のテーブルへの外部キーが16件ある(`admin_command_runs.requested_by_user_id`→users、`asset_loan_requests`→users/assets、
  `authentication_key_device_rules`→devices/authentication_keys、`compensatory_leave_grant_cancellations`→users/compensatory_leave_grants、
  `expense_entry_presets`→users、`external_employee_mappings`→users、`external_integration_connections`→users、`paid_leave_grant_rules`/
  `special_leave_grant_rules`/`system_settings`→work_styles)。作り直しは同じIDで行を作り直すため、親がイベントから作り直せる限り参照は保たれる。
  本番の複製DBでの試し実行の後に、参照切れが無いかを確かめる。

### 検証結果
- backend(CIと同じ条件:`.env`+`key:generate`+ファイルのsqlite+`php artisan test`):2176件中2175件PASS。失敗は
  `ReadModelFingerprintCommandTest::test_fingerprintは1行1テーブル…`の1件で、`origin/main`でも失敗する既存の失敗(変更セット20261024-fix-revoked-grant-remainingで確認)。
  追加テストは53件(`ReadModelRebuilderTest`・`ReadModelRebuildCommandTest`・`ReadModelRebuildRunServiceTest`・`ReadModelRebuildApiTest`・
  `BlockDuringReadModelRebuildTest`・`ReadModelRebuildPermissionMigrationTest`)。
- frontend:`npx vitest run --project=unit` 全件PASS(1076件+追加分)、`npm run lint` error無し、`tsc`(app・e2e)エラー無し、
  追加したstoryのstorybookテストPASS(`VITEST_CHROMIUM_EXECUTABLE_PATH`指定)。
- 開発環境での実機確認(backend・mock-oidc・frontendを起動し、`read-models:rebuild --pending`をスケジューラーの代わりに実行):
  - 試し実行を依頼(202)→実行中、一般の利用者の画面は「メンテナンス中」の全画面表示になり、終了後`maintenance:false`で通常の画面に戻った。
  - 開発用の`admin@example.com`(シーダーが`firstOrCreate`で直接作る)が依頼者のときは、安全装置で中止された
    (「作り直すと依頼者のアカウントまたは権限が失われるため中止しました。…」)。
  - イベントから作られた社員(高橋 健太)を管理者グループに所属させて本実行を依頼→成功(約23秒)。結果は変更3テーブル
    (`users` 7→6、`role_assignments` 14→6、`group_feature_assignments` 16→15。いずれもシーダーが直接書いた行で、イベントから作り直せない分)。
    利用者・権限の警告が表示された。依頼者は作り直し後も操作できた。

### 受け入れ条件の確認
- 試し実行・本実行を単独で依頼でき、`--pending`で開始される:実機・テストで確認。
- 実行中の503とメンテナンス表示、終了後の復帰:実機・`BlockDuringReadModelRebuildTest`で確認。
- 本実行でイベントどおりの値に戻り二重にならない・試し実行でDBが変わらない・失敗でロールバック・依頼者の保護:`ReadModelRebuilderTest`・実機で確認。
- 権限の無い利用者は使えない:`ReadModelRebuildApiTest`(403)、ナビは権限`read_model.rebuild`で表示。
- 依頼と終了の監査:`ReadModelRebuildCommandTest`・`ReadModelRebuildRunServiceTest`で`read_model_rebuild.requested`/`finished`を確認。
- 既存テスト:上記の既存の1件を除きPASS。

### リリース時の注意
- 本番で使う前に、本番の複製DBで試し実行を流し、所要時間・メモリ・MySQLのトランザクションの大きさと、差分(特に利用者・権限のテーブル)を確かめる。
- PR#121のリリース(stored_eventsの作り直し。`RoleSetReconciler`が直接書かれた権限の付与をイベント化する)より前に本実行すると、
  `admin_command.*`の付与が消える。試し実行の警告で確認できる。
