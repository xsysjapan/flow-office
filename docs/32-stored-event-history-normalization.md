# StoredEvent 履歴再構成手順

## 目的

2026-08-10 に取得した本番エクスポートを基準に、旧移行処理が作ったイベントと、現在の
ドメインモデルで意味が変わったイベント列を補正する。監査ログの保存・検索先は従来どおり
Spatie の `stored_events` であり、検索用の別Projectionは作らない。

SQLエクスポート原本は変更しない。補正は復元先DBに対して
`php artisan events:normalize-history` を実行して行う。

## 本番エクスポートの棚卸し結果

- `stored_events`: 788件
- 旧カットオーバー由来のメタデータを持つイベント: 113件
- 廃止済みユーザーロールイベント: 12件
- 月次勤怠と統合申請を対応付けられる申請: 4件
- `legacy_stored_events`: 1件（`export.created`）
- 未登録のイベント別名: 0件

個人名、メールアドレス、外部ID、認証設定は棚卸し結果へ出力しない。

旧カットオーバー由来というメタデータだけを理由に削除はしない。`attendance_day.*` 60件、
`attendance_punch.*` 32件、入社日5件・ログイン8件、勤務カレンダー・勤務形態3件は、現在のイベント別名と
payloadへ既に変換されており、現行クラスでデシリアライズでき、表す業務事実も変わっていないため保持する。
一方、現在と状態遷移が異なる旧ユーザーロールと月次勤怠申請は下記規則で再構成する。

## 補正規則

### ユーザー登録とグループ所属

`user.migrated_from_legacy` は、登録時の履歴から次の現在イベントへ置換する。

- 初回SSOログインと一致する場合: `user.created_from_sso_login`
- 初期管理者の場合: `user.onboarded_as_admin`
- それ以外のMicrosoft 365由来ユーザー: `user.synced_from_ms365`

`user.roles_changed` と `user.roles_migrated_from_legacy` は削除し、同じ業務事実を次へ変換する。

- 全ユーザー: 登録日時の `membership.added` → `ALL_USERS`
- `admin`: `SYSTEM_ADMINISTRATORS` の所属追加・解除
- `hr_staff`: `HUMAN_RESOURCES_USERS` の所属追加・解除
- `backoffice_staff`: `BACKOFFICE_USERS` の所属追加・解除
- `employee`: `ALL_USERS` で表現するため個別ロール履歴を作らない

標準グループに対応しない旧ロールが検出された場合、補正は適用せず停止する。
補正中だけ作られた旧ロール由来の直接 `role_assignments` も削除する。

新規登録についても同じ履歴になるよう、SSO登録、Microsoft 365同期による新規登録、初期管理者登録は
Projectorの暗黙更新ではなく `membership.added` を発行する。

### 月次勤怠の申請・承認

現在の因果関係を正として、申請単位で次を揃える。

1. `workflow_request.drafted`
2. `attendance_month.submitted`
3. `attendance_month.locked`
4. `attendance_month.shared`
5. `workflow_request.submitted`
6. 承認・差戻し・取消のWorkflowイベント
7. 対応する月次勤怠の承認・差戻し・取消・ロック解除イベント
8. 承認時は `backoffice_task.created` とバックオフィスタスクProjection

過去の後付け処理で日時が逆転したロック・共有・取消は、対応するWorkflowイベントの日時へ補正する。
同一秒内でIDの因果順が逆転している旧データだけは、秒精度のカラムで順序を表せるよう1〜2秒の
オフセットを付ける。`attendance_month.locked.workflowRequestId` と `attendance_locks.workflow_request_id`
も同時に補完する。

承認済み月次勤怠にバックオフィスタスクが無い場合は、現在の承認後リアクターと同じ
`backoffice_task.created` を追加する。タスクIDは月次勤怠IDから決定的に生成し、イベント日時、
Projection作成日時、期限日は元の `attendance_month.approved` を基準にするため、再実行しても
重複しない。履歴補正を再適用できない環境では、次の専用コマンドで事前確認・追加できる。

```bash
php artisan events:backfill-attendance-backoffice-tasks
php artisan events:backfill-attendance-backoffice-tasks --apply
```

### 移行初期のFeature

移行済みDBで確認した現在値を初期値とし、`AccessControlSeeder` は次を設定する。

- `ALL_USERS`: 勤怠（打刻、勤怠入力、勤務表・月次提出）、申請、休暇申請、経費精算
- `BACKOFFICE_USERS`: バックオフィスタスク
- `SYSTEM_ADMINISTRATORS`: ユーザー・グループ管理、システム設定
- `HUMAN_RESOURCES_USERS`: ユーザー・グループ管理

子Featureだけが明示設定されている箇所はDBの現在値を維持し、Seederで親Featureを暗黙追加しない。

### 出力監査

独自EventStoreへ残っていた `export.created` はSpatie集約へ移し、以後のCSV/Excel出力も
`stored_events` に直接記録する。独自 `EventStore`、旧Projectorリスナーは廃止する。
`projections:rebuild` は既存運用との互換入口として残し、内部ではSpatie標準の
`event-sourcing:replay` を実行する。

## 実行手順

事前にDB全体のバックアップを取得し、アプリケーションをメンテナンス状態にする。

```bash
php artisan migrate --force

# 読み取り専用の事前確認
php artisan events:normalize-history

# 適用。指定名のバックアップテーブルと `_legacy` テーブルを先に作る
php artisan events:normalize-history \
  --apply \
  --backup-table=stored_events_backup_20260810
```

シーダー(`UserManagementSeeder`・`AccessControlSeeder`)は、作り直し(と旧系統を削除したコードの配備)の後に実行する。
シーダーはCommandを発行してイベントを`stored_events`へ記録し、既にある行は飛ばす(上書きしない)ため、作り直しの前に
実行すると、作り直しの対象に新規のイベントが混ざる。

```bash
php artisan db:seed --class=UserManagementSeeder --force
php artisan db:seed --class=AccessControlSeeder --force
```

バックアップテーブルが既に存在する場合は上書きせず停止する。MySQLのDDLは暗黙コミットされるため、
バックアップ作成後に、イベント・所属Projection・勤怠Projectionの補正だけを1トランザクションで行う。

## 検証

- 廃止イベント3種が0件であること
- 全イベント別名が `config/event-sourcing.php` に登録され、全行をデシリアライズできること
- 全集約の `aggregate_version` が1から連続していること
- 全ユーザーが `ALL_USERS` に所属し、その `membership.added` があること
- 現在管理者であるユーザーの `SYSTEM_ADMINISTRATORS` 所属と履歴が一致すること
- 月次勤怠のロックが対応する `workflow_request_id` を持つこと
- 承認済み月次勤怠ごとに `backoffice_task.created` と未着手タスクが1件ずつあること
- 標準グループのFeatureが移行初期値と一致すること
- `legacy_stored_events` の `export.created` が0件で、`stored_events` 側に存在すること

本番複製MySQL 5.7で、788件から補正後792件（出力監査1件、月次勤怠タスク3件）となること、
全792件をデシリアライズできること、
全集約のバージョン欠番が0件であることを確認済み。

## 休暇まわりの作り直し(変更セット 20261009-keep-leave-work-type-on-edit、論点18)

休暇の旧イベント(有給・特別休暇・代休の旧系統)と、移行イベント(`*.migrated`)・補正イベント(`attendance_day.corrected`)は、
`stored_events`の作り直しで新しいイベント構成へ変換する。移行イベント・補正イベントは作らない(`data-correction`スキル)。
変換規則と実行手順は`docs/changesets/20261009-keep-leave-work-type-on-edit/assets/event-rebuild-mapping.md`(2章・7章)、
本番の手順は`docs/27-release-runbook.md` 3.1による。

### 作り直しの実行

```bash
php artisan leave:rebuild-event-store                    # 試し実行(既定。書き込みなし。扱えない並びがあれば失敗)
php artisan leave:rebuild-event-store --apply            # stored_events_rebuilt に作り直し後の行を書き、自己整合性を検査する
php artisan leave:rebuild-event-store --verify           # stored_events_rebuilt の自己整合性(id・版の連番、旧イベントの残存)を検査する
php artisan leave:rebuild-event-store --swap             # 検査が通っている場合に stored_events と入れ替える
php artisan leave:rebuild-event-store --list-readmodels  # 全Projectorの書き込み先テーブル(リビルド前に空にする対象)を出す
```

- 旧イベントクラスに依存せず`stored_events`の生のJSONを読み、元の発生順にid・版を振り直す。`created_at`は元の値を引き継ぐ。
- 扱えない並びは未対応として一覧に出し、失敗させる(推測で埋めない)。例: ReadModelに行が無く`attendance_day.created`も無い勤怠日、
  cutover後の指定・取消の重複、`daily_calculation_adjusted`の直前に`attendance_day.calculated`が無い日、旧と口座イベントの混在、
  申請に結び付かない消化。
- `--swap`は`stored_events`を`stored_events_before_rebuild_YYYYMMDDHHMMSS`として残す(表名を記録する)。
- 検証は複製DBで行う。複製に作り直し後の表を入れ、全ReadModelを空にして全Projectorをリビルドした結果を本番のReadModelと比較し、
  差分が意図した差(差し戻された休暇の消化記録の取消、休暇値の削除等)だけであることを確認する。

### 部分的な書き換え(`StoredEventCorrector`・補正コマンドの基底)

少数のイベントのpayloadを直す・誤って記録されたイベントを消す補正は、次の部品で行う。作り直しで置き換えられない個別の補正に使う。
各補正は、対象のイベント・修正前後の値を示したうえで、ユーザーの明示的な許可を得てから本実行する(`data-correction`ステップ2。一般的な「直してほしい」は許可とみなさない)。

- `App\Domain\EventSourcing\Correction\StoredEventCorrector`: `stored_events`の直接修正(書き換え・削除)の共通処理
- `App\Console\Commands\Concerns\StoredEventCorrectionCommand`: 補正コマンドの基底クラス。子クラスは`plan()`で補正計画
  (`StoredEventCorrectionPlan`。補正キーと`StoredEventRewrite`の一覧)を返す。子クラスのsignatureには`{--apply} {--backup-table=}`を含める
- 補正ログ `stored_event_corrections`: 修正したイベントの修正前後のpayload・バックアップテーブル名を残す。Projectionではない

### 直接修正の実行(試し実行 → 許可 → `--apply`)

補正コマンド(`StoredEventCorrectionCommand`の子クラス)は次の順で実行する。

```bash
php artisan <補正コマンド>                                  # 試し実行(対象行と修正前後の一覧。書き込みなし)
php artisan <補正コマンド> --apply                          # 本実行(既定のバックアップ名)
php artisan <補正コマンド> --apply --backup-table=stored_events_backup_20261010
```

- 試し実行の一覧は、修正対象(`pending`)と補正ログに既にあり触らない行(修正済み)を区別して出す。
- `--apply`は、まず修正対象の行だけを`CREATE TABLE ... AS SELECT`でバックアップテーブルへ複写し、行数を検証する。
  バックアップ名は既定で`stored_events_backup_YYYYMMDDHHMMSS`、`--backup-table`で指定する(英小文字で始まる英小文字・数字・`_`の
  56文字以内。既存のテーブル名は上書きせず停止する)。対象が無ければバックアップは作らない。
- バックアップの後、1トランザクションで書き換え・削除し、補正ログへ修正前後のpayloadを記録する。書き換え・削除した集約ごとに
  版(`aggregate_version`)が欠番なく続いているかを確かめ、崩れていれば全体をロールバックする。
- 冪等: 同じ補正キー(`correction_key`)で再実行すると、補正ログにある行は再修正しない。
  MySQLのDDLは暗黙コミットされるため、バックアップ作成後にトランザクションが失敗した場合はバックアップテーブルが残る。
  再実行する場合は新しいバックアップ名を指定する。
- 本実行の後、影響するReadModelを`event-sourcing:replay`で再生成し(対象テーブルを空にしてから実行する。手順は`docs/29`参照)、
  検証SQLで結果を確認する。

### 作り直しの後(休暇まわりの確認)

ReadModelの作り直し(全テーブルを空にしてreplay)は、運用コマンド`read-models:rebuild {--dry-run}`(運用コマンド画面の
「データの作り直し(ReadModel)」。試し実行・本実行。変更セット`20261025-read-model-rebuild-admin-command`)からも実行できる。
以下はSSHで`leave:rebuild-event-store --list-readmodels`と`event-sourcing:replay`を使って手作業で行う場合の手順。

- 全ReadModelのテーブル(`leave:rebuild-event-store --list-readmodels`で出す一覧)を空にしてから、全Projectorを`event-sourcing:replay`する
  (Projectorは既存の状態を消さない前提のため空にする。手順は`docs/29`参照)。
- 日次計算を`attendance:recalculate-days`(`--from`・`--to`必須。既定は試し実行、`--apply`で記録。締め・提出済みの日は一覧に印を付ける)で
  再計算する。締め・提出済みの月のスナップショットとの差は、リハーサルで確認する。
- 有給・特別休暇・代休の口座と勤怠の休暇ビューを空から再生成しても、作り直し前の再生成と同じ状態になること。

### 作り直しの最後の工程(有給口座・付与予定のストリーム分離)

作り直し(`EventStoreRebuilder`)は、id・版を振り直した後の最後の工程として`PaidLeaveStreamRekeyer`をかける(次節と同じ変換)。
古い形のバックアップから作り直しても、有給口座は新しいストリームIDで`userId`を持ち、付与予定のイベントは出力に無い。

- 途中の工程(`UnallocatedUsageAllocator`・各Rebuilder)は`aggregate_uuid`を社員IDとして扱う。付け替えは必ず最後に行う。
- 作り直しでは事前条件を見ずに付与予定の行を取り除く(旧版の付与予定イベントはReadModelから現行の形へ置き換えた後に取り除かれる)。
  取り除いた件数は試し実行の報告(「最後の工程」)に出る。付与予定の付与済み・上書き・個別修正を残したい場合は、この変換の前に判断する。
- 取り除いた行の分だけidが飛ぶため、作り直しではidを1から詰め直す(既存の`--verify`のid連番の検査はそのまま通る)。

## 有給口座・付与予定のストリーム付け替え(変更セット 20261021-paid-leave-aggregate-stream-id、論点3)

有給口座(`paid_leave_account.*`)・付与予定(`paid_leave_schedule.*`)の集約IDは、以前は社員IDそのものだった(社員の集約と同じストリーム)。
新しいコードは有給口座を`PaidLeaveAccountAggregate::streamIdFor(userId)`、付与予定を`PaidLeaveScheduleAggregate::streamIdFor(userId)`の
別ストリームに書き、全イベントが`userId`を持つ。作り直し済みの本番の履歴は、専用の変換で直す(既存の作り直しはもう一度かけられない)。

### 変換規則(`App\Domain\EventSourcing\Rebuild\PaidLeaveStreamRekeyer`。1行を1行に写す純粋な変換)

- `paid_leave_account.*`: payloadに`userId`(元の`aggregate_uuid`)を入れ、`aggregate_uuid`と`meta_data`の`aggregate-root-uuid`を
  `PaidLeaveAccountAggregate::streamIdFor(userId)`に替える。payloadの`userId`から作ったIDが`aggregate_uuid`と一致する行(付け替え済み)は
  変えない(2回かけても同じ)。
- `paid_leave_schedule.*`: 取り除く(自動付与の機能は使われておらず、生成は無かったことにする)。
- 版: 影響するストリーム(付け替えた先の有給口座、行が抜けて詰まる元の社員の集約)だけを、id順に1から振り直し、`meta_data`の
  `aggregate-root-version`も合わせる。それ以外の行は`id`・版・payload・meta・`created_at`を変えない。残る行の`id`は変えない
  (取り除いた分は飛び番)。
- 事前条件(1件でもあれば変換せず、件数を報告する): `paid_leave_schedule.entry_granted`・`assessment_overridden`・`entry_manually_edited`、
  `paid_leave_account.grant_created`で`source='scheduled_batch'`のもの(付与予定が実際に使われていた形跡)。手動付与(`source='manual'`)・
  移行(`carried_over`)は止める理由にしない(付け替えて残す)。

### 実行(`leave:rekey-paid-leave-streams`。`#[AdminExecutable]`は付けず、本番ではメンテナンス中にCLIで実行する)

```bash
php artisan leave:rekey-paid-leave-streams            # 試し実行(既定。付け替える行・取り除く付与予定の行(種類別)・旧ID→新IDの件数・事前条件の結果)
php artisan leave:rekey-paid-leave-streams --apply    # stored_events_rebuilt に書き、--verify を行う(事前条件に違反があれば拒否)
php artisan leave:rekey-paid-leave-streams --verify   # stored_events_rebuilt を検査する
php artisan leave:rekey-paid-leave-streams --swap     # 検査が通っていれば stored_events と入れ替える(元の表は stored_events_before_rekey_YYYYMMDDHHMMSS)
```

書き込み・入れ替えは`EventStoreRebuildWriter`(`write`・`swap`)を使う。既存の作り直しの事前条件(新しい休暇の種類がある等)では止めず、
この変換専用の事前条件と検証(`PaidLeaveStreamRekeyVerifier`)を持つ。既存の`verify`のid連番の検査は行わない(付与予定の行を取り除くとidが飛ぶため)。

### この変換の検証(`--verify`)

1. `aggregate_uuid`が`users.id`の行は`user.*`だけ。
2. `paid_leave_account.*`で、payloadの`userId`から作ったIDが`aggregate_uuid`(と`meta_data`の`aggregate-root-uuid`)と一致する。
3. `paid_leave_schedule.*`が0件。
4. 有給口座・付与予定・社員(`aggregate_uuid`が`users.id`)以外の行が、元の表と`id`・版・payload・meta・`created_at`すべて一致する(行の過不足も違反)。
5. `EventRestorabilityChecker`が通る(全行を現行のイベントクラスへ復元できる)。

付け替えの前後の再生結果は、`leave:rebuild-event-store --readmodel-fingerprint`(・`--readmodel-diff`)で比べる。基準A(付け替え前に全ReadModelを
空にして再生)と、付け替え・swap後に空にして再生した指紋が、`paid_leave_schedule_entries`・`paid_leave_schedule_assessments`
(付け替え後は空になる)を除いて一致すること。リリース手順は`docs/27-release-runbook.md` 3.2。

## ロールバック

補正適用中に失敗した場合、イベント・Projection補正はロールバックされる。再実行前に原因を修正し、
新しいバックアップ名を指定する。適用後に戻す場合はメンテナンス状態で `stored_events` を
バックアップテーブルから復元し、`memberships`、`role_assignments`、`attendance_months`、
`attendance_locks`、`entity_shares`、`workflow_requests`、`backoffice_tasks` は事前に取得したDB全体
バックアップから戻す。
