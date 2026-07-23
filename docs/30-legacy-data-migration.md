# 30. 本番カットオーバー時のデータ移行(main → spatie移行後)

`docs/29-event-sourcing-framework-migration.md`のspatie/laravel-event-sourcing移行(作業順
1〜6)は完了したが、そのブランチが分岐した`main`(2026-07-15時点)は既に本番相当の環境で
稼働しており、実データが存在する。この移行の主な変更点(`users.id`をはじめ約20テーブルの
主キーをint→UUIDへ変更)は、既存データを保持したまま単純に`php artisan migrate`するだけでは
適用できない。本ドキュメントは、実データを保ったままこのブランチのスキーマへ切り替える
手順・設計判断・ローカルリハーサル方法をまとめる。

## 1. 方針: 「イベントを1件ずつ忠実に変換」ではなく「移行時点の状態を1つのイベントとして記録」

選択肢は2つあった。

1. **旧`stored_events`(=このリポジトリの`legacy_stored_events`相当)の各イベントを、
   新しいイベントクラス・フィールド形状へ1件ずつ変換する。**
   歴史的な粒度(いつ・誰が・何を変更したか)を完全に保てるが、この移行作業自体で多くの
   イベントに「Projectorが行を完全に再構築できるようフィールドを追加する」変更
   (enrichパターン)を行っており、旧payloadに存在しないフィールドを埋める必要がある。
   ドメイン・イベント種別ごとに個別の変換ロジックが要り、失われたフィールド
   (例: `UserLoggedIn.loggedInAt`のような、旧実装では記録されていなかった値)は
   そもそも復元できないケースもある。
2. **各集約の「現在の状態」を1件の合成イベントとして新しいイベントストアへ記録し、
   そこから全Projectionを再生成する。**(**採用**)
   過去の細かい変更履歴(個々の打刻・修正のたび)は新しいイベントストアには引き継がれないが、
   `legacy_stored_events`はアーカイブとして残す(読み取り専用、削除しない)ため、
   監査上必要であれば参照できる。現在の状態は100%正確に引き継げ、実装量も現実的。

「最悪データが消えても構わない」という許容度と、(1)の全ドメイン・全イベント種別を
個別対応するコストを比較し、(2)を採用した。

## 2. アーキテクチャ

```
legacy DB(旧スキーマ、main相当)
    │  legacy:export (読み取り専用、config/database.phpの`legacy`接続)
    ▼
storage/app/legacy-migration/snapshot/*.json  (テーブルごとの生JSON)
    │  legacy:convert (config/legacy_migration.phpの定義に従って変換)
    ▼
stored_events (新スキーマ、集約ごとに1〜数件の合成イベント)
    │  php artisan event-sourcing:replay
    ▼
全Projection Table(users, attendance_days, work_styles, ...)
```

- **`config/database.php`の`legacy`接続**: 移行元DBへの読み取り専用接続。通常のアプリ
  コードからは一切使わない。`LEGACY_DB_*`環境変数で設定する。
- **`config/legacy_migration.php`**: どのテーブルを、どのイベントクラスへ、どういう
  マッピングで変換するかを宣言する設定ファイル。テーブルごとに:
  - `depends_on`: 先に変換すべき他テーブル(外部キー解決のため)。
  - `event_class`: 使う「作成」イベント。**通常は既存の業務イベントをそのまま使う**
    (`AttendanceDayCreated`・`WorkStyleCreated`・`PaidLeaveGranted`等。これらは元々
    「矛盾なく現在の状態を組み立てる」ための汎用イベントであり、移行専用に転用しても
    意味が壊れない)。**Userだけは例外**で、`UserOnboardedAsAdmin`等の既存イベントが
    「SSO連携」「ローカルパスワード発行」等の特定フローの意味を持つため、移行専用の
    `App\Domain\User\Events\UserMigratedFromLegacy`を新設した。
  - `map`: 旧行(1件)→ イベントのコンストラクタ引数配列、へ変換するクロージャ。
  - `children`: 子テーブル(`attendance_breaks`等、それ自体は集約ではない)を
    親行のUUIDへ紐付けて埋め込むための定義。
  - `extra_events`(任意): 同じ集約に対して追加で記録する後続イベント(例:
    `attendance_days`の`AttendanceDayCalculated`。`attendance_daily_calculations`は
    「作成」イベントに含めず、集約バージョン2の別イベントとして記録する)。
- **`App\Domain\LegacyMigration\UuidMap`**: (テーブル名, 旧id) → 新UUID の対応表を
  JSONファイルで永続化する。`legacy:convert`を再実行しても同じUUIDが使われる(冪等)。
- **`legacy:export` / `legacy:convert`**(`app/Console/Commands/Legacy/`): 上記を実行する
  Artisanコマンド。

## 3. 対応済みドメインと未対応ドメイン

このスクリプトが実際に変換できる(ローカルリハーサルで動作確認済み)のは以下:

| テーブル | 使用イベント |
|---|---|
| `users` | `UserMigratedFromLegacy`(新設) |
| `work_calendars` | `WorkCalendarCreated` |
| `work_styles` | `WorkStyleCreated` |
| `employee_shift_assignments` | `EmployeeShiftAssigned` |
| `attendance_days`(+`attendance_breaks`/`attendance_leave_segments`を埋め込み) | `AttendanceDayCreated` |
| `attendance_days`の`attendance_daily_calculations` | `AttendanceDayCalculated`(追加イベント) |
| `attendance_punches` | `AttendancePunchRecorded` |
| `paid_leave_grants` | `PaidLeaveGranted`(`used_days>0`は未対応、後述) |
| `roles` / `request_types` / `employment_categories` / `special_leave_types` | イベント化せず単純コピー(`plain_copy_tables`) |

**未対応(このスクリプトにはまだ定義が無い)**: `shift_patterns` / `rotation_patterns` /
`employee_rotation_assignments` / `user_work_style_monthly_assignments` /
`legal_holiday_designations` / `attendance_months` / `paid_leave_requests` /
`paid_leave_usages`(および`used_days>0`の`paid_leave_grants`) / `special_leave_grants` /
`special_leave_requests` / `special_leave_usages` / `workflow_requests` /
`backoffice_tasks` / `attachments` / `application_integrations` / `authentication_keys` /
`devices` / `device_admin_sessions` / `notifications`。

これらも同じ枠組み(`config/legacy_migration.php`にテーブル定義を1つ追加するだけ)で
対応できる。手順:

1. 対象テーブルの「作成」イベントクラスを確認する(`app/Domain/<Domain>/Events/`)。
   既存の業務イベントで問題なければそのまま使う。副作用・特定フローの意味を持つ場合
   (Userのように)は移行専用イベントを新設し、対応するProjectorに`onXxxMigratedFromLegacy`
   メソッドを1つ追加する。
2. `depends_on`に、参照している他テーブル(UUID化されたもの)を列挙する。
3. `map`クロージャで、旧カラム→イベントのコンストラクタ引数を組み立てる
   (`$map->resolve('テーブル名', $row->外部キー列)`でUUIDを解決する)。
4. 日時カラムでオフセット付きISO8601文字列を要求するイベント(`splitOffset`を使う
   Projectorがあるもの。`AttendanceDayCreated`・`AttendancePunchRecorded`等)は、
   本ファイル冒頭の`$withOffset`ヘルパーを使うこと(素の`datetime`文字列をそのまま
   渡すとProjector側で例外になる。今回の作業で実際に踏んだ)。
5. `paid_leave_usages`(および将来`special_leave_usages`)は、対応する`paid_leave_grants`
   行の`used_days`を正しく引き継ぐために必須。`used_days>0`の付与を1件でも移行する前に、
   このテーブルの変換を先に実装し、`PaidLeaveUsed`を追加イベントとして発行すること
   (`legacy_migration.tables.paid_leave_grants.map`内の例外(`RuntimeException`)を参照)。
6. ローカルリハーサル(下記4節)で`legacy:convert --dry-run`→本実行→
   `event-sourcing:replay`→件数照合、の順で必ず検証してから本番手順に組み込む。

## 4. ローカルリハーサル手順

```
# 1. ローカルにMySQLを用意する(このリハーサルでは8.0で代用した。5.7固有の懸念は5節参照)。
mysql -u root -e "
  CREATE DATABASE flow_office_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE flow_office_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'flowoffice'@'localhost' IDENTIFIED BY 'flowoffice';
  GRANT ALL PRIVILEGES ON flow_office_legacy.* TO 'flowoffice'@'localhost';
  GRANT ALL PRIVILEGES ON flow_office_new.* TO 'flowoffice'@'localhost';
"

# 2. mainブランチをworktreeでチェックアウトし、旧スキーマ+サンプルデータを用意する。
git worktree add /tmp/flow-office-main main
cd /tmp/flow-office-main/backend
composer install
cp .env.example .env && php artisan key:generate
#   .env の DB_* を flow_office_legacy へ向ける
php artisan migrate --force
php artisan db:seed --force
php artisan db:seed --class=ScenarioSeeder --force
#   必要に応じてtinker等で追加の打刻・申請データも作っておく(本リハーサルで実施した内容は
#   このコミットのセッション記録を参照)。

# 3. このリポジトリ側(spatie移行後)で backend/.env.legacy-rehearsal を用意する。
cd /home/user/flow-office/backend
cp .env .env.legacy-rehearsal
#   .env.legacy-rehearsal の DB_* を flow_office_new へ、
#   LEGACY_DB_* を flow_office_legacy へ向ける(値の例はconfig/database.phpのlegacy接続、
#   .env.example参照)。

# 4. リハーサル実行
../scripts/legacy-migration/01-rehearse-local.sh
```

`01-rehearse-local.sh`は内部で`backend/.env`を一時的に`.env.legacy-rehearsal`の内容へ
差し替えて実行し、終了時に元へ戻す。**`--env=`オプションだけでは接続先を切り替えられない**
ことに注意(`backend/bootstrap/app.php`が起動直後に無条件で`.env`(環境名を問わない)を
読み込むため、`--env=legacy-rehearsal`を付けても`.env`側の値が優先されてしまう。実際に
このリハーサルで踏んだ問題)。

### リハーサルで確認する項目

- `legacy:export`が全テーブルの件数を正しく出力すること。
- `migrate:fresh`が新スキーマをエラーなく作成できること(5節のMySQL固有の問題に注意)。
- `legacy:convert --dry-run`で件数が期待通りであること。
- `legacy:convert`(本実行)→`event-sourcing:replay`まで完走すること。
- 旧DB・新DBで主要テーブルの件数が一致すること。
- サンプル行を数件、旧DB・新DBで主要フィールドを目視比較すること(本ドキュメント作成時は
  `users`・`attendance_days`・`attendance_daily_calculations`で実施し、完全一致を確認した)。

## 5. MySQL 5.7 vs 8.0 について

本番はMySQL 5.7想定だが、この作業環境ではUbuntu 24.04のパッケージリポジトリに5.7が
存在せず(8.0のみ)、リハーサルはMySQL 8.0で代用した。5.7特有の懸念で確認できていない点:

- **JSON型・関数**: `stored_events.event_properties`/`meta_data`は素の`JSON`型カラムへの
  格納のみ(`JSON_EXTRACT`等の関数は使っていない)。5.7でも問題ないはず。
- **`sql_mode`のデフォルト差**: 8.0の`ONLY_FULL_GROUP_BY`等がデフォルトで有効な点は
  今回関係ない(集計クエリを新設していないため)。
- **識別子の64文字制限**: 5.7・8.0共通の制限。本移行作業で実際に3件のマイグレーションが
  この制限に抵触しているのを発見し修正した(6節参照)。sqliteでのテストでは検出できない
  (sqliteは識別子長を制限しないため)。
- **実施推奨**: 本番カットオーバーの前に、可能であれば実際のMySQL 5.7(またはXSERVER契約と
  同じバージョン)でも本手順を1回リハーサルすること。

## 6. このリハーサル中に見つかった既存の不具合(修正済み)

いずれもsqliteでのテスト(`php artisan test`)では検出できず、実際のMySQLで
`migrate:fresh`して初めて顕在化した。

1. **`paid_leave_grant_rule_steps`の複合ユニーク制約名が64文字を超えていた**
   (main側、`rule_id`+`continuous_service_months`の自動生成名)。このリポジトリでは
   既に短い名前が明示されており問題なし(mainのみの問題。リハーサル用に
   ローカルworktree側だけ修正した)。
2. **`attendance_punches.superseded_by_punch_id`のFK型不一致**
   (`2026_07_11_100000_add_correction_columns_to_attendance_punches_table.php`):
   `attendance_punches.id`はUUID化されたが、この列は`foreignId`(unsignedBigInteger)の
   ままだった。自己参照FKだったため、他列名(`*_punch_id`等)を機械的に検索した際の
   Attendance移行作業で見落とされていた。`foreignUuid`に修正し、`down()`の
   `dropConstrainedForeignId`もそのまま使えることを確認した(型に関わらず動作する)。
3. **`attendance_weekly_calculations`(deprecated、次のマイグレーションで即drop)の
   `user_id`のFK型不一致**: dropされる一時テーブルであっても、作成時にFK型が
   一致しないと`migrate:fresh`全体が失敗する。`create`側の`up()`と、`drop`側の
   `down()`(ロールバック時に同じ定義でテーブルを作り直す)の両方を`foreignUuid`に修正した。
4. **`workflow_request_history_entries`の複合インデックス名が64文字を超えていた**
   (`workflow_request_id`+`occurred_at`の自動生成名)。明示的な短い名前を付けて修正した。

これらは全て本コミットで修正済み(`php artisan test`は364件のまま変化なし。sqliteでは
発生しない問題のため)。

## 7. 本番カットオーバー実行時の手順

`docs/27-release-runbook.md`の「3. backend/ のデプロイ手順」のうち、`php artisan migrate
--force`を実行する直前に、以下を挟む(必ずメンテナンス時間中に行う)。

```
cd backend
# LEGACY_DB_* を、現在稼働中の(移行前の)DB_*と同じ接続情報で.envに追加する。

../scripts/legacy-migration/02-cutover.sh /path/to/backup-dir
```

`02-cutover.sh`は対話確認を挟みながら、バックアップ→`legacy:export`→`migrate:fresh`→
`legacy:convert`→`event-sourcing:replay`まで実行する。完了後は`docs/27-release-runbook.md`
の残り手順(`config:cache`等)に戻って続行する。

### ロールバック

`migrate:fresh`実行後に問題が見つかった場合、`00-backup.sh`が作成したダンプから
復元する以外に手段はない(このスクリプト自体は復元機能を持たない。「最悪消し飛んでも
構わない」という前提のため、ロールバック機構自体への投資はしていない)。

```
gunzip -c /path/to/backup-dir/flow_office-<timestamp>.sql.gz | \
  mysql --host=... --user=... --password=... flow_office
```

## 8. 未実施・今後の作業

- 3節の「未対応」テーブル群の`config/legacy_migration.php`定義追加。
- `paid_leave_usages`/`special_leave_usages`の変換実装(`used_days>0`の付与を含む
  完全な移行に必須)。
- 実際のMySQL 5.7(またはXSERVER契約と同バージョン)でのリハーサル。
- 本番の実データ規模(件数)でのパフォーマンス確認(今回のリハーサルは数百件規模)。
- `docs/29-event-sourcing-framework-migration.md`の「最終的なデータ移行」節
  (`legacy_stored_events`のアーカイブ化)は、本カットオーバーが完了し
  `legacy_stored_events`(旧`stored_events`)が実際に不要になった段階で改めて着手する。
