# バックオフィス割当イベントの移行を管理画面の運用コマンドから実行できるようにする

ステータス: 検討中

## 変更要望(原文)
> 管理画面から移行バッチを実行できるようにしてください

## 背景・目的
変更セット`20261122-backoffice-task-reassign`で追加した移行コマンド`backoffice:rebuild-assignment-events`はCLI専用で、
本番(XSERVER)ではSSHで段階的(試し実行→`--apply`→`--verify`→`--swap`→`read-models:rebuild`)に実行する手順だった。
管理画面「運用コマンド」から、試し実行と本実行を安全に行えるようにする。

## 現状(As-Is)
- 運用コマンド: `#[AdminExecutable(label, rules, ui)]`(`backend/app/Console/Attributes/AdminExecutable.php`)を付けたコマンドが
  `AdminCommandRegistry`で一覧化され、管理画面`/admin/commands`(`frontend/src/pages/admin/AdminCommandsPage.tsx`)から依頼できる。
  実行は毎分起動の実行役`admin-commands:run-pending`(`docs/27` 3.4)。画面は`rules`/`ui`から入力欄を作る汎用実装。
- メンテナンス(全API 503): `BlockDuringReadModelRebuild`ミドルウェアが`ReadModelRebuildRunService::isRunning()`(`command_name`=
  `read-models:rebuild`の`running`記録+心拍)のときだけ503にする。`read-models:rebuild`は心拍を書き、60秒(設定値)待ってから作り直す。
- 移行コマンド: `backend/app/Console/Commands/RebuildBackOfficeAssignmentEventsCommand.php`(`--apply`/`--verify`/`--swap`。既定は試し実行)。
  作り直しの表`stored_events_rebuilt`に書き、`swap`で入れ替える(元表は`stored_events_before_rebuild_YYYYMMDDHHMMSS`)。
- `docs/27-release-runbook.md` 3.6: 新コードの配備から入れ替え・確認までを1回のメンテナンスで行う手順。

## 設計前提の検証
- 正データと派生データ: 移行は`stored_events`(正)の作り直しで、ReadModelはその後のリビルドで揃える(設計原則2、`data-correction`)。変更なし。
- 配備から移行までの間の整合: 新コードは旧形式の`assigned`でステータスを変えないが、ReadModelは旧Projectorで作られた正しい値のまま。
  その間に新しく記録される`assigned`は新形式(`previousAssignedUserId`キー付き)で、移行はイベント単位で冪等なので混在しても正しく変換される。
  よって**配備と移行を同じメンテナンスで行う必要はなく**、危険なのは「移行前に`read-models:rebuild`を行うと旧形式の`assigned`の確認中への変化が
  失われること」と「`--apply`後〜`--swap`の間に書かれたイベントが失われること」の2点だけである。
- 原因の分類: 機能追加(実行経路の追加)。
- 横展開: 他の作り直し(休暇の作り直し・有給口座の付け替え)もCLI専用だが、今回の要望の対象外。
- 一覧のページング: 該当なし。

## 仕様検討

### 論点1: 管理画面からの実行単位
- 選択肢:
  - A. 既存の段階(`--apply`/`--verify`/`--swap`)をそれぞれ画面に出す
  - B. 画面からは「試し実行」と「本実行」の2つだけにし、本実行は1回の実行の中で 書き出し→検証→入れ替え→ReadModelの作り直し まで行う
- 決定: B
- 理由: 段階を分けると、`--apply`と`--swap`の間に書かれたイベントが失われる・入れ替え後にReadModelを作り直し忘れる、という手順ミスの余地が残る。
  1回の実行で完結させ、その間だけメンテナンスにすれば安全。CLIの段階的なオプションはリハーサル・障害時の調査用にそのまま残す。
- 未確定・要確認事項: なし

### 論点2: 本実行中のメンテナンス(書き込みの停止)
- 選択肢:
  - A. `ReadModelRebuildRunService`のメンテナンス判定を、`read-models:rebuild`に加えて本移行の本実行の`running`記録でも真にする
    (心拍・60秒待ちも`read-models:rebuild`と同じ)
  - B. 別のミドルウェア・判定を新設する
- 決定: A
- 理由: 503の応答・解除の検知・画面の表示(メンテナンス中の文言)をそのまま使える。判定の対象を「メンテナンスを要する運用コマンド名の一覧」に広げるだけで済む。
  試し実行はDBを変えないのでメンテナンスにしない。
- 未確定・要確認事項: なし

### 論点3: 移行前の`read-models:rebuild`の防止
- 選択肢:
  - A. `read-models:rebuild`(本実行)は、`stored_events`に旧形式の`backoffice_task.assigned`(`previousAssignedUserId`キー無し)が1件でもあれば
    拒否し、「先に『バックオフィス割当イベントの移行』を実行してください」と出す(試し実行は警告のみで実行する)
  - B. 手順書の注意書きだけにする
- 決定: A
- 理由: 管理画面から誰でも(権限者が)`read-models:rebuild`を押せるため、手順書だけではステータスが失われる事故を防げない。
- 未確定・要確認事項: なし

### 論点4: 画面の入力と表示
- 決定:
  - ラベル「バックオフィス割当イベントの移行」。入力はチェックボックス「本実行する(メンテナンス中に移行し、ReadModelを作り直す)」1つ
    (rulesのキー`execute`。未チェック=試し実行)。説明文(`description`)に、本実行中は全利用者がメンテナンス中(全API 503)になること、
    日次バッチの時刻(1:00〜2:20)を避けること、事前に試し実行で件数を確認することを書く。
  - 出力: 試し実行・本実行とも、対象件数・挿入件数・直前ステータス別件数・完了/取消からの変化の集約ID・事前条件・violationsを表示する。本実行はさらに
    検証結果、入れ替え後のバックアップ表名、ReadModelの作り直しの結果(変更のあったテーブル)を表示し、記録の`summary`にも残す。
  - 対象0件なら本実行でも何もしない(メンテナンスにもしない)。violations・事前条件違反があれば何もせず失敗にする。
- 未確定・要確認事項: なし

### 論点5: 失敗時の扱い
- 決定:
  - 書き出し・検証で失敗したら入れ替えずに終了し、`stored_events_rebuilt`は削除する(次回の事前条件違反を残さない)。`stored_events`は変わらない。
  - 入れ替え後のReadModelの作り直しで失敗した場合は、`stored_events`は移行済みのまま失敗を記録し、出力に「『データの作り直し(ReadModel)』を
    実行してください」とバックアップ表名を出す(移行済みなので論点3の拒否には当たらない)。
- 理由: 入れ替え(DDL)はトランザクションで戻せないため、入れ替え前に検証を完了させ、入れ替え後の失敗は再実行可能な作り直しで回復させる。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend
  - `RebuildBackOfficeAssignmentEventsCommand`に`{--execute}`を追加し、`#[AdminExecutable(label: 'バックオフィス割当イベントの移行',
    rules: ['execute' => ['nullable', 'boolean']], ui: ['execute' => ['control' => 'checkbox']])]`を付ける。既存の`--apply`/`--verify`/`--swap`はCLI用に残す
    (運用コマンドの画面には出さない。`--execute`と同時指定は拒否)。
  - `--execute`の流れ: 試し実行と同じ集計 → 対象0件なら終了 → violations・事前条件違反なら失敗 → メンテナンス開始(実行記録`running`+心拍、
    `config('read_model_rebuild.claim_wait_seconds')`秒待つ) → split→write(`stored_events_rebuilt`)→Verifier→swap→`ReadModelRebuilder::rebuild(false)`
    → 心拍の解除。画面から依頼された場合は`CurrentAdminCommandRun`の記録を、CLIから直接の場合は`ReadModelRebuildRunService`と同様に記録を作って使う
    (`RebuildReadModelsCommand`の書き方を写す)。
  - `ReadModelRebuildRunService::isRunning()`の判定対象を、`read-models:rebuild`と`backoffice:rebuild-assignment-events`(`parameters.execute`が真の記録)に広げる。
    同時実行の排他(`LOCK_NAME`)も共通にし、どちらかの実行中はもう一方を開始しない。
  - 失敗時(論点5): 入れ替え前の失敗は`stored_events_rebuilt`を削除して終了。入れ替え後のReadModel作り直しの失敗は失敗として記録し案内を出力。
  - `RebuildReadModelsCommand`(論点3): 本実行の前に旧形式の`backoffice_task.assigned`の件数を数え、1件以上なら拒否(終了コード1、記録は失敗)。試し実行は警告のみ。
    件数の判定は`BackOfficeTaskAssignmentSplitter`側に静的な判定(例: `countLegacyAssigned(): int`をクエリで行う小さなクラス)を置き、両コマンドで使う。
- frontend: 変更なし(運用コマンドの画面は`rules`/`ui`から汎用に描画する)。表示を確認し、崩れる場合のみ対応する。
- テスト(Feature):
  - 運用コマンドの一覧に出る(`rules`に`execute`)。
  - `--execute`なし=試し実行でDB不変。
  - `--execute`: 旧形式の行を投入→実行→`stored_events`が新構成・旧形式0件・`backoffice_tasks`が移行前と一致・`*_history_entries.stored_event_id`が新id・
    `stored_events_rebuilt`が残らない・バックアップ表がある。
  - `--execute`の実行中は`isRunning()`が真(心拍)。終了後は偽。
  - 対象0件の`--execute`は何もせずメンテナンスにもならない。violationsで失敗し`stored_events`不変。
  - 検証失敗(テストでVerifierを差し替え)で入れ替えず、`stored_events_rebuilt`が削除され`stored_events`不変。
  - `read-models:rebuild`本実行は旧形式があると拒否、試し実行は警告のみで成功。移行後は通常どおり実行できる。
  - `read-models:rebuild`実行中は移行の本実行を開始できない(逆も)。
  - 既存の`ReadModelRebuildCommandTest`・移行のシナリオテストが通る。

## 受け入れ条件
- 管理画面「運用コマンド」に「バックオフィス割当イベントの移行」が表示され、試し実行で件数を確認できる。
- 本実行で、メンテナンス(全API 503)の間に移行とReadModelの作り直しが完了し、`backoffice_tasks`が移行前と一致する。
- 移行前に「データの作り直し(ReadModel)」を本実行しようとすると拒否される。
- 関連テストがPASSする。

## 対象外
- 休暇の作り直し・有給口座の付け替えの運用コマンド化
- 移行コマンドの削除(本番実行後の別変更)

## ドキュメントへの影響
- `docs/27-release-runbook.md` 3.6: 配備と移行を同じメンテナンスで行う手順から、「配備→(業務時間外に)管理画面で試し実行→本実行」に改める。移行前の
  `read-models:rebuild`は拒否される旨。
- `docs/32-stored-event-history-normalization.md`: 実行経路(運用コマンド)を追記。
- `docs/changesets/20261122-backoffice-task-reassign/spec.md`: 論点6の「実行のタイミング」に本変更セットへの参照を追記。
- `docs/16`・`docs/17`: 変更なし。

## モック・アセット
なし(既存の運用コマンド画面にコマンドが1つ増えるのみ)

## 実装対象
- backend: `app/Console/Commands/RebuildBackOfficeAssignmentEventsCommand.php`、`app/Console/Commands/RebuildReadModelsCommand.php`、
  `app/Services/EventSourcing/ReadModelRebuildRunService.php`、`app/Domain/EventSourcing/Rebuild/`(旧形式の件数の判定)、`tests/Feature/`
- docs: 上記

## 検証方法
- `cd backend && ./vendor/bin/phpunit --filter='BackOfficeAssignment|ReadModelRebuild|AdminCommand'`、CI同条件の全件`php artisan test`

## レビュー履歴
初版

## 実装結果
未着手
