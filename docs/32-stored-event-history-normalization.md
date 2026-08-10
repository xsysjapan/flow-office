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

## ロールバック

補正適用中に失敗した場合、イベント・Projection補正はロールバックされる。再実行前に原因を修正し、
新しいバックアップ名を指定する。適用後に戻す場合はメンテナンス状態で `stored_events` を
バックアップテーブルから復元し、`memberships`、`role_assignments`、`attendance_months`、
`attendance_locks`、`entity_shares`、`workflow_requests`、`backoffice_tasks` は事前に取得したDB全体
バックアップから戻す。
