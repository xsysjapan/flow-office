# リリース前リハーサル手順書(変更セット 20261009-keep-leave-work-type-on-edit)

本番相当データ(本番DBの複製)で、休暇まわりの`stored_events`の作り直し(論点18)の試し実行・本実行・複製DBでの検証・
入れ替え・リビルド・再計算・ロールバックを通しで行うための手順書。
ユーザーがこの手順のとおりに実行し、結果を「8. 結果の記録欄」に書き込むことを想定する。

- 対象の変更: PR #115(draft)。head ブランチ `claude/claude-md-delegation-rules`。**WP10(変換コマンド・旧系統の削除)の実装後**に行う。
  実施時の commit を「1-2」に記録する。
- 根拠: `spec.md`(論点12・13・17・18、仕様確定事項H)、`assets/event-rebuild-mapping.md`(変換規則と7章の実行手順)、
  `.claude/skills/data-correction/SKILL.md`(「`stored_events`の作り直しの進め方」)、`docs/27-release-runbook.md` 3.1。
- 方針: 移行イベント・補正イベントは作らない。旧イベント列から新しいイベント構成の列を決定的に生成し、元の発生順にid・版を振り直した
  表を作り、複製DBで検証してから入れ替える。移行コマンド・補正候補の検出コマンドは使わない(WP10で削除)。
- 変換コマンドの名前 `leave:rebuild-event-store` とオプション(`--apply`・`--swap`)は仮称(`event-rebuild-mapping.md` 7章)。
  WP10の実装に合わせて読み替える。
- 表記: **要確認** は、コードや既存資料から確定できず、実施前にユーザーまたは委譲元が決める箇所。
- 本番DBへの書き込みは、この手順書の対象外。本番での作り直しは、変換規則と試し実行の結果を示してユーザーの明示的な許可を得てから行う
  (data-correction「本番での実行にはユーザーの明示的な許可を得る」)。

---

## 0. 安全ルール(全体に共通)

### 0-1. 接続先の確認(SQLを実行するたびに最初に行う)

```sql
SELECT DATABASE() AS db, VERSION() AS mysql_version, @@hostname AS host;
```

期待: `db` がリハーサル用DB名。この手順では2つのDBを使う。

| DB名 | 役割 |
|---|---|
| `flow_office_rehearsal` | 本番役。作り直しの試し実行・`--apply`・`--swap`・リビルド・再計算を行う |
| `flow_office_verify` | 複製DB。`stored_events_rebuilt`を`stored_events`として入れ、リビルドして比較する(3-5) |

本番のDB名だった場合は、そこで中止する。

### 0-2. リハーサル環境で通知・外部連携を止める(リビルド・再計算の前に行う)

`event-sourcing:replay` ではReactorは走らないが、日次計算の再計算(`attendance:recalculate-days`)はCommandを通るため、
同期Reactor・通知が動く可能性がある。DBの複製には本番の設定が入っているため、そのままでは本番利用者へメールが送信される可能性がある。

```sql
-- メール通知(GraphMailNotifier)を止める。送信先の設定はsystem_settingsにある(要確認: 本番のTeams・Entra設定の有無)
UPDATE system_settings SET notification_mail_enabled = 0;
```

- `.env` は `MAIL_MAILER=log` にする。
- キューワーカー(`php artisan queue:work`)を起動しない。cron を設定しない。
- `SyncUsersFromMs365Command`・freee/MoneyForward連携・定時の付与コマンド(`GrantScheduledSpecialLeaveCommand`等)は実行しない。
- `flow_office_verify` にも同じ設定を行う。

### 0-3. 出力の保存

すべての出力は `~/rehearsal-20261009/` 配下に保存する(リポジトリの外。git 管理しない)。
本手順書の `mysql ... -B -e` の出力は、タブ区切り・ヘッダ付きで保存される。

```bash
mkdir -p ~/rehearsal-20261009/{before,dryrun,verify,after,rebuild,backup}
```

### 0-4. SQLの互換性

SQLは MySQL 5.7 で動く形に書いた(CTE・ウィンドウ関数は使わない)。本番のMySQLバージョンは要確認。
`mysql` コマンドは次の形で実行する。

```bash
mysql -h <リハーサルホスト> -u <ユーザー> -p -D flow_office_rehearsal -B -e "<SQL>" > <保存先>.tsv
```

---

## 1. 準備

### 1-1. 本番DBの複製

既存の運用手順(複製の専用手順)は無い。以下は一般的な手順で、**要確認**(本番の接続方式・権限・MySQLバージョン)。

```bash
# 本番側: 読み取りのみ(書き込みしない)
mysqldump --single-transaction --routines --triggers --no-tablespaces \
  -h <本番ホスト> -u <ユーザー> -p <本番DB名> | gzip > ~/rehearsal-20261009/prod-copy.sql.gz

# リハーサル用DB(本番役)へ投入
mysql -h <リハーサルホスト> -u <ユーザー> -p -e \
  "CREATE DATABASE flow_office_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c ~/rehearsal-20261009/prod-copy.sql.gz | mysql -h <リハーサルホスト> -u <ユーザー> -p flow_office_rehearsal
```

- 複製の時刻は、本番の休暇の申請・承認・付与取消を止めた時点が望ましい。
- 複製ファイルには個人情報・認証情報が含まれる。保存先と削除の方法はユーザーの規程に従う。
- 投入後、0-1 で接続先を確認する。
- `stored_events` の件数を本番側で控えておく(2-B15 の値と照合する)。

### 1-2. 配備するブランチ

- ブランチ: `claude/claude-md-delegation-rules`(PR #115 の head。WP10を含むこと)。
- 実施時の commit: `git rev-parse HEAD` の値を記録する。 ____________________
- 旧コード(本番で動いている origin/main 系)の commit も記録する(5-6 のロールバックで再配備する)。 ____________________

### 1-3. PHPとComposer

```bash
cd <リハーサル用アプリ>/backend
composer install --no-dev --optimize-autoloader --no-interaction
```

- PHP は 8.4 を使う(`composer.lock` が PHP 8.4.1 以上を要求する。docs/27 §8)。

### 1-4. `.env`

```bash
cp .env.example .env
```

- `DB_CONNECTION=mysql`、`DB_DATABASE=flow_office_rehearsal` など、リハーサル用DBの接続情報を設定する。
- `APP_KEY` は**本番の値を設定する**。DBの暗号化された値(`system_settings`の認証情報等)を復号するため、
  `php artisan key:generate` を実行すると復号できなくなる。**要確認**: 暗号化カラムの一覧。
- `APP_ENV=production`、`APP_DEBUG=false`(docs/27 §3 の .env 設定に従う)。
- `MAIL_MAILER=log`(0-2)。
- 複製DBでの検証(3-5)用に、`DB_DATABASE=flow_office_verify` の `.env` を持つ別のアプリ配置(または同じ配置で `.env` を切り替える)を用意する。

```bash
php artisan config:clear && php artisan route:clear && php artisan view:clear
```

### 1-5. 作り直し前の記録

**作り直し・migrate の前に**、2 の記録をすべて取得する。作り直しの後では旧イベント・旧スキーマの値を取り直せない
(元の表は `stored_events_before_rebuild_*` として残るが、ReadModelは作り直される)。

---

## 2. 作り直し前の記録(作り直し・migrate の前に、旧スキーマのDBで実行)

各SQLを `~/rehearsal-20261009/before/` に保存する。比較の基準として、3-5(複製DB)と6章で同じSQLを作り直し後に再実行する。
**このSQLは旧スキーマ(migrate前)で取得する。** 以下のテーブル・列はマイグレーション前から存在するものだけを使っている
(作り直し後も同じ列が残る想定。WP10で削除する列は使っていない)。

### B01. 月次スナップショット(提出以降の月)

`attendance_months.snapshot_json` は提出時の集計(`MonthlyOvertimeCalculator::calculateCategoryTotals` + `day_count`)。
キー名は `MonthlyOvertimeCalculator` の戻り値と `SubmitAttendanceMonthHandler::buildSnapshot` に従う。

```sql
SELECT m.user_id, m.year_month, m.status,
       JSON_EXTRACT(m.snapshot_json, '$.day_count')                AS day_count,
       JSON_EXTRACT(m.snapshot_json, '$.prescribed_work_minutes')  AS prescribed_work_minutes,
       JSON_EXTRACT(m.snapshot_json, '$.work_minutes')             AS work_minutes,
       JSON_EXTRACT(m.snapshot_json, '$.payroll_work_minutes')     AS payroll_work_minutes,
       JSON_EXTRACT(m.snapshot_json, '$.paid_leave_days')          AS paid_leave_days,
       JSON_EXTRACT(m.snapshot_json, '$.paid_leave_minutes')       AS paid_leave_minutes,
       JSON_EXTRACT(m.snapshot_json, '$.special_leave_days')       AS special_leave_days,
       JSON_EXTRACT(m.snapshot_json, '$.special_leave_minutes')    AS special_leave_minutes,
       JSON_EXTRACT(m.snapshot_json, '$.absence_days')             AS absence_days
FROM attendance_months m
WHERE m.snapshot_json IS NOT NULL
ORDER BY m.user_id, m.year_month;
```

- 給与連携(freee の `total_normal_work_mins` = `prescribed_work_minutes`、`num_paid_holidays` = `paid_leave_days`。
  `FreeeAttendanceApiPayloadBuilder`)と Excel・CSV は、このスナップショットを読む。

### B02. 月次の日次集計(提出前の月も含む。ライブ集計の基準)

```sql
SELECT d.user_id,
       DATE_FORMAT(d.work_date, '%Y-%m')          AS year_month,
       COUNT(d.id)                                AS day_count,
       COUNT(c.id)                                AS calculated_days,
       SUM(c.prescribed_work_minutes)             AS prescribed_work_minutes,
       SUM(c.work_minutes)                        AS work_minutes,
       SUM(c.payroll_work_minutes)                AS payroll_work_minutes,
       SUM(c.paid_leave_days)                     AS paid_leave_days,
       SUM(c.paid_leave_minutes)                  AS paid_leave_minutes,
       SUM(c.special_leave_days)                  AS special_leave_days,
       SUM(c.special_leave_minutes)               AS special_leave_minutes
FROM attendance_days d
LEFT JOIN attendance_daily_calculations c ON c.attendance_day_id = d.id
GROUP BY d.user_id, DATE_FORMAT(d.work_date, '%Y-%m')
ORDER BY d.user_id, year_month;
```

- **要確認**: `attendance_daily_calculations.attendance_day_id` が1日1行か(重複があると合計が増える)。

### B03. 有給付与(利用者ごと・付与ごと)

```sql
-- 利用者ごとの集計
SELECT user_id, status, COUNT(*) AS grants,
       SUM(granted_days) AS granted_days, SUM(allocated_days) AS allocated_days, SUM(remaining_days) AS remaining_days
FROM paid_leave_grants
GROUP BY user_id, status
ORDER BY user_id, status;

-- 付与ごと(保存して6-2で比較)
SELECT id, user_id, granted_on, expires_on, granted_days, allocated_days, remaining_days, status, source
FROM paid_leave_grants
ORDER BY id;
```

### B04. 特別休暇の付与(付与ごと)

```sql
SELECT id, user_id, special_leave_type_id, granted_on, expires_on,
       granted_days, used_days, remaining_days, status, grant_reason
FROM special_leave_grants
ORDER BY id;
```

### B05. 代休の付与(付与ごと。下書きを含む)

```sql
SELECT status, COUNT(*) AS grants, SUM(granted_days) AS granted_days, SUM(remaining_days) AS remaining_days
FROM compensatory_leave_grants
GROUP BY status;

SELECT id, user_id, source, work_date, granted_days, granted_minutes, used_days, used_minutes,
       remaining_days, remaining_minutes, status, expires_on, confirmed_at
FROM compensatory_leave_grants
ORDER BY id;
```

### B06. 残高キャッシュ(有給)

```sql
SELECT user_id, available_days, pending_days, unallocated_days, next_grant_scheduled_on
FROM paid_leave_balances
ORDER BY user_id;
```

### B07. 消化記録

```sql
-- 有給(取消されていないもの)。cutover後の行は cancelled 列、旧系統の行は cancelled が false のまま(要確認)
SELECT user_id, COUNT(*) AS usages, SUM(used_days) AS used_days
FROM paid_leave_usages
WHERE cancelled = 0
GROUP BY user_id ORDER BY user_id;

-- 特別休暇(行が存在するもの。取消は行の削除)
SELECT user_id, COUNT(*) AS usages, SUM(used_days) AS used_days
FROM special_leave_usages
GROUP BY user_id ORDER BY user_id;

-- 代休
SELECT user_id, COUNT(*) AS usages, SUM(used_days) AS used_days
FROM compensatory_leave_usages
GROUP BY user_id ORDER BY user_id;

-- 特別・代休の消化記録(個別)
SELECT 'special' AS kind, id, user_id, special_leave_request_id AS request_id, special_leave_grant_id AS grant_id,
       used_on, used_days, used_minutes, usage_type, is_confirmed
FROM special_leave_usages
UNION ALL
SELECT 'compensatory', id, user_id, compensatory_leave_request_id, compensatory_leave_grant_id,
       used_on, used_days, used_minutes, usage_type, is_confirmed
FROM compensatory_leave_usages
ORDER BY kind, id;
```

### B08. 休暇申請の状態

```sql
SELECT 'paid' AS kind, status, COUNT(*) AS requests FROM paid_leave_requests GROUP BY status
UNION ALL SELECT 'special', status, COUNT(*) FROM special_leave_requests GROUP BY status
UNION ALL SELECT 'compensatory', status, COUNT(*) FROM compensatory_leave_requests GROUP BY status;

-- 申請ごと(保存して6-3の照合に使う)
SELECT 'paid' AS kind, id, user_id, target_date, leave_type, requested_days, status FROM paid_leave_requests
UNION ALL
SELECT 'special', id, user_id, target_date, leave_type, requested_days, status FROM special_leave_requests
UNION ALL
SELECT 'compensatory', id, user_id, target_date, leave_type, requested_days, status FROM compensatory_leave_requests
ORDER BY kind, target_date, id;
```

### B09. cutover前の有給8件(2026-08-10〜2026-09-04)と旧イベント

spec の確認(2026-10-10)では、全休7件・午後半休1件、すべて承認済み・取消なし。

```sql
-- 対象の申請(8件になること)
SELECT id, user_id, target_date, leave_type, hours, requested_days, status, approved_at
FROM paid_leave_requests
WHERE target_date BETWEEN '2026-08-10' AND '2026-09-04'
ORDER BY target_date, id;

-- 旧系統の有給イベント(件数。各 8 件を想定)
SELECT event_class, COUNT(*) AS events, MIN(created_at) AS first_at, MAX(created_at) AS last_at
FROM stored_events
WHERE event_class LIKE 'paid_leave.%'
GROUP BY event_class
ORDER BY event_class;
```

### B10. 有給の付与予定と出勤率判定(保存された判定結果)

```sql
SELECT e.user_id, e.scheduled_on, e.status AS entry_status,
       a.period_start, a.period_end, a.denominator_days, a.attendance_days, a.excluded_days,
       a.attendance_rate, a.automatic_result, a.final_result, a.policy_version
FROM paid_leave_schedule_assessments a
JOIN paid_leave_schedule_entries e ON e.id = a.schedule_entry_id
ORDER BY e.user_id, e.scheduled_on, a.period_start;
```

### B11. 特別休暇の自動付与(出勤率を満たして付与されたもの)

```sql
SELECT user_id, special_leave_type_id, granted_on, granted_days, status, grant_reason
FROM special_leave_grants
WHERE grant_reason LIKE '自動付与%'
ORDER BY granted_on, user_id;
```

### B12. 休暇値・全休の状態(勤怠日)

```sql
-- (4)の基準: 勤怠日に残る休暇値
SELECT work_type, status, source, COUNT(*) AS days
FROM attendance_days
WHERE work_type REGEXP '^(paid|special|compensatory)_leave_'
GROUP BY work_type, status, source
ORDER BY work_type, status, source;

-- (4)の基準: 実績も打刻も無い clocked_out(全休の出勤扱い)
SELECT d.work_type, COUNT(*) AS days
FROM attendance_days d
WHERE d.status = 'clocked_out' AND d.actual_start_at IS NULL AND d.actual_end_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM attendance_punches p WHERE p.user_id = d.user_id AND p.work_date = d.work_date)
GROUP BY d.work_type
ORDER BY days DESC;

-- (2)の基準: 勤怠日の最初のイベントに attendance_day.created が無い日(prerequisites の (a1)(a2))。作り直しで created を補う日
SELECT COUNT(*) AS no_created,
       SUM(EXISTS(SELECT 1 FROM stored_events e
                  WHERE e.aggregate_uuid = d.id AND e.event_class LIKE 'attendance_day.%')) AS with_other_events
FROM attendance_days d
WHERE NOT EXISTS (SELECT 1 FROM stored_events c
                  WHERE c.aggregate_uuid = d.id AND c.event_class = 'attendance_day.created');

-- (3)の基準: 編集イベントに入った休暇値
SELECT event_class, COUNT(*) AS events
FROM stored_events
WHERE event_class IN ('attendance_day.created', 'attendance_day.edited')
  AND JSON_UNQUOTE(JSON_EXTRACT(event_properties, '$.workType')) REGEXP '^(paid|special|compensatory)_leave_'
GROUP BY event_class;
```

- 注意: (2)の`no_created`は目安。正式な件数は作り直しの試し実行(3-2)の「`created`を補う勤怠日」の件数を使う。

### B13. 休暇に紐づく申請(統合ワークフロー)の状態

```sql
SELECT subject_type, status, COUNT(*) AS workflows
FROM workflow_requests
WHERE subject_type IN ('paid_leave_request', 'special_leave_request', 'compensatory_leave_request')
GROUP BY subject_type, status
ORDER BY subject_type, status;
```

### B14. 月次の状態

```sql
SELECT status, COUNT(*) AS months FROM attendance_months GROUP BY status ORDER BY status;
```

### B15. イベントの件数(作り直しで変わる件数の基準)

```sql
SELECT COUNT(*) AS stored_events_total, MAX(id) AS max_id FROM stored_events;

SELECT SUBSTRING_INDEX(event_class, '.', 1) AS prefix, COUNT(*) AS events
FROM stored_events
WHERE event_class REGEXP '^(paid_leave|special_leave|compensatory_leave|workflow_request|attendance_day)'
GROUP BY prefix
ORDER BY prefix;
```

### 2の見方

- B09 の申請が 8 件でなければ、spec の前提(2026-10-10 の確認)と本番の状態が違う。作り直しの前に止めて確認する。
- B01〜B15 は、3-5(複製DB)と6-2の比較の基準になる。差分は6-2の「期待する差分」(変換規則で意図した差)と照合する。

---

## 3. 作り直しの試し実行・本実行・複製DBでの検証(`event-rebuild-mapping.md` 7章の1〜5)

変換コマンド `leave:rebuild-event-store`(仮称)は、旧イベントクラスに依存せず `stored_events` の生のJSONを読む。
3-1〜3-4 は `flow_office_rehearsal`(本番役・旧スキーマのまま)で、3-5 は `flow_office_verify` で行う。

- **要確認 R1**: 変換コマンドは新しいコードに含まれる。旧スキーマのDB(migrate前)に対して実行できること(読むのは
  `stored_events` だけで、新しいテーブルを前提にしないこと)をWP10の実装で確認する。

### 3-1. 事前条件の確認

試し実行が自動で確認するが、同じ内容をSQLでも確認して記録する。

```sql
-- (1) 新しい種類のイベントが0件(期待: 0行)。paid_leave_account.* のうち新しい種類はWP10の一覧で確認する(要確認)
SELECT event_class, COUNT(*) AS events
FROM stored_events
WHERE event_class LIKE 'paid_leave_request.%'
   OR event_class LIKE 'special_leave_account.%'
   OR event_class LIKE 'compensatory_leave_account.%'
GROUP BY event_class;

-- (2) 補正ログが0件(テーブルが無ければ0件として扱う。本番の旧スキーマには無い想定)
SELECT COUNT(*) AS corrections FROM stored_event_corrections;

-- (3) スナップショットが0件(要確認: spatie のスナップショットのテーブル名)
SELECT COUNT(*) AS snapshots FROM snapshots;

-- (4) cutover のイベントの件数(2.3の突き合わせの対象)
SELECT COUNT(*) AS cutover_events, MIN(created_at) AS first_at, MAX(created_at) AS last_at
FROM stored_events
WHERE event_class = 'paid_leave_account.migrated';
```

- (1)(2)(3) のいずれかが0件でなければ、作り直しは行わない(試し実行も失敗する)。補正ログがある場合は旧id→新idの対応表を
  残す必要がある(`event-rebuild-mapping.md` 6章)ため、委譲元に報告する。

### 3-2. 試し実行(既定)

```bash
cd <リハーサル用アプリ>/backend
php artisan leave:rebuild-event-store | tee ~/rehearsal-20261009/dryrun/rebuild-dryrun.txt
```

見方(`event-rebuild-mapping.md` 7章の2):

| 出力 | 期待 | 違った時 |
|---|---|---|
| 申請・付与・勤怠日ごとの変換結果の件数(旧イベント数 → 新イベント数) | 件数を記録する | 2.1・2.2・2.4 の規則で説明できない増減があれば止める |
| 扱えない並びの一覧(申請・付与・勤怠日ごと) | **0件**(1件でもあれば試し実行は失敗する) | 推測で埋めない。並びごとに原因を確認し、変換規則を決めて変更セットを更新してから再実行する |
| cutover の付与の突き合わせ(再現・`carried_over`・不一致) | 不一致 **0件**。`carried_over` の件数と内容を記録する | 不一致の付与は原因を確認し、変換規則を決めてから進む(2.3) |
| 差戻し/取消/却下の判定結果(申請ごと) | 下のSQLのワークフローの状態と一致する | 一致しない申請を個別に確認する |
| `created` を補う勤怠日(2.4)と、そのうち `source=leave` にする日 | B12 の `no_created` と照合する | 差があれば理由を確認する |
| 休暇値を書き換える `created`/`edited`(2.4) | B12 の (3) の件数と一致する | 差があれば理由を確認する |
| 置く `attendance_day.deleted`(休暇の解除で空になった勤怠日、後続のイベントが無いもの) | 件数を記録する | 後続のイベントがあって置かなかった日も一覧で確認する |
| 既定値nullの項目の補完(2.5) | 補完できない項目0件 | 補完元が無いものは扱えない並びとして扱う |

差戻し/取消/却下の判定と突き合わせるワークフローの状態(旧スキーマ。`workflow_requests` の `subject_type`・`subject_id`):

```sql
SELECT w.subject_type, w.status AS workflow_status,
       COALESCE(p.status, s.status, c.status) AS request_status, COUNT(*) AS requests
FROM workflow_requests w
LEFT JOIN paid_leave_requests p         ON w.subject_type = 'paid_leave_request'         AND p.id = w.subject_id
LEFT JOIN special_leave_requests s      ON w.subject_type = 'special_leave_request'      AND s.id = w.subject_id
LEFT JOIN compensatory_leave_requests c ON w.subject_type = 'compensatory_leave_request' AND c.id = w.subject_id
WHERE w.subject_type IN ('paid_leave_request', 'special_leave_request', 'compensatory_leave_request')
GROUP BY w.subject_type, w.status, COALESCE(p.status, s.status, c.status)
ORDER BY w.subject_type, w.status;
```

- `workflow_status = rejected` かつ `request_status = submitted` の件数 = 却下として `*.cancelled`・`usage_cancelled` にする件数。
- `workflow_status = returned` の件数 = 差戻しの状態で終わる申請の件数(cutover後の有給の差戻しはワークフローのイベントから組み立てる)。
- `request_status` が NULL の行(業務側の申請が見つからないワークフロー)は件数を記録し、試し実行の扱えない並びと照合する。

### 3-3. 書き込みを止める(本番の手順の模擬)とバックアップ

本番では次を止める。リハーサルでは各操作の時刻を記録し、5-6 の再開までの時間(メンテナンス窓)を測る。

1. メンテナンスモード: `php artisan down`
2. スケジューラ(cron)・キューワーカーの停止
3. 打刻端末・外部API・MCP(`mcp/`)からの受付の停止

停止の後、DB全体のバックアップを取る(5-7 のロールバックで使う。ReadModelもスキーマも含む)。

```bash
mysqldump --single-transaction --routines --triggers --no-tablespaces \
  -h <リハーサルホスト> -u <ユーザー> -p flow_office_rehearsal | gzip > ~/rehearsal-20261009/backup/before-rebuild.sql.gz
```

### 3-4. `--apply`(新しい表 `stored_events_rebuilt` に書く)

```bash
php artisan leave:rebuild-event-store --apply | tee ~/rehearsal-20261009/dryrun/rebuild-apply.txt
```

- 休暇以外のイベントも同じ順で写し、id・版を振り直す。`stored_events` は変わらない。
- 出力の件数が 3-2 の試し実行と一致することを確認する。

確認のSQL:

```sql
-- (1) 件数(作り直し前と後)
SELECT (SELECT COUNT(*) FROM stored_events) AS before_total,
       (SELECT COUNT(*) FROM stored_events_rebuilt) AS rebuilt_total,
       (SELECT MAX(id) FROM stored_events_rebuilt) AS rebuilt_max_id;

-- (2) 接頭辞ごとの件数(作り直し前と後を並べて保存する)
SELECT SUBSTRING_INDEX(event_class, '.', 1) AS prefix, COUNT(*) AS events FROM stored_events GROUP BY prefix ORDER BY prefix;
SELECT SUBSTRING_INDEX(event_class, '.', 1) AS prefix, COUNT(*) AS events FROM stored_events_rebuilt GROUP BY prefix ORDER BY prefix;

-- (3) 集約ごとの版が1から欠けなく連番(期待: 0行)
SELECT aggregate_uuid, COUNT(*) AS n, MIN(aggregate_version) AS min_v, MAX(aggregate_version) AS max_v
FROM stored_events_rebuilt
WHERE aggregate_uuid IS NOT NULL
GROUP BY aggregate_uuid
HAVING MIN(aggregate_version) <> 1 OR MAX(aggregate_version) <> COUNT(*);

-- (4) (aggregate_uuid, aggregate_version) の重複(期待: 0行)
SELECT aggregate_uuid, aggregate_version, COUNT(*) AS n
FROM stored_events_rebuilt
WHERE aggregate_uuid IS NOT NULL
GROUP BY aggregate_uuid, aggregate_version
HAVING COUNT(*) > 1;

-- (5) id順に並べたときの発生日時の逆転の数(作り直し前と後を比べる。増えていれば理由を確認する)
SELECT COUNT(*) AS inversions FROM stored_events a JOIN stored_events b ON b.id = (SELECT MIN(id) FROM stored_events WHERE id > a.id)
WHERE b.created_at < a.created_at;
SELECT COUNT(*) AS inversions FROM stored_events_rebuilt a JOIN stored_events_rebuilt b ON b.id = a.id + 1
WHERE b.created_at < a.created_at;

-- (6) 変換対象外のイベントが、種類・集約・版・発生日時・内容とも変わっていない(期待: 0)
SELECT COUNT(*) AS changed_or_missing
FROM stored_events o
WHERE o.event_class NOT REGEXP '^(paid_leave|special_leave|compensatory_leave|attendance_day)'
  AND NOT EXISTS (SELECT 1 FROM stored_events_rebuilt n
                  WHERE n.aggregate_uuid <=> o.aggregate_uuid
                    AND n.aggregate_version <=> o.aggregate_version
                    AND n.event_class = o.event_class
                    AND n.created_at = o.created_at
                    AND n.event_properties = o.event_properties);
```

- (5) の作り直し前のSQLは、元の id が飛び飛びでも動くように書いた(件数が多いと遅い。**要確認**: 所要時間)。
- (6) は `meta_data` を比べない(`aggregate-root-version` 等が更新されるため。6章)。`workflow_request.*` は変換の入力に使うが
  書き換えないので、(6) の対象に含まれる。

### 3-5. 複製DBでの検証(`flow_office_verify`)

作り直し後の表から全Projectorをリビルドし、作り直し前のReadModel(2章の記録)と比べる。差が「意図した差」だけであることを確認する。

```bash
# 3-4 の後の flow_office_rehearsal(stored_events_rebuilt を含む)を複製する
mysqldump --single-transaction --routines --triggers --no-tablespaces \
  -h <リハーサルホスト> -u <ユーザー> -p flow_office_rehearsal | gzip > ~/rehearsal-20261009/verify/verify-src.sql.gz
mysql -h <リハーサルホスト> -u <ユーザー> -p -e \
  "CREATE DATABASE flow_office_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c ~/rehearsal-20261009/verify/verify-src.sql.gz | mysql -h <リハーサルホスト> -u <ユーザー> -p flow_office_verify
```

```sql
-- flow_office_verify で実行する(0-1 で接続先を確認してから)
RENAME TABLE stored_events TO stored_events_original, stored_events_rebuilt TO stored_events;
```

以降は `DB_DATABASE=flow_office_verify` の `.env` の新しいコードで、5-3〜5-4 と同じ手順を行う(migrate・外部キーの確認・権限カタログ・
全ReadModelを空にして全Projectorをリビルド)。続けて、5-5 の日次計算の再計算を試し実行で行い、変わる日の一覧を保存する。

比較:

1. 2章の B01〜B15 を `flow_office_verify` で実行し、`~/rehearsal-20261009/verify/` に保存する。
2. `diff ~/rehearsal-20261009/before/B02.tsv ~/rehearsal-20261009/verify/B02.tsv` のように差分を取る。
3. 差分を6-2の「期待する差分」と照合する。6-3 の照合も `flow_office_verify` で行う。
4. 説明できない差分が1件でもあれば、5章(入れ替え)に進まない。

---

## 4. 確認事項(a)〜(h)(spec「実装中の決定」のリハーサル確認事項を作り直しに合わせて改めたもの)

各項目は、作り直し前の記録(2)・試し実行(3-2)・複製DBでの検証(3-5)の結果で確認する。SQLは作り直しの前(旧スキーマ)に実行する。

### (a) 現行で残数不足のまま承認された消化記録

- 作り直しでの扱い: 承認は `*.used` の有無にかかわらず `usage_confirmed` にし、充当は `*.used`・`usage_allocated` がある分だけ。
  残りは未充当量(申請日数 − 充当合計)として確定イベントに記録する(`event-rebuild-mapping.md` 2.1・5章、論点17)。
- 確認: 件数と不足量を記録し、3-5 の消化記録(`unallocated_days`)と一致するか見る。

```sql
-- 特別休暇
SELECT r.id, r.user_id, r.target_date, r.requested_days, COALESCE(SUM(u.used_days), 0) AS allocated_days
FROM special_leave_requests r
LEFT JOIN special_leave_usages u
       ON u.special_leave_request_id = r.id AND u.special_leave_grant_id IS NOT NULL
WHERE r.status = 'approved'
GROUP BY r.id, r.user_id, r.target_date, r.requested_days
HAVING COALESCE(SUM(u.used_days), 0) < r.requested_days - 0.001
ORDER BY r.target_date;

-- 代休(取得単位が時間の申請は(b)で見る)
SELECT r.id, r.user_id, r.target_date, r.requested_days, COALESCE(SUM(u.used_days), 0) AS allocated_days
FROM compensatory_leave_requests r
LEFT JOIN compensatory_leave_usages u
       ON u.compensatory_leave_request_id = r.id AND u.is_confirmed = 1 AND u.compensatory_leave_grant_id IS NOT NULL
WHERE r.status = 'approved' AND r.leave_type <> 'hourly'
GROUP BY r.id, r.user_id, r.target_date, r.requested_days
HAVING COALESCE(SUM(u.used_days), 0) < r.requested_days - 0.001
ORDER BY r.target_date;
```

- 特別休暇のうち残数を要しない種別は `*.used` が出ないため、この件数に入る。作り直しでは `*.used` が無い申請を「残数を要しない」
  (`requiresGrant=false`、未充当量0)と判定する(5章)。**要確認 R7**: 残数を要する種別で `*.used` が無い申請(残数0で承認されたもの)が
  あると、この判定で「要しない」に分類される。種別の現在の設定と突き合わせ、該当があれば変換規則を決める。

### (b) 時間単位で分数が null の申請・消化記録

- 作り直しでの扱い: 時間休の `hours` は旧 `usedMinutes`÷60、代休の分数は旧 `used` の `usedMinutes`・申請の `requestedMinutes` から求める
  (5章)。求められない申請は扱えない並びとして試し実行が失敗する想定(**要確認 R8**: WP10の実装での扱い)。

```sql
-- 期待: 0件
SELECT 'special' AS kind, COUNT(*) AS requests FROM special_leave_requests
WHERE leave_type = 'hourly' AND hours IS NULL AND status IN ('submitted', 'approved')
UNION ALL
SELECT 'compensatory', COUNT(*) FROM compensatory_leave_requests
WHERE leave_type = 'hourly' AND requested_minutes IS NULL AND status IN ('submitted', 'approved');

-- 消化記録の側(期待: 0件)
SELECT 'special' AS kind, COUNT(*) FROM special_leave_usages WHERE usage_type = 'hourly' AND used_minutes IS NULL
UNION ALL
SELECT 'compensatory', COUNT(*) FROM compensatory_leave_usages WHERE usage_type = 'hourly' AND used_minutes IS NULL;
```

- 違った時に決めること: 件数が 1 以上なら、正しい分数をどう決めるかをユーザーに確認し、変換規則に加えてから進む。

### (c) 代休の付与一覧の残数表示(下書きを含む)

- 確認: 現行の残数表示は下書き(`draft`)の付与を含む。作り直し後も同じに保つか。
- 期待: 件数と残数の合計を記録する(B05)。3-5 の B05 と一致する。
- 違った時に決めること: 「下書きを含む」を維持する(仕様「表示用Projectorで対応」)か、表示から外すか。
  判断はユーザーが行う(変更セットの決定に反する場合は変更セットを更新)。

### (d) 全Projectorのリビルドの所要時間と行数

- 確認: 5-4(と 3-5)の全ReadModelのリビルドの所要時間と、主なテーブルの行数。
- 期待:
  - `attendance_day_leaves` の行数 = 休暇申請の総数(差戻し・取消の行も `request_status` を変えて残るため)。
  - 所要時間(5-4 のリビルド+5-5 の再計算): 本番のメンテナンス窓に収まるか。収まらない場合は、ユーザーがメンテナンス窓の長さを判断する。

```sql
SELECT leave_kind, request_status, COUNT(*) AS rows_count
FROM attendance_day_leaves
GROUP BY leave_kind, request_status
ORDER BY leave_kind, request_status;
```

- 違った時に決めること: 行数の不一致は6-3の照合で差分を特定する。

### (e) cutover後の `paid_leave_account.usage_designated` で `paidLeaveRequestId` が null のもの

- 作り直しでの扱い: cutover後の有給は口座イベントを申請ごとに組み立てる(2.1)ため、申請IDの無い消化記録は申請に結び付けられない。
  扱えない並びとして試し実行が失敗する想定(**要確認 R8**)。

```sql
-- 期待: 0件
SELECT COUNT(*) AS without_request_id
FROM stored_events
WHERE event_class = 'paid_leave_account.usage_designated'
  AND (JSON_EXTRACT(event_properties, '$.paidLeaveRequestId') IS NULL
       OR JSON_TYPE(JSON_EXTRACT(event_properties, '$.paidLeaveRequestId')) = 'NULL');

-- 内容の確認(件数が少ない場合)
SELECT id, aggregate_uuid, created_at,
       JSON_UNQUOTE(JSON_EXTRACT(event_properties, '$.usageId'))  AS usage_id,
       JSON_UNQUOTE(JSON_EXTRACT(event_properties, '$.usedOn'))   AS used_on,
       JSON_UNQUOTE(JSON_EXTRACT(event_properties, '$.usedDays')) AS used_days
FROM stored_events
WHERE event_class = 'paid_leave_account.usage_designated'
  AND (JSON_EXTRACT(event_properties, '$.paidLeaveRequestId') IS NULL
       OR JSON_TYPE(JSON_EXTRACT(event_properties, '$.paidLeaveRequestId')) = 'NULL')
ORDER BY id;
```

- **要確認**: `usedOn`・`usedDays` のキー名は `PaidLeaveAccountUsageDesignated` のプロパティ名で推定した。結果が空なら、キー名を確認する。
- 違った時に決めること: 件数が 1 以上なら、対応する申請を特定する方法(対象日・利用者での突き合わせ等)をユーザーと決め、変換規則に加える。

### (f) 承認済み・未充当の消化記録の未充当量

- 作り直しでの扱い: 未充当量は 0 として扱わず、申請日数 − 充当合計を記録する(a)。cutover前の有給8件は、cutoverの付与を旧付与と
  突き合わせて旧イベントで再現できれば(2.3)、充当まで再現されるため未充当にならない。
- 有給の確認(旧スキーマ):

```sql
-- cutover前の8件(B09)は消化記録が無いため出る。それ以外は件数を記録する
SELECT r.id, r.user_id, r.target_date, r.requested_days, COALESCE(SUM(a.allocated_days), 0) AS allocated_days
FROM paid_leave_requests r
LEFT JOIN paid_leave_usages u ON u.paid_leave_request_id = r.id AND u.cancelled = 0
LEFT JOIN paid_leave_usage_allocations a ON a.usage_id = u.usage_id
WHERE r.status = 'approved'
GROUP BY r.id, r.user_id, r.target_date, r.requested_days
HAVING COALESCE(SUM(a.allocated_days), 0) < r.requested_days - 0.001
ORDER BY r.target_date;
```

- 期待: 3-5 で、cutover前の8件に消化記録と充当がある(6-3 (6))。特別・代休は (a) の件数と同じ件数が未充当量ありになる。
- 違った時に決めること: 8件の充当が再現されない場合は、3-2 のcutoverの突き合わせ結果(再現・`carried_over`・不一致)を確認する。

### (g) 差戻し/取消/却下の判定とワークフローの状態の突き合わせ

- 確認: 3-2 の試し実行の差戻し/取消/却下の判定結果を、3-2 のSQL(ワークフローの状態)と申請ごとに突き合わせる。
  - ワークフローが無い休暇申請(申請不要の承認で作られたもの以外): 件数を記録し、理由を確認する。
  - 差戻し済みで消化記録が取り消されていない申請(論点12の候補(1)): 作り直しで差戻しの位置に `usage_cancelled` が置かれる件数と一致する。
  - 却下済みのワークフローで申請中の休暇: 作り直しで `*.cancelled` になる件数と一致する。
- 違った時に決めること: 一致しない申請を個別に確認し、変換規則の不足であれば変更セットを更新してから再実行する。

### (h) 旧システムが直接作った勤怠日の扱い

- 作り直しでの扱い(2.4): `attendance_day.created` が無い勤怠日は、最初のイベントの直前に `created` を置く(内容はReadModelの現在値。
  休暇の処理が作った日は `source=leave`、全休のために書かれた `clocked_out` は `not_started`)。休暇の解除で空になった勤怠日は、後続の
  イベントが無い場合だけ `deleted` を置く。
- 確認: 3-2 の「`created` を補う勤怠日」の件数・内訳(source・status)を B12 と照合する。3-5 で B12 の休暇値・全休の `clocked_out`・
  `no_created` が 0 件になる。
- 違った時に決めること: 「休暇の処理が作った日」の判定(source=leave にするか)が実態と違う日があれば、判定の条件をユーザーと決める。

---

## 5. 入れ替え・リビルド・再計算・再開・ロールバック(`event-rebuild-mapping.md` 7章の6〜8とロールバック)

5章は 3-5 の比較と4章の確認がすべて済んでから行う。本番では、この前に変換規則と試し実行・複製DBでの比較の結果を示し、
ユーザーの明示的な許可を得る(許可の日時と内容を `spec.md` のレビュー履歴に記録する)。

### 5-1. 前提の確認

- 3-2 の扱えない並び 0件、cutoverの不一致 0件。
- 3-5 の差分がすべて「意図した差」。
- 4章の (a)〜(h) の判断が決まっている。
- 3-3 のバックアップ(`backup/before-rebuild.sql.gz`)がある。

### 5-2. 入れ替え(`--swap`)

```bash
php artisan leave:rebuild-event-store --swap | tee ~/rehearsal-20261009/after/rebuild-swap.txt
```

コマンドが行うこと(同じ内容のSQL。1文で原子的):

```sql
RENAME TABLE stored_events TO stored_events_before_rebuild_YYYYMMDDHHMMSS,
             stored_events_rebuilt TO stored_events;
ALTER TABLE stored_events AUTO_INCREMENT = <新しい最大id + 1>;
```

```sql
-- 確認: 次に振られるidが最大id+1であること
SELECT MAX(id) + 1 AS expected_next_id FROM stored_events;
SELECT AUTO_INCREMENT FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stored_events';
```

- 元の表の名前(`stored_events_before_rebuild_*`)を記録する。 ____________________

### 5-3. 旧系統を削除した新しいコードの配備とマイグレーション

```bash
cd <リハーサル用アプリ>/backend
php artisan migrate --force
php artisan migrate:status | tail -n 20
php artisan access-control:sync-catalog
```

- 対象は本変更のマイグレーション(`2026_10_10_*`。WP10の列削除を含む)。**要確認 R10**: docs/27 3.1 の migrate の範囲の記述。

外部キーの撤去と列を確認する(MySQL)。

```sql
-- 期待: 0行
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND ((TABLE_NAME = 'paid_leave_usages' AND COLUMN_NAME IN ('attendance_day_id', 'paid_leave_request_id'))
    OR (TABLE_NAME = 'special_leave_usages' AND COLUMN_NAME IN ('attendance_day_id', 'special_leave_request_id'))
    OR (TABLE_NAME = 'compensatory_leave_usages' AND COLUMN_NAME IN ('attendance_day_id', 'compensatory_leave_request_id'))
    OR (TABLE_NAME = 'compensatory_leave_grants' AND COLUMN_NAME = 'attendance_day_id'));

-- 期待: attendance_day_id が YES(任意)
SELECT TABLE_NAME, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLUMN_NAME = 'attendance_day_id'
  AND TABLE_NAME IN ('paid_leave_usages', 'special_leave_usages', 'compensatory_leave_usages', 'compensatory_leave_grants');

-- 期待: 0行(compensatory_leave_grants.attendance_day_id の unique が無いこと)
SELECT INDEX_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'compensatory_leave_grants'
  AND COLUMN_NAME = 'attendance_day_id'
  AND NON_UNIQUE = 0;

-- 期待: 0行(WP10で削除する列が残っていないこと。列名はWP10の実装に合わせて読み替える)
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((COLUMN_NAME = 'input_source')
    OR (COLUMN_NAME = 'stored_event_id' AND TABLE_NAME IN ('paid_leave_usages', 'special_leave_usages', 'compensatory_leave_usages')));
```

### 5-4. 全ReadModelを空にして全Projectorをリビルド

作り直しでは、休暇の処理が直接作った勤怠日にも `created` が置かれるため、勤怠日・日次計算・月次を含む**全ReadModel**を空にしてよい
(旧手順の「勤怠日を空にしない」は不要になった)。Projectorは `replay` の前に状態を消さないため、必ず空にしてから再生する。

- 空にするテーブルの一覧は、Projectorの書き込み先から機械的に作ったもの(WP10の成果物)を使う。
  **要確認 R2**: 一覧の作り方と、一覧に `stored_events.id` を参照する列を持つ表(`workflow_request_history_entries`・
  `expense_claim_history_entries` の `stored_event_id`)が含まれること。

```sql
-- 空にする(一覧のテーブルをすべて。外部キーが残るテーブルがあるため、一時的に検査を止める)
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE <一覧のテーブル>;
-- ...
SET FOREIGN_KEY_CHECKS = 1;
```

```bash
time php artisan event-sourcing:replay --force | tee ~/rehearsal-20261009/after/replay-all.txt
```

- **要確認 R11**: spatie の replay コマンドの引数と挙動(引数なしで全Projector、`--force`)。最初の実行の出力で確認する。
- 記録: 所要時間、主なテーブルの行数。

### 5-5. 日次計算の再計算(締め・提出済みの月も含む)

作り直しでは日次計算を変換しない(`event-rebuild-mapping.md` 2.4)。休暇値を直した日と休暇のある日を再計算する。
締め・提出済みの月も再計算する(2026-10-10 ユーザー決定)。月次スナップショットは変えず、差のある月を一覧にして報告する。

```sql
-- 再計算の期間の目安: 休暇のある日と、休暇値を直した日の最小・最大
SELECT MIN(work_date) AS from_date, MAX(work_date) AS to_date FROM attendance_day_leaves;
```

```bash
# 試し実行(既定。書き込みなし)。期間は上のSQLと 3-2 の休暇値を書き換えた日を含める
php artisan attendance:recalculate-days --from=<from_date> --to=<to_date> | tee ~/rehearsal-20261009/after/recalc-dryrun.txt
# 本実行
php artisan attendance:recalculate-days --from=<from_date> --to=<to_date> --apply | tee ~/rehearsal-20261009/after/recalc-apply.txt
# 月次スナップショットとの差(dry-run のみ。スナップショットは更新しない)
php artisan attendance:recalculate-month-snapshots --dry-run | tee ~/rehearsal-20261009/after/snapshot-diff.txt
```

- 見る点: `[除外]`(手動調整済みで対象外になった日)の件数が、`attendance_day.daily_calculation_adjusted` を持つ日の数と合うか。
  `[変更]` の内容が、休暇値の削除・休暇ビューへの切り替えで説明できるか。締め・提出済みの日の印。

```sql
-- 手動調整された勤怠日の数(期間で絞るなら aggregate_uuid と勤怠日を結合する)
SELECT COUNT(DISTINCT aggregate_uuid) AS adjusted_days
FROM stored_events
WHERE event_class = 'attendance_day.daily_calculation_adjusted';
```

- 報告: 提出時のスナップショットと差のある月(利用者・年月・項目・差)の一覧を `spec.md` の「実装中の決定」に記録し、ユーザーに報告する。

### 5-6. 確認の後、書き込みを再開する

6章の検証が済んだら、`php artisan up`、スケジューラ・キューワーカーの再開、打刻端末・API・MCPの受付の再開を行う。
3-3 の停止から再開までの時間を記録する(本番のメンテナンス窓の見積もり)。

### 5-7. ロールバック(リハーサルで1回試す)

5-6 の再開までに問題があれば、表とReadModelとコードを同時に戻す(新しいコードは旧イベントを読めず、旧コードは新しいイベントを読めないため)。

1. 表を戻す:
   ```sql
   RENAME TABLE stored_events TO stored_events_rebuilt_failed,
                stored_events_before_rebuild_YYYYMMDDHHMMSS TO stored_events;
   ```
2. ReadModel(とマイグレーションで変わったスキーマ)を 3-3 のバックアップから戻す。5-3 の migrate でスキーマが変わっているため、
   実際には DB 全体を `backup/before-rebuild.sql.gz` から戻すのが確実(書き込みは止めているので失われるデータは無い)。
3. 旧コード(1-2 で記録した commit)を再配備する。

- リハーサルでは、6章の記録を保存した後に `flow_office_rehearsal` で1回行い、所要時間と、戻した後の B15(イベント件数)・B02 が
  作り直し前(2章)と一致することを確認する。

---

## 6. 作り直し後の検証(`flow_office_rehearsal`、5-5 の後。3-5 では `flow_office_verify` で同じことを行う)

### 6-1. 外部キーと列(5-3と同じSQL)

5-3 の SQL を再実行し、期待どおりであることを確認する。

### 6-2. 作り直し前の記録(B01〜B15)との比較

作り直し後に、2章のSQL(B01〜B15)を同じ順で実行し、`~/rehearsal-20261009/after/` に保存する。差分は次のように確認する。

```bash
diff ~/rehearsal-20261009/before/B02.tsv ~/rehearsal-20261009/after/B02.tsv > ~/rehearsal-20261009/after/diff-B02.txt
```

期待する差分(変換規則で意図した差だけが出る):

| 基準 | 期待 | 差分が出た場合 |
|---|---|---|
| B01 月次スナップショット | 変わらない(提出イベントは書き換えず、5-5 でもスナップショットは更新しない) | 差分があれば、スナップショットの再計算が走った可能性。原因を確認する |
| B02 月次の日次集計 | 休暇値を直した日・休暇のある日の再計算(5-5)、論点15の空の勤怠日の削除(`day_count` が減る)で変わる | 変わった日が 5-5 の `[変更]` と 3-2 の `deleted` の一覧で説明できるか確認する |
| B03 有給付与 | 残数は変わらない。cutoverでシステム外から持ち込んだ付与は `source = carried_over` に変わる(付与IDは同じ) | `carried_over` の件数が 3-2 の突き合わせ結果と一致するか確認する |
| B04・B05 特別休暇・代休の付与 | 変わらない | 差分は(c)の下書き・(a)(f)の未充当で説明できるか確認する |
| B06 残高キャッシュ | 差し戻された休暇の消化記録の取消で `pending_days` が減る以外は変わらない | 減った量が (g) の差戻し未取消の件数と合うか確認する |
| B07 消化記録 | 差し戻された休暇の消化記録が取り消される(減る)。cutover前の有給8件は消化記録が新しくできる(増える)。却下済みの申請中の休暇の消化記録が取り消される | 増減が (f)(g) と 3-2 の件数で説明できるか確認する。**要確認 R9**: 旧系統の有給の消化記録の取消の表し方 |
| B08 申請の状態 | 却下済みワークフローの申請中の休暇が `cancelled` になる。それ以外は変わらない | 差分が出たら、6-3の照合で該当の申請を特定する |
| B09 cutover前8件 | 8件のまま承認済み。休暇ビュー(6-3)に8件出る | 8件が出なければ、6-3の照合を先に確認する |
| B10 出勤率の判定 | 保存された判定は変わらない(再判定はしない) | 6-3の出勤率の照合を参照 |
| B11 特別休暇の自動付与 | 変わらない | 差分は、付与の登録が口座集約のイベントに変わったことによるか確認する |
| B12 休暇値・全休 | 休暇値・全休の `clocked_out`・`no_created` がすべて 0 件 | 残った行を個別に確認する(2.4 の規則で扱えていない) |
| B13 休暇に紐づく申請 | 変わらない(ワークフローのイベントは書き換えない) | 差分があれば 3-4 (6) を確認する |
| B14 月次の状態 | 変わらない | 差分があれば、締め・提出の操作が行われていないか確認する |
| B15 イベント件数 | 総数・最大idが変わる(分割・統合・`created`/`deleted` の追加)。休暇・勤怠以外の接頭辞の件数は変わらない。旧接頭辞(`paid_leave`・`special_leave`・`compensatory_leave`の旧種類)は0件になり、新しい接頭辞(`paid_leave_request` 等)ができる | 件数の増減を 3-2・3-4 の出力と照合する |

### 6-3. 休暇ビューと申請の照合

```sql
-- (1) 有給: 申請の状態と休暇ビューの状態が一致する(期待: 0行)
SELECT r.id, r.status AS request_status, v.request_status AS view_status
FROM paid_leave_requests r
LEFT JOIN attendance_day_leaves v ON v.leave_kind = 'paid' AND v.leave_request_id = r.id
WHERE v.id IS NULL OR v.request_status <> r.status;

-- (2) 特別休暇(期待: 0行)
SELECT r.id, r.status AS request_status, v.request_status AS view_status
FROM special_leave_requests r
LEFT JOIN attendance_day_leaves v ON v.leave_kind = 'special' AND v.leave_request_id = r.id
WHERE v.id IS NULL OR v.request_status <> r.status;

-- (3) 代休(期待: 0行)
SELECT r.id, r.status AS request_status, v.request_status AS view_status
FROM compensatory_leave_requests r
LEFT JOIN attendance_day_leaves v ON v.leave_kind = 'compensatory' AND v.leave_request_id = r.id
WHERE v.id IS NULL OR v.request_status <> r.status;

-- (4) 休暇ビューに、対応する申請が無い行(期待: 0行)
SELECT v.id, v.leave_kind, v.leave_request_id
FROM attendance_day_leaves v
WHERE (v.leave_kind = 'paid' AND NOT EXISTS (SELECT 1 FROM paid_leave_requests r WHERE r.id = v.leave_request_id))
   OR (v.leave_kind = 'special' AND NOT EXISTS (SELECT 1 FROM special_leave_requests r WHERE r.id = v.leave_request_id))
   OR (v.leave_kind = 'compensatory' AND NOT EXISTS (SELECT 1 FROM compensatory_leave_requests r WHERE r.id = v.leave_request_id));

-- (5) 申請中・承認済みの休暇日に、勤怠日が無い(論点3。期待: 0行)
SELECT v.user_id, v.work_date, v.leave_kind
FROM attendance_day_leaves v
WHERE v.request_status IN ('submitted', 'approved')
  AND NOT EXISTS (SELECT 1 FROM attendance_days d WHERE d.user_id = v.user_id AND d.work_date = v.work_date);

-- (6) cutover前の有給8件が休暇ビューに出て、消化記録がある(期待: 8行、request_status = approved、usages >= 1)
SELECT v.leave_request_id, v.request_status, v.work_date, v.unit,
       (SELECT COUNT(*) FROM paid_leave_usages u
         WHERE u.paid_leave_request_id = v.leave_request_id AND u.cancelled = 0) AS usages
FROM attendance_day_leaves v
WHERE v.leave_kind = 'paid'
  AND v.leave_request_id IN (SELECT id FROM paid_leave_requests
                             WHERE target_date BETWEEN '2026-08-10' AND '2026-09-04')
ORDER BY v.work_date;

-- (7) 勤怠日の source の内訳(作り直しで `created` を補った日のうち、休暇の処理が作った日は leave になる)
SELECT source, COUNT(*) AS days FROM attendance_days GROUP BY source ORDER BY source;
```

- (6) で `usages` が 0 の申請は、cutoverの付与が旧イベントで再現できなかった(`carried_over`)利用者の申請。3-2 の突き合わせ結果と照合する。
- (7) の `leave` の件数は、3-2 の「`created` を補う勤怠日のうち `source=leave` にする日」から、論点15で削除した日を引いた件数になる。
- 出勤率の照合(B10 との比較): 有給の出勤率ビュー `leave_attendance_rate_days` の分母・分子が、B10 の保存された判定と
  一致するかを、利用者×期間で確認する。**要確認**: 判定の分母の定義(`is_working_day` の扱い)を、保存された判定と照らして確認する。

### 6-4. 空からの全件リビルドで同じ状態になることの確認(受け入れ条件)

**手順(a) 状態の保存**: 6-2 の後、次の SQL を `~/rehearsal-20261009/rebuild/state-before.tsv` に保存する
(`-N -B`。連番の `id` と `created_at`・`updated_at` は含めない。勤怠日の `id` は集約IDなので含める)。

```sql
-- 休暇ビュー
SELECT leave_kind, leave_request_id, user_id, work_date, unit, hours, minutes, special_leave_type_id,
       workflow_request_id, request_status
FROM attendance_day_leaves ORDER BY leave_kind, leave_request_id;

-- 休暇ビューの消化記録(有給)
SELECT usage_id, leave_request_id FROM attendance_day_leave_paid_usages ORDER BY usage_id;

-- 有給申請
SELECT id, user_id, approver_user_id, status, leave_type, target_date, hours, requested_days,
       request_group_id, submitted_at, approved_at, returned_at, cancelled_at
FROM paid_leave_requests ORDER BY id;

-- 申請と業務の対応表
SELECT workflow_request_id, leave_kind, leave_request_id FROM leave_request_workflow_links ORDER BY workflow_request_id;

-- 有給の付与・消化・充当・残高
SELECT id, user_id, granted_on, expires_on, granted_days, allocated_days, remaining_days, status, source FROM paid_leave_grants ORDER BY id;
SELECT usage_id, user_id, used_on, used_days, used_minutes, usage_type, confirmed, cancelled, paid_leave_request_id
FROM paid_leave_usages ORDER BY usage_id;
SELECT usage_id, grant_id, allocated_days FROM paid_leave_usage_allocations ORDER BY usage_id, grant_id;
SELECT user_id, available_days, pending_days, unallocated_days FROM paid_leave_balances ORDER BY user_id;

-- 特別休暇・代休の付与と消化
SELECT id, user_id, special_leave_type_id, granted_on, expires_on, granted_days, used_days, remaining_days, status
FROM special_leave_grants ORDER BY id;
SELECT usage_id, user_id, used_on, used_days, used_minutes, usage_type, is_confirmed, unallocated_days
FROM special_leave_usages ORDER BY usage_id;
SELECT id, user_id, source, work_date, granted_days, granted_minutes, used_days, used_minutes,
       remaining_days, remaining_minutes, status, expires_on
FROM compensatory_leave_grants ORDER BY id;
SELECT usage_id, user_id, used_on, used_days, used_minutes, usage_type, is_confirmed, unallocated_days, unallocated_minutes
FROM compensatory_leave_usages ORDER BY usage_id;

-- 代休の休日出勤・付与ビュー
SELECT user_id, work_date, is_holiday_day, work_minutes FROM compensatory_holiday_work_days ORDER BY user_id, work_date;
SELECT grant_id, user_id, work_date, granted_days, granted_minutes, status, used_days, used_minutes
FROM compensatory_grant_day_views ORDER BY grant_id;

-- 出勤率ビュー
SELECT user_id, work_date, is_working_day, attended, full_leave_kinds, partial_leave_kinds
FROM leave_attendance_rate_days ORDER BY user_id, work_date;
SELECT user_id, work_date, is_working_day, attended, work_style_id, full_leave_kinds, partial_leave_kinds
FROM special_leave_attendance_rate_days ORDER BY user_id, work_date;

-- 勤怠日・日次計算(作り直し後は勤怠も空から再生成できる)
SELECT id, user_id, work_date, status, source, work_type FROM attendance_days ORDER BY id;
SELECT attendance_day_id, prescribed_work_minutes, work_minutes, payroll_work_minutes, paid_leave_days, paid_leave_minutes,
       special_leave_days, special_leave_minutes
FROM attendance_daily_calculations ORDER BY attendance_day_id;
```

- 列名はWP10の実装(削除した列)に合わせて読み替える。

**手順(b) 空にして再生**: 5-4 と同じ方法で、全ReadModelを空にして全Projectorを再生する。

**手順(c) 比較**: (a) と同じSQLを `state-after.tsv` に保存し、差分を取る。

```bash
diff ~/rehearsal-20261009/rebuild/state-before.tsv ~/rehearsal-20261009/rebuild/state-after.tsv && echo SAME
```

- 期待: 差分なし(旧Projectorと口座のProjectorが同じテーブルに書く問題は、旧Projectorの旧イベント処理の削除(WP10)で無くなる)。
- 日次計算は 5-5 の再計算のイベントから再生されるため、(a) と同じになる。
- **順序の確認(任意だが推奨)**: Projectorを逆順で1つずつ再生し、(a) と同じになるか確認する(順序非依存)。

### 6-5. 日次計算の再計算の冪等性

```bash
php artisan attendance:recalculate-days --from=<from_date> --to=<to_date> | tee ~/rehearsal-20261009/after/recalc-dryrun-2.txt
```

- 期待: 5-5 の本実行の後なので `変更あり 0 件`。手動調整された日は `[除外]` になり、上書きされない(受け入れ条件)。

### 6-6. 主要画面での目視確認

画面は本番相当データ・リハーサル環境で行う。各項目の結果を 8章の表に記録する。

| 画面(パス) | 確認する項目 | 期待 |
|---|---|---|
| 勤怠の日次 `/attendance/days/:date` | 休暇日の休暇ラベル(`leaves` から表示)。複数の休暇がある場合は複数表示 | 休暇ラベルが出る。全休・半休・時間休は、状態バッジの代わりに休暇ラベル |
| 同 | 休暇日の**時刻・作業内容の編集**をしても、休暇ラベルが消えない(不具合の再現確認。変更要望) | 消えない。作業内容(`work_type`)は休暇に影響しない |
| 同 | 作業内容欄は休暇日でも表示され、入力値がそのまま保存される。休暇値だった日の作業内容は休暇値の前の値(無ければ空) | 表示・保存される |
| 勤怠の今日 `/attendance/today`・打刻 | 全休の日は出勤できない。打刻を取り込まない。半休の日は打刻できる。休暇だけの日(`source=leave`)に打刻が取り込まれる | 全休: 出勤不可・警告なし。半休: 打刻可 |
| 勤怠の週・月 `/attendance/week`・`/attendance/months` | 休暇日数(有給・特別)と代休の付与表示。締めの状態 | 作り直し前(B01・B02)の値と一致する(5-5 の差の一覧に載った日を除く) |
| 有給 `/paid-leave`・`/paid-leave/history`・`/paid-leave/grants/mine` | 残数。差戻しの申請の表示と「提出する」「取消」の導線。持ち込みの付与(`carried_over`)の表示 | 差戻しの申請が出る。再提出(申請詳細へ)・取消の導線がある。`carried_over` の付与が表示される |
| 特別休暇 `/special-leave`・`/special-leave/history` | 同上 | 同上 |
| 代休 `/compensatory-leave`・`/compensatory-leave/history/mine`・`/compensatory-leave/grants/mine` | 同上。付与一覧の下書き表示(c) | 同上 |
| 休暇の履歴画面(イベント一覧) | 旧`event_type`名が出ず、新しいイベントで履歴が表示される | 表示される |
| 申請詳細 `/requests/:id` | 業務側(休暇)の申請に「却下」が出ない。差戻しの休暇は「提出する」が出る | 却下が出ない。提出が出る |
| 取消ダイアログ(承認済みの休暇の取消) | 「勤怠区分もクリアされます」の文言が無い | 文言が無い |
| cutover前の有給8件(`/paid-leave/history`・勤怠の日次) | 8件が表示される。承認済み。消化記録まで再現できた申請は、通常の承認済み申請と同じく取消できる(締め済みの月は論点14で拒否) | 8件表示・取消の可否が上のとおり |
| 締め済みの日の休暇 | 申請・取消がエラーになり、状態が変わらない | エラー。どの画面の表示も変わらない |
| 承認済みの休暇を管理者が取消 | 取消が成功する。ワークフローは承認済みのまま | 成功。申請詳細で承認済みのまま |

- 目視で見つけた不具合は、このファイルの8章に書く。修正は変更セットを更新してから行う(コードは直接触らない)。
- 監査ログAPIの `event_id` は作り直しで変わる(`event-rebuild-mapping.md` 6章)。外部に返すidが変わることをリリースノートに記載する。

---

## 7. 実施の順(一覧)

1. 1章: 複製・commit(新旧)・composer・`.env`(APP_KEY は本番と同じ)・通知の停止(0-2)
2. 2章: 作り直し前の記録 B01〜B15 と、4章 (a)(b)(e)(f) のSQL(旧スキーマ・作り直しの前)
3. 3-1: 事前条件の確認
4. 3-2: 試し実行と見方(扱えない並び0件・cutoverの不一致0件・判定の突き合わせ)
5. 3-3: 書き込みの停止(模擬)とDB全体のバックアップ
6. 3-4: `--apply`(`stored_events_rebuilt`)と確認のSQL
7. 3-5: 複製DB(`flow_office_verify`)での入れ替え・migrate・全Projectorのリビルド・再計算の試し実行・比較
8. 4章: 確認事項 (a)〜(h) の判断
9. (本番ではここでユーザーの明示的な許可を得る)
10. 5-2: `--swap`
11. 5-3: 新しいコードの配備・migrate・外部キーと列の確認・権限カタログ
12. 5-4: 全ReadModelを空にして全Projectorをリビルド
13. 5-5: 日次計算の再計算(締め・提出済みの月も含む)とスナップショットとの差の一覧
14. 6章: 比較・照合・リビルドの再現性・冪等性・画面
15. 5-6: 書き込みの再開(停止から再開までの時間を記録)
16. 5-7: ロールバックの試行(リハーサルのみ。6章の記録を保存した後)

---

## 8. 結果の記録欄

判断と件数は、この表に書いた後、変更セット `spec.md` の「実装中の決定」とレビュー履歴にも記録する。

| 項目 | 件数・結果 | 判断(何をするか) | 実施日 | 担当 | 備考 |
|---|---|---|---|---|---|
| 1-1 複製(本番の stored_events 件数 / 複製の件数) | | | | | |
| 1-2 commit(新 / 旧) | | | | | |
| 1-4 APP_KEY の設定 | | | | | |
| 0-2 通知の停止(system_settings) | | | | | |
| B01〜B15(保存先) | | | | | |
| B09 cutover前8件 | | | | | 8件か |
| 3-1 事前条件(新種類 / 補正ログ / スナップショット / cutoverイベント) | __ / __ / __ / __ | | | | |
| 3-2 試し実行: 変換件数 | 申請 __ / 付与 __ / 勤怠日 __ | | | | |
| 3-2 扱えない並び | __件 | | | | 0件か |
| 3-2 cutoverの突き合わせ | 再現 __ / carried_over __ / 不一致 __ | | | | |
| 3-2 差戻し / 取消 / 却下の判定 | __ / __ / __ | | | | ワークフローと一致か |
| 3-2 `created` を補う勤怠日(うち source=leave)/ 休暇値の書き換え / `deleted` | __(__) / __ / __ | | | | |
| 3-3 停止の時刻・バックアップ | | | | | |
| 3-4 `--apply`(総数 / 版の連続性 / 重複 / 対象外の変化) | __ / __行 / __行 / __ | | | | |
| 3-5 複製DBでの比較 | 説明できない差分 __件 | | | | |
| (a) 残数不足のまま承認 | 特別 __件 / 代休 __件 | | | | |
| (b) 時間単位で分数 null | 申請 __件 / 消化 __件 | | | | |
| (c) 代休の下書き | 件数 __ / 残数合計 __ | | | | |
| (d) リビルドの所要時間・行数 | 所要 __秒 / 行数一致 有・無 | | | | |
| (e) paidLeaveRequestId が null | __件 | | | | |
| (f) 未充当量(特別・代休・有給) | 特別 __件 / 代休 __件 / 有給 __件 | | | | |
| (g) 判定とワークフローの突き合わせ | 不一致 __件 / ワークフロー無し __件 | | | | |
| (h) 直接作られた勤怠日 | __件(内訳: ) | | | | |
| 5-2 `--swap`(元の表の名前・AUTO_INCREMENT) | | | | | |
| 5-3 migrate・外部キーと列の確認 | | | | | |
| 5-4 全Projectorのリビルド | 所要 __秒 | | | | |
| 5-5 再計算(変更 / 除外)・スナップショットとの差のある月 | __ / __ ・ __か月 | | | | 一覧の保存先 |
| 6-2 差分(B01〜B15) | | | | | |
| 6-3 照合(1)〜(7) | 不一致 __件 | | | | |
| 6-4 リビルドの差分 | 差分 __行 | | | | |
| 6-5 再計算の冪等性 | 変更 __件 | | | | |
| 6-6 画面 | 不具合 __件 | | | | |
| 5-6 停止から再開までの時間 | | | | | メンテナンス窓 |
| 5-7 ロールバックの所要時間・一致 | | | | | |

---

## 9. 要確認の一覧(実施前に決める・確認する)

- **R1**: 変換コマンドを旧スキーマ(migrate前)のDBに対して実行できるか(読むのは `stored_events` だけであること)。
- **R2(重要)**: 空にする全ReadModelのテーブルの一覧(Projectorの書き込み先から機械的に作る。WP10の成果物)。`stored_events.id` を参照する
  `workflow_request_history_entries`・`expense_claim_history_entries` を含むこと。
- **R3**: 3-1 の「新しい種類のイベント」の一覧(`paid_leave_account.*` のうち本変更で増えた種類)と、スナップショットのテーブル名。
- **R4**: 変換コマンド(`leave:rebuild-event-store`、仮称)とオプション名はWP10の実装に合わせて読み替える(WP10は未着手)。
- **R5**: 本番DBの複製の手順(mysqldump のオプション・権限・MySQLのバージョン)は既存の資料に無い。
- **R6**: 暗号化された値の復号のため、リハーサルの `APP_KEY` は本番の値を使う。暗号化カラムの一覧は未確認。
- **R7**: 特別休暇の「残数を要する/要しない」は `*.used` の有無で判定する(承認時点の種別の設定の履歴が無いため)。残数を要する種別で
  `*.used` が無い申請があれば、判定が実態と違う。
- **R8**: (b) 分数が求められない時間休、(e) 申請IDの無い有給の消化記録を、変換コマンドが扱えない並びとして失敗させるか(WP10の実装で確認)。
- **R9**: 旧系統の有給の消化記録の取消の表し方(`paid_leave_usages.cancelled` が旧系統の行で使われていたか)。B07 の比較の読み方に影響する。
- **R10**: docs/27 3.1 の migrate の範囲の記述と、リポジトリのマイグレーション(`2026_10_10_*`。補正ログ `stored_event_corrections` を含む)の対応。
- **R11**: spatie の replay コマンドの引数と挙動(引数なしで全Projector、`--force`)は、既存コードの使い方から推定した。
- **R12**: `attendance_daily_calculations.attendance_day_id` が 1 日 1 行か(B02 の合計の前提)。
- **R13**: 3-4 (5) の作り直し前の逆転数のSQLは件数が多いと遅い。所要時間を見て、必要なら抽出範囲を絞る。
