# バックオフィス割当イベントの移行を管理画面の運用コマンドから実行できるようにする

ステータス: 完了

## 変更要望(原文)
> 管理画面から移行バッチを実行できるようにしてください

## 背景・目的
変更セット`20261122-backoffice-task-reassign`で追加した移行コマンド`backoffice:rebuild-assignment-events`はCLI専用で、
本番(XSERVER)ではSSHで段階的(試し実行→`--apply`→`--verify`→`--swap`→`read-models:rebuild`)に実行する手順だった。
管理画面「運用コマンド」から、試し実行と本実行を安全に行えるようにする。

## 現状(As-Is)
- 運用コマンド: `#[AdminExecutable(label, rules, ui)]`を付けたコマンドが`AdminCommandRegistry`で一覧化され、管理画面`/admin/commands`から依頼できる。
  **レジストリはコマンドの全オプションを画面に出し**(`AdminCommandRegistry.php`:288-299)、値を取らないオプションはチェックボックスになる。
  実行は毎分起動の実行役`admin-commands:run-pending`で、実行役はコマンドを呼ぶ前に記録を`running`にする(`AdminCommandRunner.php`:103)。
  排他の期限は240分(`routes/console.php`:122-126)。画面の履歴は`error_message ?? output`を表示し、`summary`は表示しない。
- メンテナンス(全API 503): `BlockDuringReadModelRebuild`が`ReadModelRebuildRunService::isRunning()`(`command_name`=`read-models:rebuild`の
  `running`記録が心拍または`started_at`で10分以内)のとき503にする。実行役は依頼を`created_at`順に拾い、有給付与予定の判定の実行中は
  作り直しの依頼を後回しにする(`nextQueuedRun`:56-63)。スケジューラーは作り直し中に他のスケジュールを飛ばす(`routes/console.php`:17,21)。
  CLIから直接の作り直しは`startDirectRun`(:31-39。記録のコマンド名は固定)が判定中なら開始しない。
- `ReadModelRebuilder::rebuild($dryRun)`: 全ReadModelを1つのトランザクションで空にして全Projectorをreplay。外側のトランザクションがあればその中で動く
  (`DB::transactionLevel()`を見る)。試し実行は最後にロールバック。心拍(`Cache::store('file')`)と時間の上限(180分)を持つ。
- 移行: `BackOfficeTaskAssignmentSplitter`(純粋変換。挿入があると全行のidを1から振り直す。**不具合: 旧形式があっても挿入が0件
  (直前が全てin_review)だと変換前の行を返し、`previousAssignedUserId`が付かない**(:129-131))・`BackOfficeTaskAssignmentRebuildVerifier`・
  `RebuildBackOfficeAssignmentEventsCommand`(`stored_events_rebuilt`に書いて`swap`で入れ替える段階的なCLI)。
- `stored_events.id`を参照するもの: Projectionの`*_history_entries.stored_event_id`等(リビルドで直る)、補正ログ`stored_event_corrections.stored_event_id`と
  metaの`history-normalization.source_event_id`(リビルドで直らない)。
- `projections:rebuild {projector?}`(`RebuildProjectionsCommand`)でもBackOfficeTaskProjectorを再生できる。

## 設計前提の検証
- 正データと派生データ: 移行は`stored_events`(正)の作り直しで、ReadModelはリビルドで揃える(設計原則2、`data-correction`)。
- 配備から移行までの整合: 新コードは旧形式の`assigned`でステータスを変えないが、ReadModelは旧Projectorで作った正しい値のまま。配備後に記録される
  `assigned`は新形式で、Splitterはイベント単位で冪等なので混在しても正しく変換される。危険なのは(1)移行前にReadModelをリビルドすること、
  (2)作り直しの表への書き出し〜入れ替えの間の書き込みが失われること、(3)入れ替え後にReadModelのリビルドが終わらないまま運用が再開すること(idの振り直しで
  `stored_event_id`が別のイベントを指す)。
- 段階的な方式(作り直しの表→swap→リビルド)を画面から行うと、上の(2)(3)を防ぐためにメンテナンスの開始・維持・回復の仕組みを新しく作る必要があり、
  DDL(swap)はトランザクションで戻せない(設計レビューで判明)。
- 原因の分類: 機能追加(実行経路の追加)。前版の設計(段階的な方式をそのまま画面に出す)は、上記の理由で作り直した。
- 横展開: 休暇の作り直し・有給口座の付け替えもCLI専用だが対象外。
- 一覧のページング: 該当なし。

## 仕様検討

### 論点1: 管理画面からの実行方式
- 選択肢:
  - A. 段階的な方式(`--apply`/`--verify`/`--swap`)を画面から実行し、メンテナンスの開始・維持・回復を新しく作る
  - B. 移行(`stored_events`の書き換え)とReadModelの作り直しを**1つのトランザクション**で行う運用コマンドにする。トランザクションの前にバックアップ表を作る。
    メンテナンス・心拍・排他・有給判定との調整・試し実行(ロールバック)は`read-models:rebuild`の仕組みをそのまま使う
- 決定: B
- 理由: 失敗・プロセス停止時はトランザクションごと戻るため、`stored_events`とReadModelがずれた状態が生じない。新しい回復手順が要らない。
  試し実行も本実行と同じ処理を最後にロールバックするだけで、実際の差分(移行の件数とReadModelの変化)をそのまま確かめられる。
- `data-correction`の「新しい表に書いてから入れ替える」との違い: 入れ替え(DDL)の代わりに、トランザクション内で`stored_events`を書き換え、
  コミット前に検証(バックアップ表との比較)を行う。元の表はトランザクション前にバックアップ表として残すので、「検証してから反映」「元の表を残す」という
  規定の目的は満たす。ReadModelの作り直しと同じトランザクションにまとめるための方式で、`data-correction`に選択肢として追記する。
- 未確定・要確認事項: なし

### 論点2: コマンドの構成
- 選択肢:
  - A. 既存の`backoffice:rebuild-assignment-events`に画面用のオプションを足す
  - B. 画面用の新しいコマンドを作り、段階的なCLIコマンドは削除する
- 決定: B。新しいコマンド`backoffice:migrate-assignment-events {--dry-run}`(ラベル「バックオフィス割当イベントの移行」)。
  旧`RebuildBackOfficeAssignmentEventsCommand`とそのテスト・`docs/27` 3.6の段階的な手順は削除する(`Splitter`・`Verifier`は再利用する)。
- 理由: レジストリは全オプションを画面に出すため、既存コマンドに足すと`--swap`等も画面から押せてしまう。移行の経路を1つにして手順の取り違えを防ぐ。
  画面の入力は`read-models:rebuild`と同じ「試し実行」チェックボックス1つにそろえる。
- 未確定・要確認事項: なし

### 論点3: メンテナンスと排他
- 決定: `ReadModelRebuildRunService`・`AdminCommandRunner::nextQueuedRun`・スケジューラーの「作り直し中」判定・有給判定との調整・`LOCK_NAME`の
  対象を、コマンド名1つから**メンテナンスを要するコマンド名の一覧**(`read-models:rebuild`と`backoffice:migrate-assignment-events`)に広げる。
  記録の取得は`command_name IN (...)`と`status='running'`で行い、JSON式は使わない。変更箇所は`ReadModelRebuildRunService`(`isRunning`の
  取得条件、`startDirectRun`の記録するコマンド名を引数化)、`AdminCommandRunner::nextQueuedRun`(:62 判定中に後回しにするコマンド名)、
  `routes/console.php`はコメントのみ。相互排他の判定(もう一方が実行中か)は`LOCK_NAME`のロックの中で行う(実行役から呼ばれた場合も)。試し実行も`read-models:rebuild`と同じく実行中はメンテナンスにする
  (試し実行もReadModelを空にして作り直すため)。どちらかの実行中はもう一方を開始しない(自分の記録は除外して判定)。
- 理由: 既存の仕組みに乗せれば、拾われた時点からの503、心拍、有給判定の後回し、他スケジュールの停止がそのまま効く。
- 未確定・要確認事項: なし

### 論点4: 処理の流れ
- 決定(`backoffice:migrate-assignment-events`):
  1. 心拍を書き、`read_model_rebuild.claim_wait_seconds`秒待つ(`read-models:rebuild`と同じ)。
  2. 旧形式の件数を数える(`LegacyBackOfficeAssignmentDetector`)。0件ならReadModelを作り直さず「対象なし」で成功。
  3. 本実行のみ: トランザクションの前に、`CREATE TABLE <backup> LIKE stored_events`と`INSERT INTO <backup> SELECT * FROM stored_events`で
     バックアップ表`stored_events_before_rebuild_YYYYMMDDHHMMSS`を作る(主キー・索引を持たせる。CTASは使わない)。
  4. トランザクション開始 → 手順2の読み込みと変換をこのトランザクションの中で行う(読み込みから削除までの間の書き込みを取りこぼさない) →
     バックアップ表と`stored_events`の件数・最大idが一致することを確かめ、違えば中止(本実行のみ) → `stored_events`の全行を削除し、変換後の行を入れる → 補正ログ`stored_event_corrections.stored_event_id`とmetaの
     `history-normalization.source_event_id`を旧id→新idの対応で付け替える(補正ログは`unique(correction_key, stored_event_id)`があるため、
     いったん全行を`stored_event_id + (最大id+1)`へ逃がしてから新idに更新する2段階。`aggregate_version`列も新しい版に更新。対応の無いid
     (補正で削除されたイベントを指す行)は変更せず件数を出力する) → violationsがあればロールバックして失敗 → Verifier(変換後の`stored_events`とバックアップ表(試し実行は変換前の行)を比較) →
     `ReadModelRebuilder::rebuild($dryRun)`(外側のトランザクション内で動く) → 本実行はコミット、試し実行はロールバック。
  5. 時間の上限は`ReadModelRebuilder`と同じ設定を、手順1からの合計で適用する(`ReadModelRebuilder::rebuild($dryRun, ?float $startedAt = null)`に変更)。
     各段階の間と行の書き込みのチャンクごとに心拍を書く。書き込みが終わった行の配列は`unset`する。
  6. トランザクションの中ではDDL(CREATE/ALTER/DROP/RENAME/TRUNCATE)を発行しない(MySQLの暗黙コミットで1トランザクションが崩れるため)。
- 未確定・要確認事項: なし

### 論点5: 移行前のReadModelのリビルドの防止
- 決定: `read-models:rebuild`と`projections:rebuild`は、本実行の前に旧形式の`backoffice_task.assigned`(payloadに`previousAssignedUserId`キーが無い)が
  1件でもあれば拒否し、「先に運用コマンド『バックオフィス割当イベントの移行』を実行してください」と出す。`read-models:rebuild`の試し実行は警告のみ。
  件数は`event_class='backoffice_task.assigned'`の行だけを読み、PHPで`array_key_exists`する(DBの違いに依存しない)。判定は小さなクラス
  `App\Domain\EventSourcing\Rebuild\LegacyBackOfficeAssignmentDetector`に置き、3コマンドで使う。
- 理由: 管理画面から`read-models:rebuild`を押されるとステータスが失われるため。`event-sourcing:replay`(パッケージのコマンド)は対象外とし、手順書に注意を書く。
- 未確定・要確認事項: なし

### 論点6: 画面の表示
- 決定: 説明文(`description`)に、実行中は全利用者がメンテナンス中(全API 503)になること、日次バッチの時刻(1:00〜2:20)を避けること、
  先に試し実行で確かめることを書く。出力(`output`)に、対象件数・挿入件数・直前ステータス別件数・完了/取消からの変化の集約ID・付け替えた補正ログの件数・
  バックアップ表名(本実行)・ReadModelの変更のあったテーブル(`read-models:rebuild`と同じ表)を出す。失敗時の`error_message`には原因と、
  何も変わっていない(ロールバックした)ことを書く。`summary`にも同じ要約を残す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend
  - 新規`app/Console/Commands/MigrateBackOfficeAssignmentEventsCommand.php`: `backoffice:migrate-assignment-events {--dry-run}`、
    `#[AdminExecutable(label: 'バックオフィス割当イベントの移行')]`(オプションは`--dry-run`だけ)。論点4の流れ。画面から依頼された場合は`CurrentAdminCommandRun`、
    CLIから直接の場合は`ReadModelRebuildRunService::startDirectRun`相当で記録を作る(`RebuildReadModelsCommand`の書き方を写す)。
  - 新規`app/Domain/EventSourcing/Rebuild/BackOfficeAssignmentMigrator.php`: 論点4の手順2〜4(読み込み・変換・バックアップ・書き換え・id参照の付け替え・検証・
    `ReadModelRebuilder::rebuild`呼び出し)をまとめる。結果は要約の配列。
  - `BackOfficeTaskAssignmentSplitter`: 不具合修正(旧形式があり挿入0件でも変換後の`$out`を返す。「対象なし」は`targetCount===0`)。
    旧id→新id・新しい版の対応を結果に含め、metaの`history-normalization.source_event_id`も付け替える。
  - `ReadModelRebuilder::rebuild($dryRun, ?float $startedAt = null)`(論点4の5)。
  - `BackOfficeTaskAssignmentRebuildVerifier`: 比較する2つの表名(または行配列)を引数で受け取れるようにする。
  - 新規`LegacyBackOfficeAssignmentDetector`(論点5)。`RebuildReadModelsCommand`・`RebuildProjectionsCommand`に拒否を追加。
  - `ReadModelRebuildRunService`・`AdminCommandRunner`・`routes/console.php`: 論点3のコマンド名の一覧化。
  - 削除: `RebuildBackOfficeAssignmentEventsCommand.php`とそのコマンド用テスト(Splitter・Verifierの単体テストは残す)。
- テスト(Feature):
  - 運用コマンドの一覧に出て、オプションは`dry-run`だけ。
  - 試し実行: 旧形式を投入→実行→`stored_events`・ReadModel・補正ログが不変、出力に件数とReadModelの差分。
  - 本実行: `stored_events`が新構成・旧形式0件・idと版が連番、`backoffice_tasks`が移行前と一致、`*_history_entries.stored_event_id`が新id、
    補正ログとmetaの`source_event_id`が新idを指す、バックアップ表がある。
  - 失敗時のロールバック: ReadModelの作り直しで例外(テストで差し替え)→`stored_events`・ReadModel・補正ログが元のまま(バックアップ表は残る)。
  - violationsで何もせず失敗。対象0件は何も変えず成功。
  - 実行中は`isRunning()`が真、`read-models:rebuild`と互いに開始できない、有給判定の実行中は開始しない。
  - `read-models:rebuild`本実行・`projections:rebuild`は旧形式があると拒否、`read-models:rebuild`の試し実行は警告のみ。移行後は通常どおり実行できる。
    新形式(`previousAssignedUserId: null`)を旧形式と数えない。
  - Splitter単体: 旧形式があり直前が全てin_review(挿入0件)でも`previousAssignedUserId`が付く。
  - 補正ログの付け替え: 欠番のあるidで`unique(correction_key, stored_event_id)`に衝突せず新idになる、`aggregate_version`も新しい版、対応の無い行は不変。
  - 暗黙コミットの防止: `DB::listen`で、トランザクション中にCREATE/ALTER/DROP/RENAME/TRUNCATEが発行されないこと(テストはSQLiteのため)。
  - 既存の`ReadModelRebuildCommandTest`・移行のシナリオテスト(コマンドを新しいものへ書き換え)が通る。

## 受け入れ条件
- 管理画面「運用コマンド」に「バックオフィス割当イベントの移行」が表示され、試し実行で件数とReadModelの差分を確認できる。
- 本実行で、メンテナンス(全API 503)の間に移行とReadModelの作り直しが1つのトランザクションで完了し、`backoffice_tasks`が移行前と一致する。
  途中で失敗しても`stored_events`・ReadModelは元のまま。
- 移行前に「データの作り直し(ReadModel)」の本実行と`projections:rebuild`は拒否される。
- 関連テストがPASSする。

## 対象外
- 休暇の作り直し・有給口座の付け替えの運用コマンド化
- 移行コマンドの削除(本番実行後の別変更)
- `event-sourcing:replay`(パッケージのコマンド)での拒否

## ドキュメントへの影響
- `docs/27-release-runbook.md` 3.6: 段階的な手順を削除し、「配備→業務時間外に管理画面で試し実行→結果をユーザーに確認→本実行」に改める。
  本番の件数でのメモリ・所要時間の見積もりをリハーサルで行うこと、移行前の`read-models:rebuild`・`projections:rebuild`は拒否されること、
  `event-sourcing:replay`を使わないこと、リハーサル(MySQL)でピークメモリ・`max_binlog_cache_size`・undoの増加・最長の単一SQL(バックアップの
  `INSERT ... SELECT`、全削除)の所要時間(心拍が途切れる10分の目安を超えないこと)を測ること、失敗時に残ったバックアップ表の片付け、実行結果(件数・バックアップ表名)を変更セット`20261122-backoffice-task-reassign`の実装結果と`docs/32`に記録することを書く。
- `docs/32-stored-event-history-normalization.md`: 実行経路(運用コマンド、1トランザクション)に書き換え、補正ログ・metaのidの付け替えを追記。
- `.claude/skills/data-correction/SKILL.md`: 「ReadModelの作り直しと同じトランザクションで`stored_events`を書き換える方式(事前にバックアップ表、コミット前に検証)」を
  作り直しの選択肢として追記。
- `docs/changesets/20261122-backoffice-task-reassign/spec.md`: 論点6の実行方法に本変更セットへの参照を追記。
- `docs/16`・`docs/17`: 変更なし。

## モック・アセット
なし(既存の運用コマンド画面にコマンドが1つ増えるのみ。frontendの変更なし)

## 実装対象
- backend: 上記の新規・変更ファイル、`tests/Feature/`
- docs: 上記

## 検証方法
- `cd backend && ./vendor/bin/phpunit --filter='BackOffice|ReadModelRebuild|AdminCommand|RebuildProjections'`、CI同条件の全件`php artisan test`

## レビュー履歴
- 初版(2026-11-22)
- 2026-11-22 独立設計レビュー(general-purpose)で、段階的な方式を画面から行う設計に重大な問題(レジストリが全オプションを画面に出し`--swap`を単独で押せる、
  拾われた時点でメンテナンスになる、書き出し中の心拍が無い、有給判定との調整漏れ、swap後の失敗でReadModelとずれたまま運用再開、240分の期限、
  JSON式のDB差、補正ログのid参照、`projections:rebuild`の経路)が判明したため、「設計前提の検証」から作り直した。移行とReadModelの作り直しを
  1トランザクションで行う新しいコマンドにし、メンテナンス等は`read-models:rebuild`の仕組みを一覧化して共有、段階的なCLIは削除する。
- 2026-11-22 第2版の独立設計レビューの指摘を反映: Splitterの不具合(挿入0件で変換前を返す)の修正を追加/読み込み・変換をトランザクション内へ移し
  バックアップとの件数・最大idを照合/バックアップは`CREATE TABLE LIKE`+`INSERT SELECT`/補正ログの2段階付け替えと`aggregate_version`・対応無しの扱い/
  `rebuild($dryRun, $startedAt)`/一覧化の変更箇所とAs-Isの訂正、相互排他をロック内で/DDL不発行のテスト/リハーサルの測定項目。

## 実装結果
- コミット: `1252456`(backend)、docs は同ブランチの「docs: 割当イベント移行を運用コマンド(1トランザクション)で行う手順に更新」。
- backend: 新規`MigrateBackOfficeAssignmentEventsCommand`(`backoffice:migrate-assignment-events {--dry-run}`、`#[AdminExecutable]`)、
  `BackOfficeAssignmentMigrator`、`LegacyBackOfficeAssignmentDetector`。変更: `BackOfficeTaskAssignmentSplitter`(挿入0件の不具合修正、idMap・versionMap、
  meta`source_event_id`の付け替え)・`SplitResult`・`RebuildVerifier`(行配列を比較元に)、`ReadModelRebuilder::rebuild($dryRun, $startedAt)`、
  `ReadModelRebuildRunService`(`MAINTENANCE_COMMANDS`、`startDirectRun`のコマンド名引数化、ロック内の相互排他)、`AdminCommandRunner::nextQueuedRun`、
  `RebuildReadModelsCommand`・`RebuildProjectionsCommand`(旧形式があれば拒否)、`routes/console.php`(コメント)。削除: `RebuildBackOfficeAssignmentEventsCommand`。
- テスト: `BackOfficeAssignmentEventRebuildScenarioTest`(新コマンド向けに書き換え、13件)、`LegacyBackOfficeAssignmentGuardTest`(新規7件)、Splitter・Verifier・AdminCommandTestを更新。
- 実装上の判断: 旧形式が1件でもあれば挿入0件でも全行のidを振り直す(Verifierがidの連番を要求するため)。violationsは本実行ではバックアップ表の作成後に
  検出されるため、その場合はバックアップ表が残る(`error_message`に案内)。FK検査はトランザクション開始前に無効化し`finally`で戻す。
- docs: `docs/27-release-runbook.md` 3.6、`docs/32-stored-event-history-normalization.md`、`.claude/skills/data-correction/SKILL.md`、
  変更セット`20261122-backoffice-task-reassign`への追記(「ドキュメントへの影響」の全件を更新済み)。
- 検証結果:
  - 委譲元で再実行: `./vendor/bin/phpunit --filter='BackOffice|ReadModelRebuild|AdminCommand|RebuildProjections|Splitter|LegacyBackOffice'` 145件pass(APP_KEY設定時。
    未設定では既存の`ExpenseExternalApiPublishTest` 4件がAPP_KEY不足でエラーになる既知の環境要因)。
  - implementer実行: CI同条件の`php artisan test`全件 2347件pass。
  - MySQLでの`CREATE TABLE LIKE`・`INSERT ... SELECT`と所要時間・メモリは未確認(テストはSQLite)。本番前のリハーサルで確認する(`docs/27` 3.6 手順0)。
- 受け入れ条件: 運用コマンドの一覧への表示・オプション(GuardTest)、試し実行で不変かつ差分表示・本実行で新構成と`backoffice_tasks`の一致・失敗時ロールバック(シナリオテスト)、
  移行前の`read-models:rebuild`本実行と`projections:rebuild`の拒否(GuardTest)を確認。
