# remove-finished-migration-commands(本番で実行済みの移行用コマンドの削除)

ステータス: 完了

## 変更要望(原文)
> それからすでに不要となった過去の移行用のコマンドは削除してもらえますか？
> デプロイしていない移行用コマンドは消さないように注意してください。おそらく画面から実行できないコマンドなどです。
> events:rename-boolean-propertiesも実行できていないと思います。

## 棚卸し(2026-11-24、investigator)
`app/Console/Commands` のコマンドは33件ある。
- 定期実行・運用・開発用の20件と、判断を保留した3件(`paid-leave:schedule:reapply-policy`・`paid-leave:schedule:rebuild`・`access-control:resync-group-features`)は残す。
- リリース待ちの `events:rename-boolean-properties`・`events:fix-calendar-bulk-operation-target-ids`(本番未実行)は残す。計画中の `events:fix-user-settings-at-replay`・`events:fix-rebuild-determinism` も同じ。

過去の移行・補正のコマンドは8件ある。本番で実行済みかは、ユーザーの回答(2026-11-24)で決めた。

| コマンド | 管理画面 | 本番の実行 | 決定 |
|---|---|---|---|
| `leave:rebuild-event-store` | 不可 | 実行済み(変更セット 20261021-paid-leave-aggregate-stream-id の記録と、ユーザーの回答) | 削除。移行前のバックアップから作り直す手順(docs/32)も、ユーザーの判断で捨てる |
| `events:normalize-history` | 不可 | 実行済み(ユーザーの回答) | 削除 |
| `backoffice:migrate-assignment-events` | 可 | 実行済み(ユーザーの回答。変更セット 20261122-backoffice-task-reassign の「未実施」の記録は古い) | 削除 |
| `leave:rekey-paid-leave-streams` | 不可 | 実行済み(ユーザーの回答。変更セット 20261021-paid-leave-aggregate-stream-id の「未実施」の記録は古い) | 削除 |
| `attendance:migrate-work-classifications` と、中で呼ぶ `attendance:normalize-calculation-events` | 可 | 実行済み(ユーザーの回答) | 削除 |
| `events:backfill-attendance-backoffice-tasks` | 不可 | 記録なし。ユーザーも実行済みとしなかった | 残す(未デプロイの可能性) |
| `attendance:repair-cancelled-months` | 不可 | 記録なし。ユーザーも実行済みとしなかった | 残す(未デプロイの可能性) |

## 仕様検討

### 論点1: 消すものと残すもの
- 決定: 上の表で「削除」とした7コマンドと、それぞれだけが使うクラス・テストを削除する。
  - 例: `EventStoreRebuilder`、`Reconcile/*`(`UserReconciler` ほか)、休暇・勤怠・テナントID・祝日の各 Rebuilder、`StoredEventHistoryNormalizer`、`BackOfficeAssignmentMigrator`・`Splitter`・`Verifier`、`PaidLeaveStreamRekeyVerifier`・`Result`。
  - 実装時に、各クラスが他から使われていないことを grep で確かめ、消したものを一覧で記録する。
- 残す: 他の作業と共有している部品。
  - `ReadModelRebuilder`・`ReadModelFingerprinter`・`ReadModelDiffer`・`ReadModelTableLister`
  - `EventRestorabilityChecker`
  - `StoredEventCorrector` と `stored_event_corrections`
  - 残すコマンドが使うクラス(`AttendanceBackOfficeTaskHistoryBackfiller`)
  - リリース待ちの補正の部品
  - `PaidLeaveStreamRekeyer`・`EventStoreRebuildWriter`: 消すコマンドだけが使うなら消す。他に使う所があれば残す。
- 旧形式の検出器(`LegacyBackOfficeAssignmentDetector`):
  - 対応する移行コマンドと一緒に削除し、作り直しの拒否からも外す。
  - 理由: 本番では移行が済んでいる。移行前のバックアップから作り直す手順も捨てるので、旧形式は戻らない。検出しても直すコマンドが無ければ、作り直しが止まったままになる。
  - 開発・ステージングの古い DB は `migrate:fresh --seed` で作り直す(backend/CLAUDE.md に既に記載)。
- 残すもの(データ・設定):
  - migration ファイル(本番で適用済み)
  - 表(`legacy_stored_events`、バックアップ表)
  - `config/event-sourcing.php` の旧イベントの別名(DB に旧名の行が残りうるため。`EventRestorabilityChecker` が全行を検査する)
- 未確定・要確認事項: なし

### 論点2: 参照の更新
- `ReadModelRebuildRunService::MAINTENANCE_COMMANDS` から、削除したコマンドを外す。
- 管理画面の運用コマンドの一覧と、そのテスト(`AdminCommandTest`)を更新する。
- `routes/console.php` のコメントを更新する。
- docs:
  - docs/27 の §3.1(休暇の作り直し)・§3.2(付け替え)・§3.6(担当の移行): 実行済みとして手順を「実施済み(コマンドは削除済み)」に置き換える。
  - docs/32: 正規化の手順と「古いバックアップから作り直す場合」の記述を、実施済み・手順の廃止に直す。
  - docs/16・docs/17・docs/29: 削除したコマンドへの言及を直す。
- 変更セットの記録: 20261122-backoffice-task-reassign・20261021-paid-leave-aggregate-stream-id の「未実施」の記録に、本番で実行済み(ユーザーの回答、2026-11-24)を追記する。
- 未確定・要確認事項: なし

### 論点3: 他の変更セットとの関係
- `20261124-user-projection-settings-at-replay` の論点1は、古い移行の道具(`UserReconciler`・`EventStoreRebuildWriter`)にもタイムゾーンを入れるとしていた。本変更セットでそれらを削除するので、その項目は不要になる。向こうの spec に追記する。
- 順序: `20261124-user-projection-settings-at-replay` の実装の後に行う(同じテストに触れるため)。
- 中間版(改名前のコード `cd1d40ce` に修正を載せたもの)には載せない。削除はリリースの前提ではないため。
- 未確定・要確認事項: なし

## 受け入れ条件
- 7コマンドと専用のクラス・テストが無い。残す部品は残っている。
- 次の箇所に、削除したコマンドの名前が残っていない(変更セットの過去の記録を除く): `php artisan list`・管理画面の運用コマンドの一覧・MAINTENANCE_COMMANDS・docs/27・docs/32。
- `php artisan test` の全件が通る。

## 対象外
- 残すと決めたコマンド(上の棚卸し)、migration・表・旧イベントの別名の削除。

## ドキュメントへの影響
- 論点2のとおり。

## 実装対象
1. コマンド・専用クラス・テストの削除と、参照の更新。
2. docs の更新(別のコミット)。

## 検証方法
- `php artisan test` の全件
- `php artisan list` と grep

## レビュー履歴

## 実装結果

コミット: `a6b59316`(ファイルの削除。委譲先のステージが docs のコミットに混ざった)、`4f9e755b`(参照の更新)、最終コミット(docs)。

- 削除したコマンドは6件(`leave:rebuild-event-store`・`events:normalize-history`・`backoffice:migrate-assignment-events`・`leave:rekey-paid-leave-streams`・`attendance:migrate-work-classifications`・`attendance:normalize-calculation-events`)。当初「7件」としたのは数え違い。
- 専用のクラス約50(`EventStoreRebuilder`・`Reconcile/*`・各 Rebuilder・`StoredEventHistoryNormalizer`・`LegacyBackOfficeAssignmentDetector` ほか)と、テスト26件を削除した。
- `EventStoreRebuildWriter` は、命名統一の補正が使う `write()` だけを残した。
- 残したもの:
  - `ReadModelRebuilder`・`ReadModelFingerprinter`・`ReadModelDiffer`・`ReadModelTableLister`・`EventRestorabilityChecker`・`LeaveUsageId`
  - リリース待ちの補正の部品
  - `AttendanceBackOfficeTaskHistoryBackfiller`
  - 旧イベントの別名、migration、表
- ReadModel の指紋を CLI で出す手段(`leave:rebuild-event-store --readmodel-fingerprint`)も無くなった。
- テスト: 全件 2172 件成功(委譲元で再実行)。`php artisan list` に削除したコマンドが出ないことを確かめた。
- docs:
  - docs/27 §3.1・§3.2・§3.6、docs/32、docs/16・17・29 を「実施済み・削除済み」に直した。
  - 過去の変更セット2つの「未実施」に、本番で実行済みを追記した。
  - docs/09 と data-correction スキルには、過去の経緯・前例として名前が残る。

