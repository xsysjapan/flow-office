# リリース前リハーサル手順書(PR#121: 有給口座・付与予定のAggregateId分離、年度はじめ一斉付与)

本番のダンプをもとに、PR#121のリリース(`docs/27-release-runbook.md` 3.2・3.3)を通しで行い、合否を判定するための手順書。
実行者がこの手順のとおりに進め、結果を「9. 結果の記録欄」に書き込む。

- 対象の変更: PR#121(head `docs/paid-leave-schedule-investigation`)。実施したときのcommitを「9」に記録する。
- 旧コード: mainのcommit(PR#121のマージの前。2026-10-24時点では`1213507`)。本番で今動いているコードと同じであること。
- 根拠: `docs/27-release-runbook.md` 3.2・3.3、`docs/32-stored-event-history-normalization.md`、
  変更セット`20261021-paid-leave-aggregate-stream-id`・`20261021-paid-leave-fiscal-mass-grant-and-auto-assessment`・
  `20261024-paid-leave-release-rehearsal`(この手順書の変更セット)。
- 本番のDBへの書き込みは、この手順書の対象外。本番で付け替えるときは、試し実行の結果をユーザーに示して許可を取る(`data-correction`スキル)。
- 表記: **要確認**は、実施する前に、実行者またはユーザーが決める箇所。

---

## 0. 安全ルール

### 0-1. 元のダンプの扱い
- 元のダンプ(例: `xsys_flowoffice.sql`)は変更しない。中を表示・共有しない(SSOの設定・個人情報を含む)。
- 使うときは、必ず先に「1-2」で一時的な複製を作り、SSOの設定を除いてから使う。
- 複製のDB・保存した出力は、本番と同じ機密として扱う。比較の結果を保存したら、破棄する。

### 0-2. 接続先の確認(SQL・artisanを実行するたびに最初に行う)

```bash
php artisan db:show            # artisanの接続先(Database の行)
```

```sql
SELECT DATABASE() AS db, VERSION() AS mysql_version, @@hostname AS host;
```

この手順では、2つのDBを使う。本番のDB名が出たら、そこで中止する。

| DB名(例) | 役割 | 使うコード |
|---|---|---|
| `flow_office_before` | 基準A。旧コードで、ReadModelを再生して指紋を取る | 旧コード(main) |
| `flow_office_rehearsal` | 本番役。migration・付け替え・再生・作り直しを行う | 新しいコード(PR#121) |

### 0-3. 通知・外部連携を止める
- `.env`は`MAIL_MAILER=log`にし、`QUEUE_CONNECTION=sync`以外にはしない。キューのワーカー(`queue:work`)を起動しない。cron(`schedule:run`)を設定しない。
- 社員の同期(`SyncUsersFromMs365Command`)・freee/MoneyForwardの連携・定時の付与のコマンドは実行しない。
- 2つのDBの両方で、「1-2」のSQLを実行する。

### 0-4. 出力の保存
すべての出力を、リポジトリの外に保存する(gitで管理しない)。

```bash
mkdir -p ~/rehearsal-pr121/{before,dryrun,after,impact}
```

---

## 1. 準備

### 1-1. タイムゾーン
リハーサルのMySQLのタイムゾーンを、本番に揃える。前回のリハーサル手順書(`docs/changesets/20261009-keep-leave-work-type-on-edit/assets/rehearsal-checklist.md` 1-1a)と同じ。

```sql
SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone;
```

### 1-2. 2つのDBに取り込み、SSOの設定を除く
ダンプを2つのDBに取り込む。取り込んだ後、両方のDBで次を実行する。値は表示しない。

```sql
UPDATE system_settings
   SET m365_tenant_id = NULL, m365_client_id = NULL, m365_client_secret = NULL,
       m365_mock_enabled = 0, notification_mail_enabled = 0;
-- 確認(値は出さず、空になったかだけを見る)
SELECT m365_tenant_id IS NULL AS tenant_cleared, m365_client_id IS NULL AS client_cleared,
       m365_client_secret IS NULL AS secret_cleared, m365_mock_enabled, notification_mail_enabled
  FROM system_settings;
```

- 過去のSSOのログイン・アカウントの連携の履歴(`stored_events`・`external_identities`)は残す。付け替えと比較に要る。
- リダイレクトURIは`.env`の`MICROSOFT_REDIRECT_URI`にあるので、リハーサルの`.env`では空にする。

### 1-3. コードを2つ用意する

```bash
git clone <リポジトリ> ~/rehearsal-pr121/code-old && git -C ~/rehearsal-pr121/code-old checkout <旧コードのcommit>
git clone <リポジトリ> ~/rehearsal-pr121/code-new && git -C ~/rehearsal-pr121/code-new checkout <PR#121のcommit>
# それぞれの backend/ で composer install --no-dev、.env を用意する(DB_DATABASE は各DB)
```

### 1-4. 記録の前の件数(新しいDBで、migrationの前)

```sql
SELECT COUNT(*) AS stored_events FROM stored_events;
SELECT event_class, COUNT(*) FROM stored_events
 WHERE event_class LIKE 'paid_leave_account.%' OR event_class LIKE 'paid_leave_schedule.%'
 GROUP BY event_class ORDER BY event_class;
SELECT status, COUNT(*) FROM paid_leave_schedule_entries GROUP BY status;
```

---

## 2. 基準A(旧コード、`flow_office_before`)

旧コードの`backend/`で実行する。migrationは実行しない(旧スキーマのまま)。

1. ReadModelの一覧を出す:
   `php artisan leave:rebuild-event-store --list-readmodels > ~/rehearsal-pr121/before/readmodels.txt`
2. 一覧の全テーブルを空にする(`SET FOREIGN_KEY_CHECKS=0; TRUNCATE ...; SET FOREIGN_KEY_CHECKS=1;`)。
3. 再生する: `php artisan event-sourcing:replay --force`
4. 指紋を取る:
   `php artisan leave:rebuild-event-store --readmodel-fingerprint > ~/rehearsal-pr121/before/fingerprint-A.tsv`

旧コードで再生できない(例外で止まる)ときは、中止して報告する。旧コードは今の本番のイベントを読めるはずなので、止まるのは想定外。

---

## 3. 新しいコード(`flow_office_rehearsal`): migration

新しいコードの`backend/`で実行する。

1. `php artisan migrate --force 2>&1 | tee ~/rehearsal-pr121/after/migrate.txt`
   - 未適用のmigrationは7件(`2026_10_21_000001`〜`000005`、`2026_10_22_000001`・`000002`)。
   - `2026_10_21_000002`が、「既定のカレンダーの年度開始月と違う一斉付与の月を持つルール」(ルールの名前と月)を出したら、そのまま記録する。
     付与日は年度開始日に変わる(2026-10-24、ユーザーが5/1で正しいと確認済み)。

---

## 4. 影響の数え方(一斉付与日の変更。個人情報を出さない)

migrationの後、付け替えの前に、新しいDBで数える。

```sql
-- 自動付与の対象になる社員の数(作り直しの対象)
SELECT COUNT(*) AS auto_grant_targets FROM users
 WHERE employment_status = 'active' AND paid_leave_auto_grant_enabled = 1 AND hire_date IS NOT NULL;
-- ルールの付与方式ごとの数
SELECT grant_cycle_type, COUNT(*) FROM paid_leave_grant_rules GROUP BY grant_cycle_type;
-- 年度設定(migrationの初期値)と既定のカレンダー
SELECT company_calendar_id, fiscal_year_start_month, fiscal_year_start_day FROM paid_leave_fiscal_year_settings;
SELECT id, fiscal_year_start_month, fiscal_year_start_day FROM company_calendars WHERE is_default = 1;
```

結果は`~/rehearsal-pr121/impact/`に保存する。「8」の作り直しの出力と合わせて、ユーザーに報告する。

---

## 5. 新しいコード: 付け替え

1. 試し実行:
   `php artisan leave:rekey-paid-leave-streams | tee ~/rehearsal-pr121/dryrun/rekey.txt`
   - 付け替える行の数、取り除く付与予定の行の数(種類別)、旧ID→新IDの対応の件数を記録する。
   - 事前条件に違反があって止まったときは、件数を記録して中止し、報告する。違反には、付与予定の付与済み・判定の上書き・個別修正、`source='scheduled_batch'`の付与がある。
2. `php artisan leave:rekey-paid-leave-streams --apply | tee ~/rehearsal-pr121/after/rekey-apply.txt`
3. `php artisan leave:rekey-paid-leave-streams --verify | tee ~/rehearsal-pr121/after/rekey-verify.txt`(検査が通ること)
4. `php artisan leave:rekey-paid-leave-streams --swap | tee ~/rehearsal-pr121/after/rekey-swap.txt`

---

## 6. 新しいコード: ReadModelの再生と指紋B

1. `php artisan leave:rebuild-event-store --list-readmodels > ~/rehearsal-pr121/after/readmodels.txt`
   (旧コードの一覧に、`paid_leave_fiscal_year_settings`が増える)
2. 一覧の全テーブルを空にし、`php artisan event-sourcing:replay --force`。
3. `php artisan leave:rebuild-event-store --readmodel-fingerprint > ~/rehearsal-pr121/after/fingerprint-B.tsv`
4. 行の単位の差: `php artisan leave:rebuild-event-store --readmodel-diff=flow_office_before --readmodel-diff-json=$HOME/rehearsal-pr121/after/diff.json | tee ~/rehearsal-pr121/after/diff.txt`
   (2つのDBが同じサーバーにあること)

---

## 7. 合否の判定

`diff ~/rehearsal-pr121/before/fingerprint-A.tsv ~/rehearsal-pr121/after/fingerprint-B.tsv`で比べる。

| 表 | 合格の条件 |
|---|---|
| `paid_leave_schedule_entries`・`paid_leave_schedule_assessments` | Bで0件(付け替えで付与予定のイベントを取り除くため)。Aの件数は記録する |
| `paid_leave_fiscal_year_settings` | Aには無い(このリリースで新しくできる表)。Bの行が、下のSQLで既定のカレンダーと一致する |
| 上記以外のすべての表 | 件数・ハッシュがAと一致する |

```sql
-- B(新しいDB)で実行。1行が返り、match が 1 であること
SELECT s.company_calendar_id = c.id AND s.fiscal_year_start_month = c.fiscal_year_start_month
       AND s.fiscal_year_start_day = c.fiscal_year_start_day AS `match`
  FROM paid_leave_fiscal_year_settings s JOIN company_calendars c ON c.is_default = 1;
```

- 指紋の比較は、`created_at`・`updated_at`と、`stored_events.id`を指す列(`stored_event_id`・`last_changed_event_id`)を比べない(`ReadModelFingerprinter::EXCLUDED_COLUMNS`)。
- 上の条件に当てはまらない差が1件でもあれば、合格にしない。`--readmodel-fingerprint-rows=<表>`と`diff.txt`で行を特定し、報告する。
  - 年度設定の表で、カレンダーのID・月日まで違うときも、合格にしない。

---

## 8. 新しいコード: 付与予定の作り直し(3.3)

1. `php artisan paid-leave:schedule:reapply-policy | tee ~/rehearsal-pr121/impact/reapply.txt`
   - 「作り直した社員」と「付与予定日・日数が変わった行」の件数を記録する。「4」の自動付与の対象の数と合うこと。
2. `php artisan paid-leave:roll-schedules | tee ~/rehearsal-pr121/impact/roll.txt`
3. 件数だけを数える:

```sql
SELECT status, COUNT(*) FROM paid_leave_schedule_entries GROUP BY status;
SELECT scheduled_on, COUNT(*) FROM paid_leave_schedule_entries WHERE status = 'scheduled' GROUP BY scheduled_on ORDER BY scheduled_on;
```

- 一斉付与の付与予定日が、既定のカレンダーの年度開始の月日(または前倒しの初回の日)になっていること。
- 画面で確かめるときは、社員名の表示を共有しない。

---

## 9. 結果の記録欄

| 項目 | 結果 |
|---|---|
| 実施日・実施者 | |
| 旧コードのcommit / 新しいコードのcommit | |
| MySQLのバージョン・タイムゾーン | |
| 1-4 `stored_events`の件数 | |
| 2 基準Aの再生(成功/失敗) | |
| 3 migration(適用した件数・警告の内容) | |
| 4 自動付与の対象の数・付与方式ごとの数 | |
| 5 付け替え(付け替えた行・取り除いた行・対応の件数・`--verify`) | |
| 6 再生(成功/失敗・所要時間) | |
| 7 判定(付与予定の2表の件数A→B、年度設定の一致、その他の表の差の件数) | |
| 8 作り直し(作り直した社員・変わった行)・`roll-schedules`の件数 | |
| backendの全件のテスト(CIと同じ条件。完走/止まった場合は終了コード・シグナル・PHPのバージョン) | |
| frontendのテスト・build | |
| E2E(`scenario-00`・`03`・`08`・`15`。ポートが空いている環境) | |
| 合否と、ユーザーへの報告の日時 | |

---

## 10. 後片付け
- 2つのDB・保存した出力・一時的な複製は、比較の結果を報告した後に削除する。
- 元のダンプは変更していないことを確かめる(SSOの設定が残っているので、再び使うときは「1-2」から行う)。
