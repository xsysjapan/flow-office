# ReadModelの作り直しを運用コマンドにし、開発用シーダーをイベント経由にする

ステータス: 完了

## 変更要望(原文)

```
専用画面ではなく、単純に運用コマンドに実装してください。実施者について気にしなくて良いです。むしろ開発環境のユーザーについてイベントが発生するように生成方法を見直してください。
```

## 背景・目的

変更セット`20261025-admin-read-model-rebuild`で、ReadModelの作り直しを専用画面・専用API・専用の権限として作った(PR #123、未マージ)。
これを既存の「運用コマンド」(`/admin/commands`)に載せる形に簡素化する。依頼者を守る安全装置は不要とする。
開発環境で作り直すと、シーダーがイベントを経ずに書いた行(`admin@example.com`、ロールの割り当て、機能の割り当て)が消えるため、
開発用シーダーをCommand経由(イベントを記録する形)に直す。

## 現状(As-Is)

- 運用コマンド:`#[AdminExecutable(label, rules, ui, withoutOverlapping)]`(`backend/app/Console/Attributes/AdminExecutable.php`)を付けた
  artisanコマンドを`AdminCommandRegistry`が集め、`/admin/commands`の画面(`frontend/src/pages/admin/AdminCommandsPage.tsx`)から実行する。
  オプションの真偽値はチェックボックスになる。
  - 依頼:`POST /admin/commands/{command}/runs`(`AdminCommandController`)が`admin_command_runs`に`queued`を作り、`RunAdminCommandJob`をDBキューへ積む。
  - 実行:`routes/console.php`が毎分`queue:work --stop-when-empty --max-time=50`を起動する。`queue:work`の既定の`--timeout=60`・`--tries=1`のため、
    **60秒を超える運用コマンドは強制終了され、記録が`running`のまま残る**。さらに`Cache::lock('admin-command:<name>', 3600)`が1時間残る。
    既存の運用コマンド7件(日次勤怠の一括再計算など)も同じ制約を受ける。
  - 監査イベントは記録しない(記録は`admin_command_runs`のみ)。
- 長時間の処理の既存の型:有給付与予定の判定(`paid-leave:assess-schedules --pending`を`runInBackground()`で毎分起動)。
- 前の変更セットで作ったもの(PR #123):
  - 実行系:`ReadModelRebuilder`(全ReadModelを1トランザクションでDELETE→全Projectorをreplay、試し実行はロールバック、心拍、時間の上限、
    依頼者の確認)、`ReadModelRebuildRunService`(記録・取り出し・stale・`isRunning()`)、`RebuildReadModelsCommand --pending`。
  - メンテナンス:`BlockDuringReadModelRebuild`(実行中は全APIを503)、`GET /maintenance-status`、`routes/console.php`の他スケジュールのskip、
    フロントの`MaintenanceGate`/`MaintenanceNotice`。
  - 専用:`ReadModelRebuildController`(API 3本)、画面`ReadModelRebuildPage`・hook・api・ナビ、権限`read_model.rebuild`とそのmigration、
    監査イベント`read_model_rebuild.requested`/`finished`(`App\Domain\ReadModelRebuild`)。
- 開発用シーダーのイベントを経ない書き込み(ReadModelのテーブル):
  - `DatabaseSeeder.php:31-37`:`User::firstOrCreate('admin@example.com')`(`users`。entra_user_idはランダム)。
  - `ScenarioSeeder.php:109`(`linkAdminToMockOidc`):`users.entra_user_id`を`mock-entra-admin`へ直接更新。
  - `ScenarioSeeder.php:323`:`role_assignments`を`updateOrInsert`。
  - `ScenarioAccessSeeder.php:18-24`:`group_feature_assignments`を`updateOrInsert`。`:29`:`role_assignments`(ALL_USERS/employee)を`updateOrInsert`
    (`AccessControlSeeder`が別IDで同じグループ・ロールの割り当てを作るため二重になる)。
  - これ以外のシーダーの書き込みは、Command経由か、ReadModelでないマスタ(雇用区分・経費区分・申請種別・付与ルール等)。
- シーダーを使う箇所:`DevDatabaseResetController`(e2eの`globalSetup`。`migrate:fresh --seed`+`ScenarioSeeder`)。PHPUnitで`DatabaseSeeder`/`ScenarioSeeder`を使うテストは無い。

## 設計前提の検証

- 正データと派生データ:作り直しの対象は従来どおりProjectorが書くテーブル(派生データ)。シーダーの直接書き込みはReadModelへの
  イベントを経ない書き込み(原則2の違反)で、作り直しで消えるのは正しい挙動。直すべきはシーダー側。
- 運用コマンドの60秒の打ち切りは、作り直しに限らず既存の運用コマンドにも当てはまる設計上の制約。作り直しだけを別経路にするのではなく、
  運用コマンドの実行経路を直すのが筋(論点1)。
- 原因の分類:要望による設計の変更(簡素化)と、シーダーの実装のミス(原則2違反)。

## 仕様検討

### 論点1: 運用コマンドの実行経路(60秒の打ち切り)
- 選択肢:
  - A. 作り直しのコマンドだけ、属性で「長時間」と印を付け、キューではなくスケジューラーの別経路で実行する。
  - B. 運用コマンドすべてを、キューではなくスケジューラーで実行する。新しいコマンド`admin-commands:run-pending`を毎分・`runInBackground()`・
    `withoutOverlapping`で起動し、`queued`の記録を古い順に1件ずつ取り出してCLIで実行する。`RunAdminCommandJob`のディスパッチをやめる。
  - C. キューのワーカーの`--timeout`を延ばす。毎分起動・`--max-time=50`の運用と両立しない。
- 決定: B
- 理由: 既存の運用コマンド(日次勤怠の一括再計算など)も60秒を超えると強制終了され、記録とロックが残る。開始までの待ち(最大約1分)は
  今のキューと同じ。経路を1本にする方が単純。
- 細部:
  - 実行・記録の更新(running→succeeded/failed、exit_code、output、error_message)は、今の`RunAdminCommandJob::handle()`の処理をサービス
    (例:`AdminCommandRunner`)へ移して使う。`RunAdminCommandJob`は削除する。
  - 実行役は`withoutOverlapping(240)`(作り直しの時間の上限180分+余裕。既定の1440分だとプロセスが落ちたときに全運用コマンドが24時間止まる)。
  - 取り残しの判定:実行役は同時に1本しか動かないので、起動した時点で残っている`running`の記録はすべて取り残し(前のプロセスが落ちた)とみなし、
    失敗(「応答がないため失敗とみなしました」)にして、そのコマンドの排他のロック`admin-command:<name>`を`forceRelease()`する。時間のしきい値は使わない
    (長い処理を途中で失敗と表示しない)。排他のロックの期限は240分に揃える。
  - 取り出しは1回の起動で`queued`が無くなるまで古い順に実行する。1件ごとに例外を受け止めて記録を失敗にし、次の記録へ進む。
  - 作り直しの記録の取り出しの条件:有給付与予定の判定(`ScheduleAssessmentRunService`の記録)が`queued`/`running`の間は、作り直しの記録を取り出さず
    `queued`のまま残し、次の記録へ進む(作り直しを`running`にするとメンテナンスで判定のスケジュールが止まり、判定が終わらず待ち続けるため)。
  - 実行中の記録の受け渡し:実行役は実行する記録をコンテナに登録し(例:`app()->instance(CurrentAdminCommandRun::class, ...)`)、コマンドから読めるようにする
    (`Artisan::call`ではIDを渡せず、隠しオプションは運用コマンドの画面に入力欄として出てしまうため)。
  - デプロイ時の移行:`jobs`テーブルに残った`RunAdminCommandJob`の行は削除する(リリース手順に追加)。`queued`の記録は新しい実行役が拾う。
- 未確定・要確認事項: なし

### 論点2: 作り直しの運用コマンド
- 決定:
  - コマンド`read-models:rebuild {--dry-run}`に`#[AdminExecutable(label: 'データの作り直し(ReadModel)')]`を付ける。説明文(コマンドの`description`)に、
    全利用者がメンテナンス中になること・日次バッチの時刻を避けること・試し実行はロールバックすることを書く。
  - 処理は`ReadModelRebuilder::rebuild(bool $dryRun)`。依頼者の引数と確認(`assertRequesterSurvives`)を削除する(要望「実施者について気にしなくて良い」)。
    1トランザクションのDELETE+replay、試し実行のロールバック、心拍、時間の上限、60秒の待ちはそのまま。有給付与予定の判定との調整は論点1の取り出しの条件で行う。
  - 実行の記録:実行役から渡された記録を使う。CLI(SSH)から直接`read-models:rebuild`を実行した場合は、自分で`admin_command_runs`の記録
    (`command_name = 'read-models:rebuild'`、依頼者null、`running`)を作ってから始め、終わったら更新する(メンテナンスに入るように)。
    その場合も、判定が`queued`/`running`なら拒否して終了コード1(「有給付与予定の判定の実行中です」)。作り直しの記録が既に`running`(`isRunning()`が真)のときも拒否して終了コード1(「作り直しを実行中です」)。
    記録の`parameters`は運用コマンドの画面と同じ`{"dry-run": bool}`にする。
  - `summary`の保存は、試し実行ではロールバックの後(トランザクションの外)で行う。
  - 結果:テーブルごとの件数の前後と変更の有無を、コマンドの出力(運用コマンドの画面の「出力」に出る)に表で書く。変更のあったテーブルを先に書き、
    利用者・権限のテーブル(`users`・`memberships`・`groups`・`roles`・`role_assignments`・`permission_role`・`role_features`・`group_feature_assignments`)に
    変更があれば先頭に警告の行を書く。`admin_command_runs.summary`にも同じ内容(前の変更セットの形)を保存する。
  - 削除するもの:専用API(`ReadModelRebuildController`とルート)、専用画面・hook・api・ナビ、権限`read_model.rebuild`(カタログ・migration・
    テスト用の付与)、監査イベント`read_model_rebuild.*`と集約、`RebuildReadModelsCommand --pending`(論点1の共通の経路に置き換わる)、
    `ReadModelRebuildRunService`のうち依頼・取り出し・一覧の処理(`isRunning()`と心拍の判定は論点3で使うため残す)。
  - 権限:運用コマンドの`admin_command.execute`で実行できる。
- 未確定・要確認事項: なし

### 論点3: メンテナンス中(前の変更セットの決定を引き継ぐ)
- 決定:
  - 作り直しの実行中は、前の変更セットどおり全APIを503にし、他のスケジュールを止める。判定は「`admin_command_runs`の
    `command_name = 'read-models:rebuild'`が`running`で、心拍が10分以内」。
  - 止めないGET:`/maintenance-status`、`/auth/me`、`/access/me`、`/onboarding/status`、運用コマンドの画面が読む`/admin/commands`・`/admin/command-runs`。
  - フロント:全画面の「メンテナンス中」表示は、運用コマンドの画面(`/admin/commands`)では出さない(進み具合を見続けられるように)。
  - 他のスケジュールのskip:論点1の`admin-commands:run-pending`自身も、作り直しの実行中は新しく起動しない(作り直しはその中で動いている)。
  - プロセスが落ちた場合:心拍が10分途絶えればメンテナンスは解ける。実行役の`withoutOverlapping`の期限(240分)までは、他の運用コマンドが始まらない(許容する)。
- 未確定・要確認事項: なし

### 論点4: 開発用シーダーをイベント経由にする
- 決定:
  - `DatabaseSeeder`の`admin@example.com`:`CreateUser`(CommandBus)で作る。IDは固定値(`uuid5(URL, 'seed-user:admin@example.com')`)、
    entra_user_idは渡さない。既に同じメールの利用者があれば何もしない。所属(SYSTEM_ADMINISTRATORS)は既存どおり`AddMembership`。
  - mock-oidcでのログイン:`MockOidcUserController`は`entra_user_id`のある利用者だけをmock-oidcに出すため、`migrate --seed`だけ(ScenarioSeederなし)の
    セットアップでも管理者でログインできるよう、mockが有効(`config('services.azure.mock_enabled')`)なときは`DatabaseSeeder`で`LinkSsoAccount`により
    `mock-entra-admin`を連携する(既に連携済みなら何もしない)。
  - `ScenarioSeeder::linkAdminToMockOidc`:直接の`update`を削除し、未連携のときだけ`LinkSsoAccount`で連携する(DatabaseSeederで連携済みなら何もしない)。
  - 既存の開発DB(管理者にランダムなentra_user_idが入っているもの)では`LinkSsoAccount`が拒否されるため、開発DBは`migrate:fresh --seed`で作り直す前提とし、
    backend/CLAUDE.mdのセットアップ手順とe2eのREADMEに一言書く。
  - `ScenarioSeeder.php:323`の`role_assignments`:`CreateRoleAssignment`(IDは今のuuid5を渡す)。既にあれば何もしない。
  - `ScenarioAccessSeeder`:
    - `group_feature_assignments`:`AssignFeatureToGroup`。行ごとに既にあれば何もしない。
    - ALL_USERS/employeeの`role_assignments`:削除する。`AccessControlSeeder.php:76`が同じグループ・ロール・scope(`self`)の割り当てを既にイベントで作っており、
      完全に重複しているため、実効の権限は変わらない。(`CreateRoleAssignment`で作り直してはいけない:グループの割り当ては`GroupFeatureSync::syncGroup`が走り、
      employeeの`role_features`が空なのでALL_USERSの機能が外れる。)
    - 機能の割り当てのうちイベントから作られていないのはALL_USERSの`backoffice`だけ(ほかは`AccessControlSeeder`がイベントで作る)。
      それを含め、行が無いものだけ`AssignFeatureToGroup`で作る。
  - Commandのハンドラーが要求する前提(ロールに該当scopeの権限があること、テナントIDの一致等)で失敗する場合は、実装せずに報告する。
- 確認方法:`DatabaseSeeder`+`ScenarioSeeder`の後に作り直しの試し実行をすると、変更のあったテーブルが0件になること(PHPUnitのテストにする)。
  (設計レビューで、今のシーダーの後の試し実行の差分は`users` 7→6、`role_assignments` 12→4、`group_feature_assignments` 16→15の3つだけと実測済み。)
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- backend
  - 運用コマンドの共通の実行経路:`admin-commands:run-pending`(新設、毎分・`runInBackground()`・`withoutOverlapping(240)`)。`AdminCommandController::store`は
    `queued`の記録を作るだけにする(Jobをディスパッチしない)。取り残しの判定・取り出しの条件・1件ごとの例外の受け止め・記録の受け渡しは論点1。
  - `read-models:rebuild {--dry-run}`:`#[AdminExecutable]`。心拍を書き、60秒(設定値)待ってから`ReadModelRebuilder::rebuild($dryRun)`。
    出力と`summary`は論点2。CLIから直接の実行は論点2。失敗時は例外のメッセージを出力し、終了コード1。
  - `ReadModelRebuilder`:依頼者の引数と確認を削除。ほかは今のまま。
  - メンテナンス:論点3。`ReadModelRebuildRunService`は`isRunning()`・心拍の書き込み・判定の実行中の確認だけを持つ形に縮める(名前は実装時に変えてよい)。
  - 削除:論点2の「削除するもの」。`routes/api.php`・`routes/console.php`・`config/event-sourcing.php`の該当行。
  - シーダー:論点4。
- frontend
  - 削除:`ReadModelRebuildPage`(+story+test)、`api/readModelRebuilds.ts`、`hooks/useReadModelRebuilds.ts`のうち作り直し専用の部分
    (`useMaintenanceStatus`はメンテナンス表示で使うため残す。置き場所は実装時に決めてよい)、ナビの項目、ルート。
  - `MaintenanceGate`:全画面の表示を出さないパスを`/admin/commands`に変える。
- テスト
  - 運用コマンドの実行経路:依頼で`queued`(Jobが積まれない)、`admin-commands:run-pending`で`succeeded`/`failed`、1件が例外でも次の記録が実行される、
    起動時に残っている`running`が失敗になりロックが解放される、判定の実行中は作り直しの記録が`queued`のまま残り他の記録は実行される、
    作り直しの実行中は実行役が起動しない。既存の`RunAdminCommandJob`のテスト(`AdminCommandTest.php`・`RebuildPaidLeaveScheduleCommandTest.php`の
    `Queue::assertPushed`等)を新しい経路に書き換える。
  - CLIから直接の作り直し:記録が作られメンテナンスに入る、判定の実行中は拒否。
  - 作り直しのコマンド:運用コマンドの一覧に出る、`--dry-run`でDBが変わらない、本実行でイベントどおりの値に戻る、出力に表と警告が出る、
    `summary`が保存される、判定の実行中は待つ。`ReadModelRebuilder`の既存テストから依頼者のケースを削除。
  - メンテナンス:除外のGETの差し替え。
  - シーダー:`DatabaseSeeder`+`ScenarioSeeder`の後、作り直しの試し実行で`changed_tables == 0`。同じシーダーを2回流しても失敗せず行が増えない。
    `admin@example.com`の行がイベントから作られている(`stored_events`に作成のイベントがある)。
  - e2e:シーダーを変えるため、ログイン(管理者=`mock-entra-admin`を含む)と主要シナリオ(scenario-00・scenario-03・scenario-16)を流す。
- docs:`docs/27-release-runbook.md`・`docs/32-...`・`docs/15-usecases-admin.md`の作り直しの記述を運用コマンドに直す。`docs/17-events.md`から
  `read_model_rebuild.*`を削除。`docs/16-database-schema.md`の`admin_command_runs`の説明を直す。前の変更セット`20261025-admin-read-model-rebuild`の
  レビュー履歴に、この変更セットで置き換えた旨を追記する。

## 受け入れ条件

- 運用コマンドの画面に「データの作り直し(ReadModel)」が出て、試し実行(チェックボックス)・本実行を依頼でき、約1分以内に始まる。
- 60秒を超える運用コマンドが強制終了されず、最後まで実行される。
- 作り直しの実行中は、他の利用者のAPIが503になり画面が「メンテナンス中」になる。運用コマンドの画面では進み具合(実行履歴)が見られる。
- 作り直しの結果(テーブルごとの件数の前後・変更の有無・利用者/権限の警告)が実行履歴の出力に出る。
- 専用画面・専用API・権限`read_model.rebuild`・監査イベント`read_model_rebuild.*`が無くなっている。
- 開発環境(`migrate:fresh --seed`+`ScenarioSeeder`)で作り直しても、ReadModelが変わらない(試し実行の変更0件)。
- e2eのログインと主要シナリオが通る。
- backend(CIと同じ条件の全件)・frontend(vitest・lint・tsc)がPASSする。

## 対象外

- 運用コマンドの画面のデザイン変更(出力の表示はそのまま使う)。
- 運用コマンドの配列のオプションがUIから送れない既存の問題(`attendance:recalculate-days --user[]`)。
- 本番データのイベントを経ない行の修正(PR#121のリリースの`RoleSetReconciler`で扱う)。

## ドキュメントへの影響

- `docs/27-release-runbook.md`、`docs/32-stored-event-history-normalization.md`、`docs/15-usecases-admin.md`(UC-M004を運用コマンドとして書き直す)、
  `docs/16-database-schema.md`(`admin_command_runs`)、`docs/17-events.md`(`read_model_rebuild.*`の削除)。
- 運用コマンドの実行経路を説明しているドキュメント(実装時に`grep -rn "RunAdminCommandJob\|queue:work" docs`で特定)を新しい経路に直す。
- `docs/27-release-runbook.md`:デプロイ時に`jobs`から`RunAdminCommandJob`の行を削除する手順を追加。
- `backend/CLAUDE.md`・`frontend/e2e/README.md`:既存の開発DBは`migrate:fresh --seed`で作り直す旨。
- `docs/changesets/20261025-admin-read-model-rebuild/spec.md`のレビュー履歴に追記。

## モック・アセット

なし

## 実装対象

- backend:`app/Console/Commands/RebuildReadModelsCommand.php`(書き換え)、運用コマンドの実行経路(新コマンド・`AdminCommandController`・
  `RunAdminCommandJob`の整理)、`ReadModelRebuilder`、`ReadModelRebuildRunService`(縮小)、`BlockDuringReadModelRebuild`(除外の差し替え)、
  削除するファイル一式、`routes/*.php`、`config/event-sourcing.php`、`AccessControlCatalog`、`tests/TestCase.php`、シーダー
  (`DatabaseSeeder`・`ScenarioSeeder`・`ScenarioAccessSeeder`)、テスト。
- frontend:削除するファイル一式、`App.tsx`、`adminNavGroups.ts`、`MaintenanceGate`、テスト。
- docs:上記。

## 検証方法

- `cd backend && cp .env.example .env && php artisan key:generate && rm -f database/database.sqlite && touch database/database.sqlite && php artisan test`
- `cd frontend && npx vitest run --project=unit && npm run lint && npx tsc -p tsconfig.app.json --noEmit && npx tsc -p e2e/tsconfig.json --noEmit`
- e2e:backend・mock-oidc・frontendを起動し、`npx playwright test --config e2e/playwright.config.ts scenario-00 scenario-03 scenario-16`
- 実機:開発環境で運用コマンドの画面から試し実行・本実行を依頼し、`php artisan schedule:run`(または`admin-commands:run-pending`)で実行して、
  出力・メンテナンス表示・変更0件を確かめる。

## レビュー履歴

- 初版
- 2026-10-25: 独立設計レビューの指摘を反映(レビューは一時DBでシーダー+試し実行を流し、差分が3テーブルだけと実測)。
  (重大1)作り直しが`running`になると判定のスケジュールが止まり、判定を待ち続ける → 判定の実行中は作り直しの記録を取り出さない(論点1)。
  (重大2)コマンドが実行の記録を知る手段が無い → 実行役がコンテナで渡す。CLIから直接の実行は自分で記録を作る。試し実行の`summary`はロールバックの後(論点2)。
  (重大3)`withoutOverlapping`の既定1440分 → 240分。ロックの期限も240分に揃え、取り残しの判定で解放(論点1)。
  (中4)120分の失敗扱い → 起動時に残っている`running`を取り残しとみなす(論点1)。(中5)1件ごとに例外を受け止める。(中6)デプロイ時の`jobs`の行の削除と既存テストの書き換え。
  (中7)`migrate --seed`だけで管理者がmock-oidcに出なくなる → mock有効時は`DatabaseSeeder`で連携。既存DBは`migrate:fresh`前提(論点4)。
  (中8)ALL_USERS/employeeの割り当ては完全な重複なので削除。`CreateRoleAssignment`で作り直すと機能が外れる(論点4)。

## 実装結果

### コミット
- `dfd2d83` refactor(frontend): 専用画面の削除、メンテナンス表示の例外を`/admin/commands`に(この時点のコミットに、backendの削除ファイルも一緒に入った。次のコミットで整合)
- 次のコミット refactor(backend): 運用コマンドの実行役`admin-commands:run-pending`(`App\Console\Commands\RunPendingAdminCommandsCommand`・
  `App\Services\AdminCommand\AdminCommandRunner`・`App\Console\CurrentAdminCommandRun`)、`read-models:rebuild {--dry-run}`の運用コマンド化、
  専用API・権限・監査イベント・依頼者の確認の削除、`RunAdminCommandJob`の削除、docs
- fix(seed): `DatabaseSeeder`・`ScenarioSeeder`・`ScenarioAccessSeeder`をCommand経由に。`tests/Feature/Seeders/DevSeedersEventSourcedTest.php`
- fix(frontend): 運用コマンドの確認ダイアログの文言(「DBキューへ投入」→「約1分以内に始まり」)

### 実装上の決定(仕様の範囲内・レビューで確定)
- 実行役が扱う記録は`AdminCommandRegistry`に載っている運用コマンドのものだけ(有給付与予定の判定の記録は別経路のため触らない)。
- CLIから直接の作り直しは、記録の`parameters`を`{"dry-run": bool}`(画面からの依頼と同じ)とし、作り直しの実行中・判定の実行中は拒否(終了コード1)。
- 開発用の管理者の部署・役職は「開発部」「システム管理者」(シーダーの値。e2eは参照しない)。
- `attendance:rebuild-calculation-projections`の出力が運用コマンドの画面で空になるのは既存の挙動で、対象外。

### 検証結果
- backend(CIと同じ条件の全件):2198件中2197件PASS。失敗は既存の`ReadModelFingerprintCommandTest::test_fingerprintは1行1テーブル…`の1件(`origin/main`でも失敗)。
- frontend:`vitest --project=unit`全件PASS、lint error無し、tsc(app・e2e)エラー無し、MaintenanceGate/MaintenanceNoticeのstorybookテストPASS。
- e2e(新しいシーダーでDBをリセット):scenario-00・scenario-03・scenario-11・scenario-16の12件PASS(管理者のmock-oidcログインを含む)。
- 実機(e2eの後のデータ):運用コマンドの画面で「データの作り直し(ReadModel)」を試し実行・本実行し、`admin-commands:run-pending`で実行 → どちらも
  `succeeded`、変更のあったテーブル0件。実行中、一般の利用者の画面は「メンテナンス中」になり、終了後`maintenance:false`。管理者は作り直し後も運用コマンドの画面を使えた。

### 受け入れ条件の確認
- 運用コマンドに出て試し実行・本実行を依頼でき約1分以内に始まる:実機・`RunPendingAdminCommandsTest`。
- 60秒を超える運用コマンドが打ち切られない:キューを使わずCLIで実行(`RunAdminCommandJob`は削除)。
- 実行中の503とメンテナンス表示、運用コマンドの画面では進み具合が見られる:実機・`BlockDuringReadModelRebuildTest`。
- 結果の表・警告・summary:`ReadModelRebuildCommandTest`・実機の出力。
- 専用画面・API・権限・監査イベントが無い:削除済み(frontendのgrepで残存0件)。
- 開発環境で作り直しても変わらない:`DevSeedersEventSourcedTest`(changed_tables=0)・実機。
- e2e・backend・frontendのテスト:上記。

### リリース時の注意
- デプロイ時に`jobs`から`RunAdminCommandJob`の行を削除する(docs/27-release-runbook.md 3.4)。
- 既存の開発DBは`migrate:fresh --seed`で作り直す。
