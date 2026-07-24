# 30. 本番カットオーバー時のデータ移行(main → spatie移行後)

`docs/29-event-sourcing-framework-migration.md`のspatie/laravel-event-sourcing移行(作業順
1〜6)は完了したが、そのブランチが分岐した`main`(2026-07-15時点)は既に本番相当の環境で
稼働しており、実データが存在する。この移行の主な変更点(`users.id`をはじめ約20テーブルの
主キーをint→UUIDへ変更)は、既存データを保持したまま単純に`php artisan migrate`するだけでは
適用できない。本ドキュメントは、実データを保ったままこのブランチのスキーマへ切り替える
手順・設計判断・ローカルリハーサル方法をまとめる。

## 1. 方針: 旧`stored_events`の実際の履歴イベントを、できる限り忠実に1件ずつ変換する

当初は「各集約の現在の状態を1件の合成イベントとして記録する(スナップショット方式)」で
実装・リハーサルまで完了させたが、「イベントはできる限り復元して欲しい」という要望を受けて、
**旧`stored_events`本体を`legacy:export`の対象に含め、集約ごとの実際の履歴イベントを
version順に1件ずつ新しいイベントクラスへ変換する方式(以下Gen2)に作り直した**。
スナップショット方式(Gen1)は当初のリハーサルで動作確認まで行っていたが、現在は完全に
Gen2へ置き換わっている。

Gen2でも1件だけ、原理的に「履歴の完全な変換」ができないケースがある: 旧`AttendanceDay`の
状態遷移イベント(`attendance.day_created`/`attendance.clocked_in`等)は、Projectorが行を
自己完結的に再構築するために必要なフィールド(`breaks`/`shift_assignment_id`/
`work_location_type`/`utc_offset_minutes`等)を元々持っていない。この移行作業自体が
「Projectorが行を完全に再構築できるようフィールドを追加する」変更(enrichパターン)を
行っているため、旧payloadに存在しないフィールドは復元しようがない。この場合は
**集約の現在の最終状態からその時点で欠けているフィールドを補う(バックフィル)**という
近似を採用した(`$backfillDay`ヘルパー、3節参照)。この近似により、中間の履歴スナップショット
(例: 出勤打刻直後の状態)の一部フィールドが実際にはその時点でまだ決まっていなかった
「未来の最終値」を表示してしまう可能性があるが、**最終的に再生される状態は100%正確**
(Projectorは常に最後のイベントで上書きするため)。同様の理由で`User`の`user.synced_from_ms365`
(差分のみを持つ旧イベント)も、`$state`に前回までの値を積み上げて補完している。

`legacy_stored_events`(旧イベントストア)自体はアーカイブとして残す(読み取り専用、
削除しない)ため、この近似で失われた粒度が監査上必要になった場合は参照できる。

## 2. アーキテクチャ

```
legacy DB(旧スキーマ、main相当)
    │  legacy:export (読み取り専用、config/database.phpの`legacy`接続。
    │                  旧stored_events本体・role_userピボットも書き出す)
    ▼
storage/app/legacy-migration/snapshot/*.json  (テーブルごとの生JSON)
    │  legacy:convert (config/legacy_migration.phpの`aggregates`定義に従って変換)
    ▼
stored_events (新スキーマ、集約ごとに実際の履歴イベント + 必要な場合のみgenesis合成イベント)
    │  php artisan event-sourcing:replay
    ▼
全Projection Table(users, attendance_days, work_styles, ...)
    │  php artisan legacy:backfill-roles (role_userのイベント化されていない割り当てを補完)
    ▼
役割(ロール)まで含めて完成
```

- **`config/database.php`の`legacy`接続**: 移行元DBへの読み取り専用接続。通常のアプリ
  コードからは一切使わない。`LEGACY_DB_*`環境変数で設定する。
- **`config/legacy_migration.php`**: `aggregates`キー配下に集約ごとの変換定義を持つ。
  - `table` / `depends_on`: 対象テーブル名と、先に変換すべき他集約(外部キー解決のため)。
  - `always_genesis`(任意, bool): trueの場合、旧イベントの有無に関わらず必ず`genesis`を
    最初のイベントとして合成する。旧システムでエンティティの新規作成自体がイベント化
    されていなかったドメイン(`User`)に使う。
  - `genesis`(クロージャ): `always_genesis`が真、またはその集約行に旧イベントが
    1件も無い場合に、「移行時点で分かる最も古い状態」を合成する。通常は既存の業務
    「作成」イベント(`AttendanceDayCreated`・`WorkStyleCreated`・`PaidLeaveGranted`等)を
    そのまま使う。**Userだけは例外**で、既存の`UserOnboardedAsAdmin`等が「SSO連携」
    「ローカルパスワード発行」等の特定フローの意味を持つため、移行専用の
    `App\Domain\User\Events\UserMigratedFromLegacy`を新設した。
  - `events`(連想配列): 旧`event_type`文字列 → 変換クロージャ、のマッピング。クロージャは
    `$state`(このイベントストリームを通して引き継がれる可変の連想配列)を受け取り更新できる。
    旧payloadだけでは新イベントの必須フィールドを復元できない場合、`$state`に前回までの
    既知の値を積み上げるか、`$currentRow`(集約の現在の最終状態)からバックフィルする。
    マッピングが無い・対応不能なイベント種別はそのイベントをスキップし、警告を出す
    (移行全体は止めない。`paid_leave.used`が現時点で唯一の意図的な未対応例。3節参照)。
  - `children`: 子テーブル(`attendance_breaks`等、それ自体は集約ではない)を、
    親行のUUIDへ紐付けて読み込むための定義。
- **`App\Domain\LegacyMigration\UuidMap`**: (テーブル名, 旧id) → 新UUID の対応表を
  JSONファイルで永続化する。`legacy:convert`を再実行しても同じUUIDが使われる(冪等)。
- **`legacy:export` / `legacy:convert` / `legacy:backfill-roles`**
  (`app/Console/Commands/Legacy/`): 上記を実行するArtisanコマンド。
  `legacy:backfill-roles`は`event-sourcing:replay`の**後**に実行する。ロール割り当ては
  通常`user.roles_changed`イベントの再生で復元されるが、旧システムの初期adminユーザーの
  ように、コマンド経由でなく直接`role_user`へ挿入された(=イベント化されていない)
  割り当てが存在しうるため、現在のピボット行そのものから補完する別ステップとして
  独立させている(`users`テーブルが存在しないと書き込めないため、replayより前には
  実行できない)。

## 3. 対応済みドメインと未対応ドメイン

`config/legacy_migration.php`の`aggregates`に定義済みで、実際の履歴イベントを1件ずつ
変換できる(ローカルリハーサルで動作確認済み)のは以下7集約:

| 集約 | テーブル | genesis(必要な場合) | 変換できる旧イベント |
|---|---|---|---|
| `user` | `users` | `UserMigratedFromLegacy`(新設。**必ず**最初に合成する。旧システムでは作成自体がイベント化されていないため) | `roles_changed` / `hire_date_set` / `termination_date_set` / `logged_in` / `synced_from_ms365` |
| `attendance_punch` | `attendance_punches` | `AttendancePunchRecorded`(旧イベントが無い行のみのフォールバック) | `recorded` / `corrected` / `deleted` |
| `work_calendar` | `work_calendars` | `WorkCalendarCreated`(同上) | `created` / `work_calendar_day.updated`(※) / `published` |
| `work_style` | `work_styles` | `WorkStyleCreated`(同上) | `created` / `default_changed` |
| `employee_shift_assignment` | `employee_shift_assignments` | `EmployeeShiftAssigned`(同上) | `assigned` / `plan_changed` / `published` |
| `attendance_day`(+`attendance_breaks`/`attendance_leave_segments`を子として埋め込み) | `attendance_days` | `AttendanceDayCreated`(同上) | `day_created` / `clocked_in` / `break_started` / `break_ended` / `clocked_out` / `synced_from_punches` / `day_edited` / `day_calculated` / `daily_calculation_adjusted` / `day_deleted` |
| `paid_leave_grant` | `paid_leave_grants` | `PaidLeaveGranted`(同上) | `granted` / `warning_raised`(`used`は未対応、後述) |
| (集約外) | `roles` / `request_types` / `employment_categories` / `special_leave_types` / `role_user` | — | イベント化せず単純コピー(`plain_copy_tables`)、または`legacy:backfill-roles`で補完(`role_user`) |

(※) `work_calendar_day.updated`は旧システムでは日次バッチ的に発行されていたため、
1旧イベント→1新イベント(`days`配列の要素数1)として変換している。複数日をまとめて
1回の`WorkCalendarDaysUpdated`として発行していた実際のバッチ粒度までは復元していない
(最終状態には影響しない)。

サンプルデータでのリハーサルでは`work_calendar`・`employee_shift_assignment`の旧イベントが
0件だった(シーダーで直接データ投入されており、コマンド経由で作成されていない)ため、
この2集約は`genesis`フォールバック経路のみ検証済みで、`events`側のマッピングは
本番の実データで初めて検証されることになる点に注意。

**未対応(このスクリプトにはまだ定義が無い)**: `shift_patterns` / `rotation_patterns` /
`employee_rotation_assignments` / `user_work_style_monthly_assignments` /
`legal_holiday_designations` / `attendance_months` / `paid_leave_requests` /
`paid_leave_usages`(および`paid_leave_grant`の`paid_leave.used`イベント) /
`special_leave_grants` / `special_leave_requests` / `special_leave_usages` /
`workflow_requests` / `backoffice_tasks` / `attachments` / `application_integrations` /
`authentication_keys` / `devices` / `device_admin_sessions` / `notifications`。

これらも同じ枠組み(`config/legacy_migration.php`の`aggregates`に定義を1つ追加するだけ)で
対応できる。手順:

1. 対象テーブルの「作成」イベントクラスを確認する(`app/Domain/<Domain>/Events/`)。
   既存の業務イベントで問題なければ`genesis`にそのまま使う。副作用・特定フローの意味を
   持つ場合(Userのように)は移行専用イベントを新設し、対応するProjectorに
   `onXxxMigratedFromLegacy`メソッドを1つ追加する。
2. `depends_on`に、参照している他集約(UUID化されたもの)を列挙する。
3. `events`に、旧`event_type`文字列ごとの変換クロージャを追加する
   (`$map->resolve('テーブル名', $row->外部キー列)`でUUIDを解決する)。旧payloadに
   足りないフィールドは`$currentRow`(集約の現在の最終状態)からバックフィルするか、
   `$state`に前イベントまでの値を積み上げて補う(`attendance_day`・`user`の実装を参照)。
4. 日時カラムでオフセット付きISO8601文字列を要求するイベント(`splitOffset`を使う
   Projectorがあるもの。`AttendanceDayCreated`・`AttendancePunchRecorded`等)は、
   本ファイル冒頭の`$withOffset`ヘルパーを使うこと(素の`datetime`文字列をそのまま
   渡すとProjector側で例外になる。今回の作業で実際に踏んだ)。
5. `paid_leave_usages`(および将来`special_leave_usages`)は、対応する`paid_leave_grants`
   行の`used_days`を正しく引き継ぐために必須。`used_days>0`の付与を1件でも移行する前に、
   このテーブルの変換を先に実装し、`paid_leave.used`を`events`に追加すること。
6. ローカルリハーサル(下記4節)で`legacy:convert --dry-run`→本実行→
   `event-sourcing:replay`→`legacy:backfill-roles`→件数照合、の順で必ず検証してから
   本番手順に組み込む。特にスキップされたイベント一覧(`legacy:convert`実行時の警告)に
   意図しないものが混ざっていないか必ず確認する。

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

- `legacy:export`が全テーブル(旧`stored_events`本体・`role_user`を含む)の件数を
  正しく出力すること。
- `migrate:fresh`が新スキーマをエラーなく作成できること(5節のMySQL固有の問題に注意)。
- `legacy:convert --dry-run`で件数が期待通りであること。**スキップされたイベントの
  警告一覧に、意図しないもの(マッピング未定義の見落とし)が混ざっていないか必ず確認する**
  (`paid_leave.used`のみが現時点で意図的なスキップ)。
- `legacy:convert`(本実行)→`event-sourcing:replay`→`legacy:backfill-roles`まで完走すること。
- 旧DB・新DBで主要テーブルの件数が一致すること。
- サンプル行を数件、旧DB・新DBで主要フィールドを目視比較すること(本ドキュメント作成時は
  `users`(+ロール)・`attendance_days`・`attendance_daily_calculations`・`work_styles`・
  `attendance_punches`・`paid_leave_grants`で実施し、完全一致を確認した)。

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
- `paid_leave_usages`/`special_leave_usages`の変換実装(`used_days>0`の付与・`paid_leave.used`
  イベントの変換を含む完全な移行に必須)。
- `attendance_months`(`AttendanceMonthSubmitted`等)の変換実装。旧`snapshot`フィールドに
  対応する概念が旧システムに無く、追加の設計検討が必要。
- `work_calendar`・`employee_shift_assignment`の`events`側マッピングは、サンプルデータでは
  旧イベントが0件だったため未検証(genesisフォールバックのみ確認済み)。本番の実データで
  初めて実際の履歴イベントが変換される点に注意し、本番リハーサル(実施可能であれば)で
  重点的に確認する。
- 実際のMySQL 5.7(またはXSERVER契約と同バージョン)でのリハーサル。
- 本番の実データ規模(件数)でのパフォーマンス確認(今回のリハーサルは数百件規模)。
- `docs/29-event-sourcing-framework-migration.md`の「最終的なデータ移行」節
  (`legacy_stored_events`のアーカイブ化)は、本カットオーバーが完了し
  `legacy_stored_events`(旧`stored_events`)が実際に不要になった段階で改めて着手する。
