# PR#121のCIの失敗(backendのテスト4件・MySQLのmigration)を直す

ステータス: 完了

## 変更要望(原文)
> PRのCIがエラーになっています。修正をお願いします。

## 背景・目的
PR#121(`docs/paid-leave-schedule-investigation`、head `c50cb6f`)のCIで、次の2つのジョブが失敗した。どちらもこのPRの変更が原因(mainのCIは緑)。
- `tests`: 4件が失敗(`SeededDataRebuildScenarioTest`の2件、`FiscalYearStartChangeScenarioTest`の2件)。
- `migrate-mysql`: `migrate:rollback`で`2026_10_21_000003`のdown()が失敗した。

手元では、`./vendor/bin/phpunit`(phpunit.xmlの値、in-memoryのsqlite、同期キュー)で通っていた。CIは`.env.example`を`.env`にして`php artisan test`で動かすため、`.env`の`QUEUE_CONNECTION=database`・`DB_DATABASE=database/database.sqlite`・`APP_KEY`が効く。

## 原因
1. `SeededDataRebuildScenarioTest`
   - `routes/console.php`が、スケジュールの定義のときに`ScheduleAssessmentRunService::defaultTimezone()`を呼ぶ。
   - これが`SystemSetting::current()`(`firstOrCreate`)を呼ぶので、アプリの起動のたび(テストの`setUp`も含む)に`system_settings`の行ができる。`m365_tenant_id`には`config('services.azure.tenant')`の`common`が入る。
   - ファイルのsqliteでは、RefreshDatabaseのトランザクションの外で作られたこの行が、次のテストにも残る。その結果、作り直しのコマンドが「`external_tenant_id`(tenant)が`system_settings.m365_tenant_id`(common)と違う」で止まる。
   - APP_KEYが無いと、暗号化の列のせいで行が作れずに例外になり、握りつぶされる。そのため手元では起きなかった。
2. `FiscalYearStartChangeScenarioTest`
   - 年度開始日の変更のReactorは、`ReapplyPaidLeaveSchedulePolicyJob`をキューに積む。
   - テストは、ジョブがその場で動く前提(同期キュー)で書かれていた。CIのキュー(database)では、ジョブが動かない。
3. `migrate-mysql`
   - `000004`(法定の写し・比例の区分への移行)はdown()で行を戻さない。そのため、区分ごとに同じ継続勤務月数の行が残る。
   - その状態で、`000003`のdown()が古いunique(rule_id, continuous_service_months)を付け直すので、重複で失敗する(sqliteでも同じ)。

## 修正方針
1. `ScheduleAssessmentRunService::defaultTimezone()`は、`system_settings`を読むだけにして、行を作らない(`SystemSetting::query()->value('default_timezone')`)。
   - 行が無い・空・例外のときは`Asia/Tokyo`にする(`SystemSetting::current()`の初期値と同じ)。
   - スケジュールの定義(cron式・タイムゾーン)は変えない。
2. `FiscalYearStartChangeScenarioTest`の`setUp`で、キューを同期にする(`config(['queue.default' => 'sync'])`)。ジョブがその場で動くことを前提にしていると、コメントに書く。`Queue::fake()`を使うテストは、そのままにする。
3. `000003`のdown()で、古いuniqueを付け直す前に、`grant_category`が`normal`以外の行を消す。
   - 古い表の形(区分なし)では、表せない行だから。
   - コメントに理由を書く。

## 対象外
- `routes/console.php`のスケジュールの書き方の変更。
- `000004`のdown()の変更(法定の写しを残す方針はそのまま)。

## テスト
- `ScheduleAssessmentRunService::defaultTimezone()`は、`system_settings`の行が無いときに`Asia/Tokyo`を返し、行を作らない。行があれば、その`default_timezone`を返す。
- `PaidLeaveFiscalMassGrantMigrationsTest`: 通常と比例の区分で同じ月数の行がある状態で、`000003`のdown()が失敗せず、`normal`の行だけが残る。テストの最後に、up()で元に戻す。
- CIと同じ条件(`.env.example`を`.env`にして`php artisan test`)で、backendの全件が通る。
- MySQLは、この環境では動かせない。CIの`migrate-mysql`で確かめる。

## ドキュメントへの影響
なし(`docs/changesets/README.md`に行を足す)

## 実装結果
- 修正方針の1〜3のとおりに直した(implementer)。
- テストを足した
  - `ScheduleAssessmentRunApiTest`: 2件(行が無いときの既定値と行を作らないこと、保存した値を読むこと)。
  - `PaidLeaveFiscalMassGrantMigrationsTest`: 1件(`000003`のdown()とup())。
- 検証(委譲元で実行)
  - CIと同じ条件(`.env.example`を`.env`にして`php artisan test`): 2117件のうち2116件がPASS。
  - 失敗の1件は`ReadModelFingerprintCommandTest`。手元ではmainでも失敗し、CIでは通るので、この変更とは関係ない。
- 未検証: MySQLでの`migrate:rollback`。pushの後に、CIの`migrate-mysql`で確かめる。
