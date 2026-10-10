# リリース前リハーサル手順書(変更セット 20261009-keep-leave-work-type-on-edit)

本番相当データ(本番DBの複製)で、休暇まわりの移行・補正・検証を通しで行うための手順書。
ユーザーがこの手順のとおりに実行し、結果を「8. 結果の記録欄」に書き込むことを想定する。

- 対象の変更: PR #115(draft)。head ブランチ `claude/claude-md-delegation-rules`。実施時の commit を「1.2」に記録する。
- 根拠: `spec.md`(論点12・13・17、仕様確定事項I・H、実装中の決定(a)〜(h))、`docs/27-release-runbook.md` 3.1、
  `docs/32-stored-event-history-normalization.md` の休暇まわりの補正の節、`.claude/skills/data-correction/SKILL.md`。
- 表記: **要確認** は、コードや既存資料から確定できず、実施前にユーザーまたは委譲元が決める箇所。
- 本番DBへの書き込みは、この手順書の対象外。本番で実行するのはユーザーの指示による(data-correction ステップ2・安全手順)。

---

## 0. 安全ルール(全体に共通)

### 0-1. 接続先の確認(SQLを実行するたびに最初に行う)

```sql
SELECT DATABASE() AS db, VERSION() AS mysql_version, @@hostname AS host;
```

期待: `db` がリハーサル用DB名(この手順では `flow_office_rehearsal`)。本番のDB名だった場合は、そこで中止する。

### 0-2. リハーサル環境で通知・外部連携を止める(移行コマンドを実行する前に行う)

移行コマンドは同期Reactorを通じて通知を発行する。DBの複製には本番の設定が入っているため、そのままでは本番利用者へ
メールが送信される可能性がある。

```sql
-- メール通知(GraphMailNotifier)を止める。送信先の設定はsystem_settingsにある(要確認: 本番のTeams・Entra設定の有無)
UPDATE system_settings SET notification_mail_enabled = 0;
```

- `.env` は `MAIL_MAILER=log` にする。
- キューワーカー(`php artisan queue:work`)を起動しない。cron を設定しない。
- `SyncUsersFromMs365Command`・freee/MoneyForward連携・定時の付与コマンド(`GrantScheduledSpecialLeaveCommand`等)は実行しない。

### 0-3. 出力の保存

すべての出力は `~/rehearsal-20261009/` 配下に保存する(リポジトリの外。git 管理しない)。
本手順書の `mysql ... -B -e` の出力は、タブ区切り・ヘッダ付きで保存される。

```bash
mkdir -p ~/rehearsal-20261009/{before,after,rebuild}
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

既存の運用手順(複製の専用手順)は無い。`docs/32` の補正は2026-08-10のSQLエクスポートを基準にしている。
以下は一般的な手順で、**要確認**(本番の接続方式・権限・MySQLバージョン)。

```bash
# 本番側: 読み取りのみ(書き込みしない)
mysqldump --single-transaction --routines --triggers --no-tablespaces \
  -h <本番ホスト> -u <ユーザー> -p <本番DB名> | gzip > ~/rehearsal-20261009/prod-copy.sql.gz

# リハーサル用DBへ投入
mysql -h <リハーサルホスト> -u <ユーザー> -p -e \
  "CREATE DATABASE flow_office_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c ~/rehearsal-20261009/prod-copy.sql.gz | mysql -h <リハーサルホスト> -u <ユーザー> -p flow_office_rehearsal
```

- 複製の時刻は、本番の休暇の申請・承認・付与取消を止めた時点(docs/27 3.1 の1番)が望ましい。
- 複製ファイルには個人情報・認証情報が含まれる。保存先と削除の方法はユーザーの規程に従う。
- 投入後、0-1 で接続先を確認する。
- `stored_events` の件数を本番側で控えておく(2-B15 の値と照合する)。

### 1-2. 配備するブランチ

- ブランチ: `claude/claude-md-delegation-rules`(PR #115 の head)。
- 実施時の commit: `git rev-parse HEAD` の値を記録する。 ____________________

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

```bash
php artisan config:clear && php artisan route:clear && php artisan view:clear
```

### 1-5. 移行前の記録

**migrate の前に**、2 の記録をすべて取得する。migrate の後では旧スキーマの値を取り直せない。

---

## 2. 移行前の記録(migrate の前に、旧スキーマのDBで実行)

各SQLを `~/rehearsal-20261009/before/` に保存する。比較の基準として、6章で同じSQLを移行後に再実行する。
**このSQLは旧スキーマ(migrate前)でのみ動く。** 以下のテーブル・列はマイグレーション前から存在するものだけを使っている。

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

-- (2)の基準: 勤怠日の最初のイベントに attendance_day.created が無い日(prerequisites の (a1)(a2))
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

- 注意: (2)の`no_created`は目安。正式な候補は `leave:correction-report` の (2) を使う(5章)。

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

### B15. イベントの件数(移行で増える件数の基準)

```sql
SELECT COUNT(*) AS stored_events_total, MAX(id) AS max_id FROM stored_events;

SELECT SUBSTRING_INDEX(event_class, '.', 1) AS prefix, COUNT(*) AS events
FROM stored_events
WHERE event_class REGEXP '^(paid_leave|special_leave|compensatory_leave|workflow_request|attendance_day)'
GROUP BY prefix
ORDER BY prefix;
```

### 2の見方

- B09 の申請が 8 件でなければ、spec の前提(2026-10-10 の確認)と本番の状態が違う。移行の前に止めて確認する。
- B02 と B01 は、移行後の6-2の比較の基準になる。移行は月次の値を変えない設計(論点3)のため、差分が出た場合は6-2の「期待する差分」と照合する。

---

## 3. リリース手順の通し実行(docs/27 §3.1 の順)

### 3-1. メンテナンス窓の模擬

本番では、休暇の申請・承認・付与取消の操作を止める(docs/27 3.1 の1番)。リハーサルでは、止めた時刻を記録する。

### 3-2. マイグレーション

```bash
cd <リハーサル用アプリ>/backend
php artisan migrate --force
php artisan migrate:status | tail -n 15
```

- 対象は `2026_10_10_000001`〜`000009`。docs/27 3.1 は `000001`〜`000008` と書いているが、リポジトリには `000009`(補正ログ `stored_event_corrections`)がある。
  **要確認**: docs/27 の記述を `000009` まで直すかどうか。

外部キーの撤去を確認する(MySQL)。

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
```

### 3-3. 権限カタログの同期

```bash
php artisan access-control:sync-catalog
```

### 3-4. 新設Projectorのテーブルを空にして再生成

Projectorは自動検出で有効になる。過去分は `event-sourcing:replay` で反映する。
**空にするのは下表のテーブルだけ。** 勤怠日・日次計算・月次のテーブルは空にしない(理由は下の注意を参照)。

| Projector | 書き込むテーブル | 空にする |
|---|---|---|
| `LeaveRequestWorkflowLinkProjector` | `leave_request_workflow_links` | する |
| `PaidLeaveRequestProjector` | `paid_leave_requests`、`paid_leave_request_usage_links` | する(対応表と同時) |
| `AttendanceDayLeaveProjector` | `attendance_day_leaves`、`attendance_day_leave_paid_usages` | する |
| `CompensatoryGrantDayViewProjector` | `compensatory_grant_day_views`、`compensatory_grant_day_view_allocations` | する |
| `CompensatoryLeaveHolidayWorkProjector` | `compensatory_holiday_work_days` | する |
| `LeaveAttendanceRateProjector`(PaidLeaveSchedule) | `leave_attendance_rate_days`、`leave_attendance_rate_leaves`、`leave_attendance_rate_attendance_days` | する |
| `SpecialLeaveAttendanceRateProjector` | `special_leave_attendance_rate_days`、`special_leave_attendance_rate_leaves`、`special_leave_attendance_rate_attendance_days` | する |
| `SpecialLeaveAccountProjector` / `CompensatoryLeaveAccountProjector` 等の口座系 | `special_leave_grants`・`special_leave_usages`・`compensatory_leave_grants` 等(旧Projectorと共有) | **要確認 R1**。3-4では空にしない。6-4で扱う |

```sql
-- 空にする(リハーサルのみ。外部キーが残るテーブルがあるため、一時的に検査を止める)
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE leave_request_workflow_links;
TRUNCATE TABLE paid_leave_request_usage_links;
TRUNCATE TABLE paid_leave_requests;
TRUNCATE TABLE attendance_day_leave_paid_usages;
TRUNCATE TABLE attendance_day_leaves;
TRUNCATE TABLE compensatory_grant_day_view_allocations;
TRUNCATE TABLE compensatory_grant_day_views;
TRUNCATE TABLE compensatory_holiday_work_days;
TRUNCATE TABLE leave_attendance_rate_attendance_days;
TRUNCATE TABLE leave_attendance_rate_leaves;
TRUNCATE TABLE leave_attendance_rate_days;
TRUNCATE TABLE special_leave_attendance_rate_attendance_days;
TRUNCATE TABLE special_leave_attendance_rate_leaves;
TRUNCATE TABLE special_leave_attendance_rate_days;
SET FOREIGN_KEY_CHECKS = 1;
```

各Projectorを再生する(1つずつ。時間を記録する)。

```bash
time php artisan event-sourcing:replay LeaveRequestWorkflowLinkProjector --force
time php artisan event-sourcing:replay PaidLeaveRequestProjector --force
time php artisan event-sourcing:replay AttendanceDayLeaveProjector --force
time php artisan event-sourcing:replay CompensatoryGrantDayViewProjector --force
time php artisan event-sourcing:replay CompensatoryLeaveHolidayWorkProjector --force
time php artisan event-sourcing:replay LeaveAttendanceRateProjector --force
time php artisan event-sourcing:replay SpecialLeaveAttendanceRateProjector --force
```

- 引数は `docs/29` の例(`php artisan event-sourcing:replay AttachmentProjector`)に従った。**要確認**: この環境では
  spatie の replay コマンドのソースが参照できないため、引数名と `--force` の挙動は既存コード
  (`RebuildProjectionsCommand`)の使い方で推定している。最初の1件の出力で確認する。
- 順序は非依存のはず(仕様確定事項A「Projectorの順序非依存」)。**要確認**: 逆順で再生しても6-4と同じ結果になるか。
- 記録: 各Projectorの所要時間、対象テーブルの行数(再生後)。

**注意(勤怠日を空にしない理由)**: 「休暇の処理が勤怠日を直接作った」行(`attendance_day.created` が無い日。候補(2))は、
イベントから再生すると消える。勤怠日・日次計算・月次のテーブルを空にして再生すると、候補(2)の行が消えるため、
補正(5章)の後でしか勤怠のProjectorは再生しない。

### 3-5. 移行コマンド(特別休暇 → 代休 → 有給の申請)

順序は docs/27 3.1 の4〜6番に従う。**各コマンドは既定で試し実行。確認してから `--apply` を付ける。**

#### (i) 特別休暇の口座への移行

```bash
php artisan special-leave:migrate-to-account            # 試し実行
php artisan special-leave:migrate-to-account --apply    # 本実行(件数を確認してから)
php artisan special-leave:migrate-to-account            # 再実行(冪等。全員 skip(移行済み) になること)
```

見方:
- 表の列: `user_id` / `result`(`dry-run`・`migrated`・`skip(移行済み)`・`skip(引き継ぎ対象なし)`・`NG`)/ `grants` / `usages`。
- 最後の行: `対象 N 名 / 引き継ぎ N 名 / 対象外 N 名 / 失敗 N 名`。**失敗は0名。** 1名でも失敗すれば、表の下に error が出て終了コードが失敗になる。その時は本実行しない。
- 件数の照合(B04・B08の結果と比べる):
  - 付与の件数 = `SELECT COUNT(*) FROM special_leave_grants;`(全員分。移行前は全件が対象)
  - 消化記録の件数 = `SELECT COUNT(*) FROM special_leave_requests WHERE status IN ('submitted', 'approved');`
  - 一致しない場合は、対象外・失敗の利用者を個別に確認する。

#### (ii) 代休の口座への移行

```bash
php artisan compensatory-leave:migrate-to-account
php artisan compensatory-leave:migrate-to-account --apply
php artisan compensatory-leave:migrate-to-account
```

- 照合: 付与 = `SELECT COUNT(*) FROM compensatory_leave_grants;`、消化記録 = `SELECT COUNT(*) FROM compensatory_leave_requests WHERE status IN ('submitted', 'approved');`
- 代休は差戻し・取消の申請を含めない(コマンドの仕様)。

#### (iii) 有給申請の引き継ぎ

```bash
php artisan paid-leave:migrate-requests            # 試し実行
php artisan paid-leave:migrate-requests --apply
php artisan paid-leave:migrate-requests            # 再実行(全件 skip(引き継ぎ済み) になること)
```

見方:
- 表の列: `paid_leave_request_id` / `現在の状態` / `結果` / `警告`。
- 最後の行: `対象 N 件 / 引き継ぎ N 件 / 引き継ぎ済みのため対象外 N 件 / 却下済みワークフローのため取消として引き継ぐ N 件 / 警告 N 件 / 失敗 N 件`。
- 対象件数の照合: `SELECT COUNT(*) FROM paid_leave_requests WHERE input_source IS NULL OR input_source <> 'paid_request';`
  (3-4 の直後は `paid_request` が無いので、全件が対象になる想定。)
- 警告の種類と件数は(g)で見る。失敗は0件。
- 却下済みの取消件数の照合:

```sql
-- 期待: 「却下済みワークフローのため取消として引き継ぐ」件数と一致する
SELECT COUNT(*) AS rejected_pending
FROM paid_leave_requests r
JOIN leave_request_workflow_links l ON l.leave_request_id = r.id AND l.leave_kind = 'paid'
JOIN workflow_requests w ON w.id = l.workflow_request_id
WHERE r.status = 'submitted' AND w.status = 'rejected';
```

- 有給の引き継ぎは、却下済みの申請の未確定の消化記録も取り消す(口座の `CancelPaidLeaveUsage`)。
  dry-run ではこの取消は実行されない。本実行後の件数を6-2で確認する。

---

## 4. 確認事項(a)〜(h)(spec「実装中の決定」のリハーサル確認事項)

各項目は、移行前の記録(2)・試し実行(3-5)の結果で確認する。

### (a) 現行で残数不足のまま承認された消化記録の引き継ぎ

- 確認: 承認済みの申請で、充当された日数(旧 `*_usages` の合計)が申請日数に届かないもの。移行は不足のまま引き継ぐ。
- 実施: **移行前**(旧スキーマ)に実行する。

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

- 期待: 件数を記録する。0件でなくてもよい(現行で残数不足の承認があり得るため)。
- **要確認**: 特別休暇のうち残数を要しない種別は消化記録(`special_leave_usages`)が出ないため、この件数に入る。
  種別の区分で除外して読む。
- 違った時に決めること: 件数が多い、または不足量が大きい場合、「移行は不足のまま引き継ぐ」(実装の現状)で
  よいかを決める。(f) と合わせて判断する。

### (b) 時間単位で分数が null の消化記録

- 確認: 移行が分数を必須にしているため、分数が無い時間休は移行できない(特別休暇)。代休は `requested_minutes` が
  null のとき 0 分として扱われる(`MigrateCompensatoryLeaveToAccountCommand`の `(int) $request->requested_minutes`)。

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

- 違った時に決めること: 件数が 1 以上なら、正しい分数をどう決めるかをユーザーに確認する。
  分数を補う場合は data-correction(ステップ2の許可が必要)で扱う。代休の 0 分扱いは、そのまま引き継ぐか決める。

### (c) 代休の付与一覧の残数表示(下書きを含む)

- 確認: 現行の残数表示は下書き(`draft`)の付与を含む。移行後も同じに保つか。
- 期待: 件数と残数の合計を記録する(B05)。
- 違った時に決めること: 「移行後も下書きを含む」を維持する(仕様「表示用Projectorで対応」)か、表示から外すか。
  判断はユーザーが行う(変更セットの決定に反する場合は変更セットを更新)。

### (d) 新設Projectorのリビルドの所要時間と件数の一致

- 確認: 3-4 の各Projectorの所要時間。再生後の行数が、(3-4 の空にする前の行数)と、移行前の記録(B08の申請件数)に一致するか。
- 期待:
  - `attendance_day_leaves` の行数 = 申請中・承認済みの休暇申請の件数(B08の `submitted`・`approved` の合計。移行前の申請を含む)。
    ※ 差戻し・取消の行も残るため、行数の合計は「申請の総数」と一致する。
  - 所要時間: 本番のメンテナンス窓に収まるか。収まらない場合は、ユーザーがメンテナンス窓の長さを判断する。
- 確認のSQL:

```sql
SELECT leave_kind, request_status, COUNT(*) AS rows_count
FROM attendance_day_leaves
GROUP BY leave_kind, request_status
ORDER BY leave_kind, request_status;
```

- 違った時に決めること: 行数の不一致は6-3の照合で差分を特定する。所要時間が長すぎる場合は、Projector単位で再生するか、
  メンテナンス窓の時間を見直す。

### (e) cutover後の `paid_leave_account.usage_designated` で `paidLeaveRequestId` が null のもの

- 確認: 休暇ビューは申請IDが無い消化記録を無視する(仕様)。件数を把握する。

```sql
-- 期待: 件数を記録する(0件ならこの論点は終わり)
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
- 違った時に決めること: 件数が 1 以上なら、その消化記録は休暇として見えない(休暇ビューの対象外)。この扱いを受け入れるかを決める。

### (f) 承認済み・未充当の消化記録の未充当量(移行では 0 として扱う)

- 確認: (a) の結果のうち、未充当量(申請日数 − 充当合計)の大きさ。cutover前の有給8件は消化記録が無いため、この確認にも入る。
- 有給の確認(移行前・旧スキーマ):

```sql
-- 期待: cutover前の8件(B09)が出る(消化記録なし)。それ以外は件数を記録する
SELECT r.id, r.user_id, r.target_date, r.requested_days, COALESCE(SUM(a.allocated_days), 0) AS allocated_days
FROM paid_leave_requests r
LEFT JOIN paid_leave_usages u ON u.paid_leave_request_id = r.id AND u.cancelled = 0
LEFT JOIN paid_leave_usage_allocations a ON a.usage_id = u.usage_id
WHERE r.status = 'approved'
GROUP BY r.id, r.user_id, r.target_date, r.requested_days
HAVING COALESCE(SUM(a.allocated_days), 0) < r.requested_days - 0.001
ORDER BY r.target_date;
```

- 期待: 特別・代休は (a) の件数と合わせて記録する。有給はcutover前の8件が出る(取消は拒否される仕様)。
- 違った時に決めること: 特別・代休で未充当量が 0 でない件数が多ければ、移行データに未充当量を持たせるかを決める
  (現状は 0 として扱う)。持たせる場合は移行の入力と実装の変更が必要なので、変更セットを更新してから実施する。

### (g) `paid-leave:migrate-requests` の警告件数

- 確認: 試し実行の表の `警告` 列を、警告の種類ごとに数える。

| 警告(表示される文言) | 意味 | 期待 | 違った時 |
|---|---|---|---|
| `ワークフローの対応が無い(承認・差戻しの連動が効かない。要確認)` | 対応表(`leave_request_workflow_links`)に申請が無い | 0件が望ましい | 申請ごとに、ワークフローが無い理由を確認する |
| `差戻しなのに消化記録が取り消されていない(要確認)` | 差戻し済みの申請で消化記録が残る(候補(1)) | 件数を記録 | 5章の候補(1)で扱う |
| `承認済みだが消化記録が無い(cutover後の申請として想定外。要確認)` | cutover後の申請で消化記録が無い | 0件が望ましい(cutover前8件は `legacy_paid` のため警告は出ない) | 申請ごとに確認する |

```bash
php artisan paid-leave:migrate-requests | tee ~/rehearsal-20261009/before/paid-migrate-dryrun.txt
```

- 違った時に決めること: 警告は、申請ごとに確認し、5章の候補(1)の判断に反映する。

### (h) 旧システムが直接作った勤怠日の扱い

- 確認: 休暇の処理が直接作った勤怠日(`attendance_day.created` が無い日)の件数と内容。
- 実施: 5章の候補(2)の出力を使う。B12(`no_created`)は目安。
- 期待: 件数と内訳(最初のイベントの種類・行の有無)を記録する。
- 違った時に決めること:
  - 補正方法は、docs/32 の推奨どおり「補正イベント(`attendance_day.corrected` を今の時点に追記)」にする。
    欠けた `created` を過去の版へ挿入する方法は採らない(挿入は行わない)。
  - **補正コマンドの本体は未実装**(docs/32 の「残課題」)。`CorrectAttendanceDay` は存在するが、
    これを一括で発行する運用コマンドが無い。実施には実装が必要で、その前にユーザーが内容を承認する(data-correction ステップ3)。
  - 手順の順序(R3。5-4)を確定してから行う。

---

## 5. 補正候補の確認(`leave:correction-report`)

### 5-1. 実行のタイミング

- 3-5(移行)の**後**、6-4(勤怠のProjectorの再構築)の**前**に実行する。
- **要確認 R2**: 候補(1)の検出器は旧イベント(`*.usage_designated` 等)を読み、移行の結果(`migrated`)で除外しない。
  また、cutover後の有給で差戻しの状態は `workflow_request.returned` で表されるが、検出器の差戻し判定は
  `paid_leave_request.*` の状態だけを見る。そのため、移行の前に実行すると件数が少なく出る。
  移行後の件数が 0 にならない場合は、検出器の仕様を確認する。

```bash
cd <リハーサル用アプリ>/backend
php artisan leave:correction-report --limit=50 | tee ~/rehearsal-20261009/after/correction-report.txt
```

出力の見方: 各候補の `件数` と `採りうる方法`(`[推奨]` / `[不採用候補]`)、そして一覧(先頭 N 件)。

### 5-2. 候補ごとの判断

| 候補 | 内容 | 推奨(docs/32) | 判断の観点 | 直接修正の条件 |
|---|---|---|---|---|
| (1) | 差戻しの休暇の、未取消の消化記録 | 補正イベント(今の時点の取消を口座集約の取消で追記) | 差戻しは利用者の操作の記録であり、不具合による誤記録ではない。承認・差戻しの記録は書き換えない(原則13) | 採らない |
| (2) | 休暇の処理が直接作った勤怠日(`created` 無し) | 補正イベント(`attendance_day.corrected`) | 欠けた `created` を過去へ挿入しない | 採らない(挿入は直接修正として扱わない) |
| (3) | 編集イベント(created・edited)の休暇値(`workType` = `paid_leave_*` 等) | 直接修正(`workType` を `null` へ。版は変えない) | 休暇の処理が複写した値で、利用者の入力ではない。改竄の妥当性がある | ユーザーの明示的な許可が必要 |
| (4) | 勤怠日に残る休暇値・全休の `status` | (2)(3)の後に再生成し、残った行だけ補正イベント | 勤怠日は投影。直接UPDATEは行わない(原則2) | 採らない |

- (1)〜(4) の件数は `leave:correction-report` の出力で確認する。B12 の件数と比べて、差があれば理由を確認する。
- 補正の対象の**件数**と**修正前後の値の例**を、ユーザーに提示する。

### 5-3. 直接修正(候補(3))の許可依頼

data-correction ステップ2の手順により、一般的な「直してほしい」は許可とみなさない。次の形式で明示的な許可を得て、
許可の日時と内容を `spec.md` のレビュー履歴に記録する。

> 候補(3)の `stored_events` のうち `attendance_day.created` / `attendance_day.edited` の `workType` が休暇値
> (`paid_leave_*` / `special_leave_*` / `compensatory_leave_*`)のもの **N 件** の payload の `workType` を
> `null` に書き換えてよいか。版(`aggregate_version`)は変えない。修正前の payload は補正ログに残す。
> 対象の一覧(抜粋): ______。許可しますか?

### 5-4. 補正の順序(R3)

docs/32 の候補表は順序を示していない。次の順序を**提案**する。最終的な順序は、ユーザーが承認する。

1. (2) 補正イベント `attendance_day.corrected` を追記し、`created` が無い日の行を補完する。
2. (3) 直接修正(`workType` の休暇値を `null` に)。
3. 勤怠のProjectorを再生する(`AttendanceDayProjector`・`AttendanceDailyCalculationProjector`・`AttendanceWeeklyOvertimeAllocationProjector` 等)。
4. (4) 残った勤怠日の休暇値・全休の `status` に補正イベントを追記する。
5. 日次計算の再計算(5-5)と、月次スナップショットの確認(6-5)。

- (2) を (3) より先に行う理由: (2) を行わずに勤怠のProjectorを再生すると、`created` の無い行が消える(3-4の注意)。
- **要確認 R3**: この順序を変更セットに記録するかどうか。

### 5-5. 補正の実施と日次計算の再計算

- 補正コマンドの本体(`StoredEventCorrectionCommand` の子クラス、`CorrectAttendanceDay` を発行する運用コマンド)は
  **未実装**(docs/32 の残課題)。リハーサルでは検出と判断までを行い、本実行は実装後・許可後に行う。
- 補正後の日次計算は次のコマンドで確認する(既定は試し実行。締め・提出済みの日は一覧に印が付く)。

```bash
php artisan attendance:recalculate-days --from=2026-08-01 --to=2026-10-10 | tee ~/rehearsal-20261009/after/recalc-dryrun.txt
```

- 見る点: `[除外]`(手動調整済みで対象外になった日)の件数が、`attendance_day.daily_calculation_adjusted` を持つ
  日の数と合うか。`[変更]` の内容が、休暇の表示・月次の値に意図しない変化を起こしていないか。

```sql
-- 手動調整された勤怠日の数(期間で絞るなら aggregate_uuid と勤怠日を結合する)
SELECT COUNT(DISTINCT aggregate_uuid) AS adjusted_days
FROM stored_events
WHERE event_class = 'attendance_day.daily_calculation_adjusted';
```

- 本実行(`--apply`)は、ユーザーの許可後に行う。
- 記録: 件数・判断・日時を `spec.md` の「実装中の決定」に書く。

---

## 6. 移行後の検証

### 6-1. 外部キーと列(3-2と同じSQL)

3-2 の SQL を再実行し、期待どおりであることを確認する。

### 6-2. 移行前の記録(B01〜B15)との比較

移行後に、2章のSQL(B01〜B15)を同じ順で実行し、`~/rehearsal-20261009/after/` に保存する。
差分は次のように確認する。

```bash
diff ~/rehearsal-20261009/before/B02.tsv ~/rehearsal-20261009/after/B02.tsv > ~/rehearsal-20261009/after/diff-B02.txt
```

期待する差分(移行は月次の値を変えないという設計。差分が出た場合は、下の表と照合する):

| 基準 | 期待 | 差分が出た場合 |
|---|---|---|
| B01 月次スナップショット | 変わらない(移行は再計算しない) | 差分があれば、再計算(`attendance:recalculate-month-snapshots`)が走った可能性。原因を確認する |
| B02 月次の日次集計 | 変わらない(休暇だけの日も同じ日次計算) | 差分は、5章の補正(候補(4)・(2))・論点15の削除による変化を照合する |
| B03・B04・B05 付与の残数 | 変わらない | 差分は(c)の下書き・(f)の未充当の扱いで説明できるか確認する |
| B06 残高キャッシュ | 変わらない(再計算で一致する) | 6-4のリビルド結果と合わせて確認する |
| B07 消化記録 | 特別・代休は、差戻しの申請の消化記録が移行で減る(移行は差戻し分を含めない)。有給は旧系統の取消の表し方が未確認(R8) | 減る件数が、(a)〜(g)・候補(1)の件数と一致するか確認する。有給は R8 の結果で判断する |
| B08 申請の状態 | 変わらない | 差分が出たら、6-3の照合で該当の申請を特定する |
| B09 cutover前8件 | 8件のまま。休暇ビュー(6-3)に8件出る | 8件が出なければ、6-3の照合を先に確認する |
| B10 出勤率の判定 | 保存された判定は変わらない(再判定はしない) | 6-3の出勤率の照合を参照 |
| B11 特別休暇の自動付与 | 変わらない | 差分は、移行で付与の登録が口座に変わったことによるか確認する |
| B12 休暇値・全休 | 候補(3)・(4)の補正後は、休暇値と全休の `clocked_out` が 0 件になる | 補正前は残る(想定どおり)。補正後に残った件数を記録する |
| B13 休暇に紐づく申請 | 変わらない | 却下済みの申請中の休暇が取消になった件数(3-5 (iii))と合うか確認する |
| B14 月次の状態 | 変わらない | 差分があれば、締め・提出の操作が行われていないか確認する |
| B15 イベント件数 | 増えるのは、移行(`*_migrated`・`paid_leave_request.migrated`)と補正のイベントだけ | 件数の増え方を、移行の件数と照合する |

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

-- (6) cutover前の有給8件が休暇ビューに出る(期待: 8行、request_status = approved、source = legacy_paid)
SELECT v.leave_request_id, v.request_status, v.source, v.work_date, v.unit
FROM attendance_day_leaves v
WHERE v.leave_kind = 'paid'
  AND v.leave_request_id IN (SELECT id FROM paid_leave_requests
                             WHERE target_date BETWEEN '2026-08-10' AND '2026-09-04')
ORDER BY v.work_date;

-- (7) 勤怠日の source の内訳(移行で新しい勤怠日が作られていないこと。期待: 移行前(B12)と同じ)
SELECT source, COUNT(*) AS days FROM attendance_days GROUP BY source ORDER BY source;
```

- (6) の `source` は、旧系統の行は `legacy_paid`、cutover後の行は `paid_account` になる(`attendance_day_leaves.source` の値の定義)。
  期待と違う場合は、どの系統で作られたかを確認する。
- 出勤率の照合(B10 との比較): 有給の出勤率ビュー `leave_attendance_rate_days` の分母・分子が、B10 の保存された判定と
  一致するかを、利用者×期間で確認する。**要確認**: 判定の分母の定義(`is_working_day` の扱い)を、保存された判定と
  照らして確認する。

### 6-4. 空からの全件リビルドで同じ状態になることの確認(受け入れ条件)

対象は、休暇申請・残数・勤怠の休暇ビュー(受け入れ条件の「勤怠・休暇申請・残数の各Projectorを空から全件リビルド」)。

**手順(a) 状態の保存**: 6-2 の後、次の SQL を `~/rehearsal-20261009/rebuild/` に保存する(`-N -B`。`id` と `created_at`・`updated_at` は含めない)。

```sql
-- 休暇ビュー
SELECT leave_kind, leave_request_id, user_id, work_date, unit, hours, minutes, special_leave_type_id,
       workflow_request_id, request_status, source
FROM attendance_day_leaves ORDER BY leave_kind, leave_request_id;

-- 休暇ビューの消化記録(有給)
SELECT usage_id, leave_request_id FROM attendance_day_leave_paid_usages ORDER BY usage_id;

-- 有給申請
SELECT id, input_source, user_id, approver_user_id, status, leave_type, target_date, hours, requested_days,
       request_group_id, submitted_at, approved_at, returned_at, cancelled_at
FROM paid_leave_requests ORDER BY id;

-- 申請と業務の対応表
SELECT workflow_request_id, leave_kind, leave_request_id FROM leave_request_workflow_links ORDER BY workflow_request_id;

-- 有給の付与・消化・充当・残高(口座由来の行)
SELECT id, user_id, granted_on, expires_on, granted_days, allocated_days, remaining_days, status FROM paid_leave_grants ORDER BY id;
SELECT usage_id, user_id, used_on, used_days, used_minutes, usage_type, confirmed, cancelled, paid_leave_request_id
FROM paid_leave_usages WHERE usage_id IS NOT NULL ORDER BY usage_id;
SELECT usage_id, grant_id, allocated_days FROM paid_leave_usage_allocations ORDER BY usage_id, grant_id;
SELECT user_id, available_days, pending_days, unallocated_days FROM paid_leave_balances ORDER BY user_id;

-- 特別休暇・代休の付与と消化(**要確認 R1**: 旧Projectorと共有の行)
SELECT id, user_id, special_leave_type_id, granted_on, expires_on, granted_days, used_days, remaining_days, status
FROM special_leave_grants ORDER BY id;
SELECT usage_id, user_id, used_on, used_days, used_minutes, usage_type, is_confirmed, unallocated_days
FROM special_leave_usages WHERE usage_id IS NOT NULL ORDER BY usage_id;
SELECT id, user_id, source, work_date, granted_days, granted_minutes, used_days, used_minutes,
       remaining_days, remaining_minutes, status, expires_on
FROM compensatory_leave_grants ORDER BY id;
SELECT usage_id, user_id, used_on, used_days, used_minutes, usage_type, is_confirmed, unallocated_days, unallocated_minutes
FROM compensatory_leave_usages WHERE usage_id IS NOT NULL ORDER BY usage_id;

-- 代休の休日出勤・付与ビュー
SELECT user_id, work_date, is_holiday_day, work_minutes FROM compensatory_holiday_work_days ORDER BY user_id, work_date;
SELECT grant_id, user_id, work_date, granted_days, granted_minutes, status, used_days, used_minutes
FROM compensatory_grant_day_views ORDER BY grant_id;

-- 出勤率ビュー
SELECT user_id, work_date, is_working_day, attended, full_leave_kinds, partial_leave_kinds
FROM leave_attendance_rate_days ORDER BY user_id, work_date;
SELECT user_id, work_date, is_working_day, attended, work_style_id, full_leave_kinds, partial_leave_kinds
FROM special_leave_attendance_rate_days ORDER BY user_id, work_date;
```

**手順(b) 空にして再生**: 空にするテーブルは 3-4 の表と、(a)で保存した表に対応するものだけ。
(3-4 と同じ方法で TRUNCATE → `event-sourcing:replay`。Projectorの一覧は 3-4 の表。)

```bash
mysql -h <H> -u <U> -p -D flow_office_rehearsal -e "<3-4 と同じ TRUNCATE 文>"
php artisan event-sourcing:replay PaidLeaveRequestProjector --force
php artisan event-sourcing:replay AttendanceDayLeaveProjector --force
# ...(3-4 の Projector をすべて)
```

**手順(c) 比較**: (a) と同じSQLを `after-rebuild/` に保存し、差分を取る。

```bash
diff ~/rehearsal-20261009/rebuild/state-before.tsv ~/rehearsal-20261009/rebuild/state-after.tsv && echo SAME
```

- 期待: 差分なし。
- **要確認 R1**: `special_leave_grants`・`special_leave_usages`・`compensatory_leave_grants` は、旧Projector
  (`SpecialLeaveGrantProjector`・`SpecialLeaveUsageProjector`・`CompensatoryLeaveGrantProjector` 等。旧イベントを
  まだ読む)と新しい口座のProjectorが、同じテーブルへ書く。全件リビルドで、旧Projectorが古いイベントから作った行と、
  口座のProjectorが作った行が両方残るかもしれない。(b)の結果が (a) と違う場合は、この点を最初に確認する。
  ここで解決しなければ、受け入れ条件「全件リビルドで同じ状態」は未確認のまま。
- **順序の確認(任意だが推奨)**: (b) を、Projectorの再生の順序を逆にして行い、(a) と同じになるか確認する(順序非依存)。

### 6-5. 日次計算の再計算と月次スナップショットの確認(dry-run)

```bash
# 日次計算: 変わる値の一覧(既定は試し実行。書き込みなし)
php artisan attendance:recalculate-days --from=2026-08-01 --to=2026-10-10 | tee ~/rehearsal-20261009/after/recalc-dryrun-2.txt

# 月次スナップショット: 再計算すると変わる月の一覧(dry-run)
php artisan attendance:recalculate-month-snapshots --dry-run | tee ~/rehearsal-20261009/after/snapshot-dryrun.txt
```

- 期待: `変更あり 0 件`、または変わる日の理由が5章・6-2で説明できる。
- 手動調整された日は `[除外]` になり、上書きされない(受け入れ条件)。

### 6-6. 主要画面での目視確認

画面は本番相当データ・リハーサル環境で行う。各項目の結果を 8章の表に記録する。

| 画面(パス) | 確認する項目 | 期待 |
|---|---|---|
| 勤怠の日次 `/attendance/days/:date` | 休暇日の休暇ラベル(`leaves` から表示)。複数の休暇がある場合は複数表示 | 休暇ラベルが出る。全休・半休・時間休は、状態バッジの代わりに休暇ラベル |
| 同 | 休暇日の**時刻・作業内容の編集**をしても、休暇ラベルが消えない(不具合の再現確認。変更要望) | 消えない。作業内容(`work_type`)は休暇に影響しない |
| 同 | 作業内容欄は休暇日でも表示され、入力値がそのまま保存される | 表示・保存される |
| 勤怠の今日 `/attendance/today`・打刻 | 全休の日は出勤できない。打刻を取り込まない。半休の日は打刻できる | 全休: 出勤不可・警告なし。半休: 打刻可 |
| 勤怠の週・月 `/attendance/week`・`/attendance/months` | 休暇日数(有給・特別)と代休の付与表示。締めの状態 | 移行前(B01・B02)の値と一致する |
| 有給 `/paid-leave`・`/paid-leave/history`・`/paid-leave/grants/mine` | 残数。差戻しの申請の表示と「提出する」「取消」の導線 | 差戻しの申請が出る。再提出(申請詳細へ)・取消の導線がある |
| 特別休暇 `/special-leave`・`/special-leave/history` | 同上 | 同上 |
| 代休 `/compensatory-leave`・`/compensatory-leave/history/mine`・`/compensatory-leave/grants/mine` | 同上。付与一覧の下書き表示(c) | 同上 |
| 申請詳細 `/requests/:id` | 業務側(休暇)の申請に「却下」が出ない。差戻しの休暇は「提出する」が出る | 却下が出ない。提出が出る |
| 取消ダイアログ(承認済みの休暇の取消) | 「勤怠区分もクリアされます」の文言が無い | 文言が無い |
| cutover前の有給8件(`/paid-leave/history`・勤怠の日次) | 8件が表示される。承認済み。取消しようとすると「移行前の申請のため取消できません…」と出る | 8件表示・取消は拒否 |
| 締め済みの日の休暇 | 申請・取消がエラーになり、状態が変わらない | エラー。どの画面の表示も変わらない |
| 承認済みの休暇を管理者が取消 | 取消が成功する。ワークフローは承認済みのまま | 成功。申請詳細で承認済みのまま |

- 目視で見つけた不具合は、このファイルの8章に書く。修正は変更セットを更新してから行う(コードは直接触らない)。

---

## 7. 補正・移行の順序の一覧(実施の順)

1. 1章: 複製・commit・composer・`.env`(APP_KEY は本番と同じ)・通知の停止(0-2)
2. 2章: 移行前の記録 B01〜B15 と (a)(b)(c)(e)(f)(g)の試し実行の確認(旧スキーマ・migrateの前)
3. 3-1〜3-3: メンテナンス窓の模擬・migrate・外部キーの確認・権限カタログ
4. 3-4: 新設Projectorのテーブルを空にして再生(勤怠のテーブルは空にしない)
5. 3-5: 移行コマンド (i)(ii)(iii) の試し実行 → 件数の確認 → `--apply` → 再実行で全件 skip
6. 5章: `leave:correction-report` → 判断 → (承認後に)補正(実装後)
7. 6-2〜6-3: 比較と照合
8. 6-4: 空からのリビルド(勤怠のProjectorは補正の後)
9. 6-5: 日次計算・月次スナップショットの dry-run
10. 6-6: 目視確認

---

## 8. 結果の記録欄

判断と件数は、この表に書いた後、変更セット `spec.md` の「実装中の決定」とレビュー履歴にも記録する。

| 項目 | 件数・結果 | 判断(何をするか) | 実施日 | 担当 | 備考 |
|---|---|---|---|---|---|
| 1-1 複製(本番の stored_events 件数 / 複製の件数) | | | | | |
| 1-2 commit | | | | | |
| 1-4 APP_KEY の設定 | | | | | |
| 0-2 通知の停止(system_settings) | | | | | |
| B01〜B15(保存先) | | | | | |
| B09 cutover前8件 | | | | | 8件か |
| 3-2 migrate・外部キーの確認 | | | | | |
| 3-4 Projector(1)LeaveRequestWorkflowLink | | | | | 秒・行数 |
| 3-4 Projector(2)PaidLeaveRequest | | | | | 秒・行数 |
| 3-4 Projector(3)AttendanceDayLeave | | | | | 秒・行数 |
| 3-4 Projector(4)〜(7) | | | | | 秒・行数 |
| 3-5 (i) 特別休暇 試し実行 / 本実行 | 失敗 __名、付与 __件、消化記録 __件 | | | | |
| 3-5 (ii) 代休 試し実行 / 本実行 | 失敗 __名、付与 __件、消化記録 __件 | | | | |
| 3-5 (iii) 有給申請 試し実行 / 本実行 | 対象 __件、警告 __件、失敗 __件 | | | | |
| (a) 残数不足のまま承認 | 特別 __件 / 代休 __件 | | | | |
| (b) 時間単位で分数 null | 申請 __件 / 消化 __件 | | | | |
| (c) 代休の下書き | 件数 __ / 残数合計 __ | | | | |
| (d) 所要時間・行数の一致 | 所要 __秒 / 行数一致 有・無 | | | | |
| (e) paidLeaveRequestId が null | __件 | | | | |
| (f) 未充当量(特別・代休・有給) | 特別 __件 / 代休 __件 / 有給 __件 | | | | |
| (g) 警告の種類ごとの件数 | 対応無し __ / 差戻し未取消 __ / 承認で消化無し __ | | | | |
| (h) 直接作られた勤怠日 | __件(内訳: ) | | | | |
| 候補(1) 差戻しの未取消 | __件 | | | | |
| 候補(2) created 無しの勤怠日 | __件 | | | | |
| 候補(3) 編集イベントの休暇値 | __件 | | | | 許可の日時 |
| 候補(4) 勤怠日の休暇値・全休 | __件 | | | | |
| 6-2 差分(B01〜B15) | | | | | |
| 6-3 照合(1)〜(7) | 不一致 __件 | | | | |
| 6-4 リビルドの差分 | 差分 __行 | | | | R1 の結果 |
| 6-5 日次・月次 dry-run | 変更 __件 / 除外 __件 | | | | |
| 6-6 画面 | 不具合 __件 | | | | |

---

## 9. 要確認の一覧(実施前に決める・確認する)

- **R1(重要)**: 特別休暇・代休の付与・消化は、旧Projectorと口座のProjectorが同じテーブルに書く。全件リビルド(6-4)で
  同じ状態になるかは、コードからは確定できない。
- **R2**: 候補(1)の検出器は、移行後の状態(`migrated`)を除外しない。cutover後の有給の差戻しは `workflow_request.returned` で
  表されるが、差戻し判定に含まれていない。移行後の件数が 0 にならない場合は、検出器の仕様を確認する。
- **R3**: 補正の順序(2)→(3)→勤怠のProjector再構築→(4)。docs/32 の表は順序を示していない。変更セットに記録するか。
- **R4**: 補正コマンド本体(`StoredEventCorrectionCommand` の子クラス、`CorrectAttendanceDay` の一括発行)は未実装。
  直接修正・補正イベントの本実行には実装が必要。
- **R5**: 本番DBの複製の手順(mysqldump のオプション・権限・MySQLのバージョン)は既存の資料に無い。
- **R6**: 暗号化された値の復号のため、リハーサルの `APP_KEY` は本番の値を使う。暗号化カラムの一覧は未確認。
- **R7**: 特別休暇のうち残数を要しない種別は消化記録が無いため、(a)・(f) の件数に入る。種別の区分で除外する。
- **R8**: 旧系統の有給の消化記録の取消の表し方(`paid_leave_usages.cancelled` が旧系統の行で使われるか)。
- **R9**: 代休の時間単位で分数が null の申請は、移行で 0 分として扱われる(例外にならない)。
- **R10**: docs/27 3.1 の migrate の範囲(`000001`〜`000008`)と、リポジトリの `000009`(補正ログ)の不一致。
- **R11**: spatie の replay コマンドの引数と挙動(`--force`・短いクラス名)は、既存コードの使い方から推定した。
- **R12**: `attendance_daily_calculations.attendance_day_id` が 1 日 1 行か(B02 の合計の前提)。
- **R13**: 6-3 (7) の `attendance_days.source` の `leave` の扱い(論点3の `source=leave` は、移行では作られない想定)。
