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
php artisan db:seed --class=UserManagementSeeder --force
php artisan db:seed --class=AccessControlSeeder --force

# 読み取り専用の事前確認
php artisan events:normalize-history

# 適用。指定名のバックアップテーブルと `_legacy` テーブルを先に作る
php artisan events:normalize-history \
  --apply \
  --backup-table=stored_events_backup_20260810
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

## 休暇まわりの補正(変更セット 20261009-keep-leave-work-type-on-edit)

休暇の申請・残数・勤怠を文脈ごとに分けた変更(変更セット論点12・仕様確定事項H)で、本番の休暇データを補正する
ための枠組みを追加した。枠組みは次の部品からなる。具体的な補正の計画(どのイベントを何に変えるか)は、本番相当データの
リハーサルで件数を確認して決め、その後にユーザーの許可を得てから作る。

- `App\Domain\EventSourcing\Correction\StoredEventCorrector`: `stored_events`の直接修正(書き換え・削除)の共通処理
- `App\Console\Commands\Concerns\StoredEventCorrectionCommand`: 補正コマンドの基底クラス。子クラスは`plan()`で補正計画
  (`StoredEventCorrectionPlan`。補正キーと`StoredEventRewrite`の一覧)を返す。子クラスのsignatureには`{--apply} {--backup-table=}`を含める
- 補正ログ `stored_event_corrections`: 修正したイベントの修正前後のpayload・バックアップテーブル名を残す。Projectionではない
- 補正専用イベント `attendance_day.corrected`(`CorrectAttendanceDay`で記録)
- 候補の検出 `php artisan leave:correction-report`(試し実行専用。何も変更しない)

### 候補の検出とリハーサル

本番相当DBの複製で次を実行し、候補の件数と採りうる方法を確認する。

```bash
php artisan leave:correction-report
php artisan leave:correction-report --limit=50
```

| 候補 | 内容 | 推奨する方法 | 採らない方法 |
|---|---|---|---|
| (1) | 差し戻された休暇の未取消の消化記録 | 補正イベント(今の時点の取消を追記する。口座集約の取消で行う) | 直接修正(利用者の操作の記録であり誤記録ではないため) |
| (2) | 休暇の処理が直接作った勤怠日(`attendance_day.created`が無い) | 補正イベント(`attendance_day.corrected`を今の時点に追記) | 欠けた`created`の過去の版への挿入(挿入は行わない) |
| (3) | 編集イベント(created・edited)に入った休暇値(`workType`の`paid_leave_*`等) | 直接修正(`workType`を`null`へ書き換え。版は変えない) | 補正イベントによる上書き(過去の誤った値は履歴に残る) |
| (4) | 勤怠日に残る休暇値・全休の`status`(実績・打刻の無い`clocked_out`) | (3)の直接修正の後に`event-sourcing:replay`で再生成し、残った行だけ補正イベントを追記 | 勤怠日テーブルの直接UPDATE(ReadModleだけの書き換えは行わない) |

リハーサルでは次も確認する。

- 旧系統の引き継ぎコマンドの警告件数: `paid-leave:migrate-requests`・`special-leave:migrate-to-account`・
  `compensatory-leave:migrate-to-account`(いずれも既定は試し実行)
- (1)の対象のうち、旧系統の消化記録は口座への移行(`migrated`)の後に取消として扱うため、移行の件数と合わせて方法を決める

決めた方法と件数は変更セットに記録し、直接修正の対象イベントの種類・範囲・書き換え後の値を示したうえで、
ユーザーの明示的な許可を得る(`.claude/skills/data-correction` ステップ2。一般的な「直してほしい」は許可とみなさない)。

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

### 補正イベント(`attendance_day.corrected`)を使う場合

補正イベントは、今の時点に勤怠日の「現在の正しい状態一式」を追記する。過去のイベントは書き換えない。

- 運用コマンドは`CorrectAttendanceDay`(`App\Domain\Attendance\Commands\CorrectAttendanceDay`)をCommandBusで発行する。
  `correctionId`が同じ補正は記録しない(再実行で二重に記録しない)。`reason`・`correctedByUserId`は必須。
- 行が無ければ作る(欠けた`attendance_day.created`の補完)。`dailyCalculation`が`null`なら日次計算の行を消す。
  手動調整・週40時間の配賦は渡した値をそのまま置き、差分の再適用はしない。月次ロック(`locked_at`)は含めない。
- 補正イベントの追記後に、勤怠日の日次計算を現在のロジックで確認する場合は`attendance:recalculate-days`
  (`--from`・`--to`必須。既定は試し実行、`--apply`で記録。締め・提出済みの日は一覧に印を付ける)を使う。

### 検証(休暇まわりの補正後)

- `php artisan leave:correction-report`の候補(1)〜(4)の件数が、リハーサルで決めた方法の後に想定どおり0件になること
- `stored_event_corrections`に補正キーごとの件数とバックアップテーブル名が残っていること
- 補正の対象の集約について、版が欠番なく続いていること
- 有給・特別休暇・代休の口座と勤怠の休暇ビューを空から再生成しても、補正前の再生成と同じ状態になること

## ロールバック

補正適用中に失敗した場合、イベント・Projection補正はロールバックされる。再実行前に原因を修正し、
新しいバックアップ名を指定する。適用後に戻す場合はメンテナンス状態で `stored_events` を
バックアップテーブルから復元し、`memberships`、`role_assignments`、`attendance_months`、
`attendance_locks`、`entity_shares`、`workflow_requests`、`backoffice_tasks` は事前に取得したDB全体
バックアップから戻す。
