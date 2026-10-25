# 9. 有給管理ユースケース

年次有給休暇のWrite Modelは`App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate`
(AggregateId = `PaidLeaveAccountAggregate::streamIdFor(userId)`。社員IDから派生する別のストリーム。各イベントは`userId`を持つ)を単一の集約ルートとする。社員一人の年休台帳全体
(Grant/Usage/Allocation)の不変条件をこの集約自身がreplayのみで保証し、Handlerが
Eloquent Projectionを読んで業務ルールを判定することはしない
(docs/changesets/20260906-paid-leave-domain-redesign/spec.md参照)。旧`PaidLeaveGrantAggregate`
/`PaidLeaveRequestAggregate`(`App\Domain\PaidLeave\*`)は同spec Phase 5で削除済みで、
現存しない。

## UC-P001: 有給付与ルールを作成する

1. 管理者が付与ルールを作成する
2. 正社員、短時間勤務、週4勤務などの対象を設定する
3. 継続勤務期間ごとの付与日数を設定する
4. 出勤率条件を設定する
5. ルールを保存する

年次有給休暇は原則として、雇入れから6か月継続勤務し、全労働日の8割以上出勤した労働者に
付与される。短時間労働者にも所定労働日数に応じた比例付与がある。
(出典: 連合(日本労働組合総連合会))

付与ルール(`paid_leave_grant_rules`/`paid_leave_grant_rule_steps`)は継続勤務期間ごとの
付与日数・出勤率条件をマスタ化したもので、UC-P002の日次バッチが判定に用いる。法定の
通常/比例付与判定・シフト勤務判定を独立ドメイン化した`PaidLeaveScheduleAggregate`/
Assessmentは別の変更セット(docs/changesets/20260906-paid-leave-schedule-assessment/spec.md)
で実装済み(UC-P011〜UC-P015参照)。既存のルールマスタ・`paid-leave:grant-scheduled`は
UC-P011の`paid-leave:roll-schedules`に置換される。

## UC-P002: 有給を自動付与する

> **旧実装からの置き換え(2026-09)**: 以前は`paid-leave:grant-scheduled`
> (`GrantScheduledPaidLeaveHandler`)による「毎日全社員を評価しその場でGrant確定」の
> 日次バッチ方式だったが、`App\Domain\PaidLeaveSchedule`ドメイン新設に伴いこの
> ハンドラ・コマンドは削除された。現在は「事前生成 → 出勤率Assessment →
> 管理者が確認の上で一括付与」の3段階フローに置き換わっている
> (`docs/changesets/20260906-paid-leave-schedule-assessment/spec.md`参照)。

1. `paid-leave:roll-schedules`コマンド(cron、日次実行、`routes/console.php`)が
   `ScheduleCandidateGenerator`を使い、対象社員それぞれについて今日から1年先までの
   付与予定日候補(`scheduledOn`・区分・候補日数)を算出し、明日以降の付与予定を再計算して
   `paid_leave_schedule_entries`へ反映する(`App\Domain\PaidLeaveSchedule\Aggregates\
   PaidLeaveScheduleAggregate`のイベントソーシング経由。変化が無い行には何も記録しない)。
2. 人事担当者は「有給休暇」画面(`/admin/paid-leave`)の「付与ルール」カードにある
   「付与予定を確認」ボタンから付与予定画面(`/admin/paid-leave/schedule`)を開き、これから付与予定の
   社員一覧・出勤率Assessment結果を事前に確認できる。付与予定日を過ぎて未付与の付与予定がある場合は、
   同ボタンに件数を表示する(UC-P013)。
3. 判定不能なエントリ(後述)は`NeedsReview`状態で表示され、担当者が内容を確認する。
4. 担当者が`Eligible`のエントリを選び、管理画面から一括付与を実行すると
   `App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave`が発行され、
   `PaidLeaveGrantCreated`イベントとしてGrantが記録される(有効期限は
   付与日+2年、UC-P002共通の既存Commandを再利用)。

### 対象社員・付与予定日の決定

1. `users.hire_date`(入社日)が設定済みの社員を対象にする。MS365には入社日に相当する
   属性がないため同期対象外で、`user_profile.update` Permissionを持つ担当者が個別に設定する
   (`PUT /api/users/{user}/hire-date`)。
2. `users.paid_leave_auto_grant_enabled = true`かつ`usage_start_date`設定済みの社員のみを
   対象とする(UC-P00X参照)。
3. 対象社員に一致する有効な付与ルール(`paid_leave_grant_rules`、`work_style_id`固有の
   ルールを優先し、無ければ`work_style_id IS NULL`の全社共通ルールへフォールバック)が
   **1件も存在しない場合、その社員のScheduleエントリは一切生成されない**
   (以前は付与ルールが無くても既定値〈6ヶ月後・周年〉で機械的に生成されてしまう
   不具合があったが修正済み。`docs/changesets/20260914-port-to-pr112/spec.md`参照)。
4. 付与サイクルは`grant_cycle_type`により2方式ある(`paid_leave_grant_rules`参照)。**初期値(列の既定値・
   作成APIで未指定のとき・初期の全社ルール)は「年度はじめに一斉付与」(`mass_grant_month`)**。
   - `mass_grant_month`(年度はじめに一斉付与): 一斉付与日は、**既定の会社カレンダーの年度開始日**
     (`company_calendars.fiscal_year_start_month/day`の既定カレンダー。UC-C009)に連動する。ルールに月は持たない。
     付与予定の文脈の読み取りビュー`paid_leave_fiscal_year_settings`(1行。行が無ければ4月1日)を
     `FiscalYearStartResolver`が読む(`PaidLeaveFiscalYearSettingsProjector`が会社カレンダーのイベントから作る)。
     年度開始日の計算は会社カレンダーの年度生成(`FiscalYearCalculator`)と共通。
     - 付与済みの付与予定が無い社員: 初回は「入社日より後で最初の年度開始日」と「入社日+`first_grant_after_months`
       (`addMonthsNoOverflow`)」の早い方(前倒しは可、遅らせない)。名目の継続勤務月数は`first_grant_after_months`(既定6)。
       初回が前倒し(年度開始日が入社日+`first_grant_after_months`より前)なら、**同じ年度開始日に名目`first+12`(18か月)の
       2件目もまとめて付与する**(年度開始日と半年の日が同じ日は前倒しではないので1件)。以後は、前回より後で最初の年度開始日ごと、
       名目+12か月。
       例(年度開始4/1・通常の社員): 2026-01-01入社→2026-04-01に名目6(10日)と名目18(11日)、2027-04-01に名目30(12日)。
       2025-10-01入社→2026-04-01に名目6の1件、2027-04-01に名目18。2026-04-01入社→2026-10-01に名目6、2027-04-01に名目18。
       2026-03-31入社→翌日の2026-04-01に2件(計21日)。
     - 付与済みの付与予定がある社員: 最後の付与済みの付与予定日(同じ日に2件なら名目の大きい方)を起点に、起点より後で
       最初の年度開始日、名目+12か月。以後は毎年。年度開始日を変えた年は、前回から1年以内(短い間隔)で次の付与が来る
       (前回から1年を超えて遅らせない)。付与済みの行は付与予定のProjection(`paid_leave_schedule_entries`の
       `status=Granted`・`nominal_service_months`)から読む。
     - 既定のカレンダーの年度開始日を変えると、未確定の付与予定が作り直される(`ReapplyScheduleOnFiscalYearStartChangedReactor`が
       `ReapplyPaidLeaveSchedulePolicyJob`を発行。名前だけの変更では作り直さない)。
   - `anniversary`(入社日を基準に付与): 入社日起算の周年サイクル。初回は`first_grant_after_months`ヶ月後
     (`addMonthsNoOverflow`)、以降`grant_cycle_months`ヶ月ごと。
   - 8/30・8/31入社の半年後は、月末にそろえる(`addMonthsNoOverflow`。2/30・2/31があふれて3/2・3/3にならない)。
   - 日付・名目の月数の計算は`ScheduleCandidateGenerator::massGrantScheduledDates()`。
5. `usage_start_date`(本システムでの有給管理開始日)より前の付与日の行は作らない(付与日が利用開始日と同じ日なら作る)。
   `hire_date`が本システム導入前の実際の入社日である既存の社員向けのガードで、付与日と名目の月数は入社日から計算する。
   例: 2026-01-01入社・利用開始日2026-10-01の社員は、2026-04-01の2件は作らず、2027-04-01の名目30か月(12日)から作る。
   利用開始日より前の分(手動の付与など)は、システムの計算に入れない。

### 付与候補日数の決定・判定不能時の扱い

1. 付与ルールの付与日数の表(`paid_leave_grant_rule_steps`)は**区分(`grant_category`)ごと**に持つ。区分は
   `normal`(通常)/`proportional_4`/`proportional_3`/`proportional_2`/`proportional_1`(比例付与の週所定日数)。
   社員の区分から使う表を決め、対象の継続勤務月数(`mass_grant_month`方式で前倒しされた場合も、実際の暦月数ではなく
   「名目上の」継続勤務月数〈初回=`first_grant_after_months`、以降+12ずつ〉を使う)をカバーする最大の
   `continuous_service_months`の行を採用する。
   - 通常の社員: `normal`の表。
   - 比例の社員: 週所定日数(`weekly_scheduled_days`)が1以上5未満ならその整数部分(切り捨て)の`proportional_N`。週所定日数が無ければ
     年間所定日数(`annual_scheduled_days`)で決める(労基則24条の3: 169〜216→4、121〜168→3、73〜120→2、48〜72→1)。
     週0日、または年間48日未満は決められない(要確認)。
   - シフトの社員: `normal`の表(今の目安の比例表より日数が増えることがある)。
   - 勤務形態が無い・区分を判定できない社員、該当する区分の表が無い・継続勤務月数に合う段が無い場合は、法定へ自動で切り替えず
     要確認(下の2.)にする。
   - ルールの作成・編集では、行の区分に対応する法定表(通常=法定通常付与表、`proportional_N`=法定比例付与表の週N日)を
     下回る日数を422にする(区分ごとにエラー)。
   - 初期の全社ルール「全社共通(年度はじめ一斉付与)」(`work_style_id`がnull、`mass_grant_month`)には、現在有効な法定マスタの版
     (無ければシーダと同じ値)の写しを、全区分に入れてある(migration)。
     通常: 6→10, 18→11, 30→12, 42→14, 54→16, 66→18, 78→20。週4: 7, 8, 9, 10, 12, 13, 15。週3: 5, 6, 6, 8, 9, 10, 11。
     週2: 3, 4, 4, 5, 6, 6, 7。週1: 1, 2, 2, 2, 3, 3, 3。既存の勤務形態のルールのうち、勤務形態が比例に分類されるものは、
     既存の表を`proportional_{N}`へ移してある。
2. 付与ルールが無い場合(work_style固有・全社共通いずれも無い場合)は、その社員の
   Scheduleエントリ自体が生成されない(上記参照)。付与ルールはあるが該当stepが
   無い場合、`WorkStyle`の区分(通常/比例/シフト)が未確定(`NEEDS_REVIEW`)な場合、
   法定Policy(`paid_leave_grant_policies`/`paid_leave_proportional_grant_policies`)に
   該当行が無い場合は、いずれも「候補日数が確定できない」として扱い、エントリを
   `Scheduled`ではなく`NeedsReview`状態で作成する(候補日数0で黙って`Scheduled`
   扱いにしていた不具合を修正済み)。
3. 出勤率(`min_attendance_rate`)は、判定期間(下記)の
   `employee_calendar_entries`(勤務予定日)を分母、勤怠が退勤済みの日または有給の
   休暇(全休・半休・時間休)の日を分子として計算する(有給取得日は出勤したものとして扱う)。
   分子の入力は有給の出勤率ビュー(`leave_attendance_rate_days`・`leave_attendance_rate_leaves`。
   勤怠のイベントと休暇申請のイベントから作る。変更セット`20261009-keep-leave-work-type-on-edit`
   の論点9)で、勤怠日の`work_type`は読まない。差し戻された有給は休暇から外れるため出勤扱いにならない。
   期間中に勤務予定日が1件も無い場合は判定不能として扱う。
   閾値・判定周期は、社員の勤務形態のルール→全社ルールの順(`PaidLeaveGrantRuleResolver`)で選んだルールの値を使う。

#### 判定期間とみなし出勤(社労士の確認が前提)

判定の内容は`PaidLeaveScheduleAssessmentPlanner`が決める(再判定APIと自動判定の両方)。
docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点5・10。

- 行の位置づけ(同じ社員の付与予定のうち、取消を除く): **初回**は、付与予定日が「入社日+`first_grant_after_months`」以前で、
  それより前の行が無い行。**前回**は、付与予定日が対象の行より前の行のうち最も遅い行。
- 期間: 終わりは付与予定日の**前日**。始まりは、初回なら入社日、2回目以降なら前回の付与予定日。初回でも前回も無い行
  (利用開始日の都合で前の行が作られていない)は「付与予定日−`grant_cycle_months`」。
- 前倒しの短縮期間(みなし出勤): 本来の基準日は、初回なら「入社日+`first_grant_after_months`」、2回目以降なら
  「前回の付与予定日+`grant_cycle_months`」。付与予定日が本来の基準日より前なら、付与予定日から基準日の前日までを短縮期間とし、
  短縮期間は全出勤とみなす(平6.1.4基発1号の考え方)。みなし日数は暦日で按分して推計する:
  round(実期間の所定労働日数 × 短縮期間の暦日数 ÷ 実期間の暦日数)。
  出勤率 = (出勤日数 + みなし日数) ÷ (所定労働日数 + みなし日数)。
  - 例: 入社2026-01-10、年度開始4/1、初回6か月。実期間1/10〜3/31(81日)、短縮期間4/1〜7/9(100日)。所定55日・出勤50日なら、
    みなし日数=round(55×100/81)=68、出勤率=(50+68)/(55+68)=95.9%で付与対象。
  - 記録: `paid_leave_schedule_assessments.deemed_attendance_days`(古い行・みなしの無い行は0)。`denominator_days`/
    `attendance_days`にはみなし日数を含めない(記録から出勤率を計算し直せる)。`policy_version`は`v2`。
- **まとめて付与する2件の2件目**(同じ付与予定日で名目が「初回の月数+12」、同じ日に名目が初回の月数の行がある): 判定の期間は
  すべて前倒しで短縮された期間に当たるので、1件目の判定とは共有せず、全出勤とみなして付与対象にする(初回が対象外でも付与対象)。
  期間は付与予定日から本来の基準日(入社日+初回の月数+12か月)の前日まで、日数は持たず(0)出勤率は100%と記録する。
- 利用開始日の規則: 期間の始まりが`usage_start_date`より前で、移行データ(`source=carried_over`の付与)が無い場合は、
  どの行でも要確認(導入1年目は、移行データの無い社員はほぼ全員が要確認になる)。移行データの有無は有給口座の読み取りビュー
  (`PaidLeaveGrant`)を直接読んで確かめる(原則15の既存の例外)。
- **みなし出勤の取り扱いは社労士の確認が前提**(最終設定は社労士確認が前提。原則8)。画面の判定内訳にもその注記を出す。

#### まとめて付与する2件(組)の規則

同じ社員・同じ付与予定日で、名目の継続勤務月数が初回とその+12か月の2行は、常に組で扱う(`PaidLeaveScheduleAggregate::pairedEntryId`)。
- 判定の上書き・個別修正・置き換え(再計算でのSuperseded)・取消は、組の2行に同じ操作が記録される。
- 再判定は、Plannerで各行を判定して両方記録する(組の2行は別々に判定する)。
- 一括付与は、組の片方だけを選べない(APIは422「まとめて付与する2件は、同時に選んでください」。画面は片方を選ぶともう片方も選ぶ)。
  付与は1回の`ApplyScheduledGrants`で2件を続けて行い、有給口座には2件を1回のpersist(`GrantPaidLeaves`)で記録する。
  片方の付与に失敗したら、もう片方も付与しない。

### 法定付与日数の設定

通常付与(`paid_leave_grant_policies`)・比例付与(`paid_leave_proportional_grant_policies`)
の法定日数表は、管理画面(`/admin/paid-leave`)から現在バージョンの参照・新バージョンの
作成ができる(既存versionの行は変更しない)。編集フォームには「この表の変更は法令に
基づく設定です。保存前に社労士等の専門家に確認してください。」という警告文を表示する。

特別休暇の自動付与は`users.special_leave_auto_grant_enabled = true`の社員のみが対象で、
`GrantScheduledSpecialLeaveHandler`が種別を問わず一括で判定する(本UC-P002の
Schedule/Assessment化の対象外。今後の課題)。

### 過去の付与予定Scheduleエントリを再作成する(データ是正用)

付与ルール・法定付与ポリシーの変更時は`ReapplyPaidLeaveSchedulePolicyJob`により未来分の
Scheduleエントリが再計算され、その後も毎日の`paid-leave:roll-schedules`(今日から1年先までの
再計算、`fromDate`は明日)で追従する。いずれも、**過去日付・当日のエントリについては自動再計算
の対象外**である(既存エントリの変更は避け、新規トラブル時のリスクを最小化する設計)。

過去分のScheduleエントリにデータ不整合(ルール変更の取り込みミス、移行エラーなど)が見つかった
場合、管理者は以下のコマンドで是正できる:

```bash
# CLI から直接実行、または管理画面(/admin/commands)から実行可能
php artisan paid-leave:schedule:rebuild \
  --rule-id=123 \
  --from=2025-01-01 \
  --to=2026-12-31 \
  --reason="ルール変更の取り込みミスを是正"
```

実行内容:

1. オプション`--rule-id`(整数、省略可): 付与ルール ID を指定すると、そのルールに**現在マッチする**
   社員のみが対象になる。未指定時は対象社員条件(`employment_status=active`かつ`hire_date`設定済みかつ
   `paid_leave_auto_grant_enabled=true`)を満たす全社員が対象。
2. オプション`--from`・`--to`(YYYY-MM-DD形式、いずれも省略可): 対象期間を指定する。
   - `--from`未指定: 各社員の`hire_date`
   - `--to`未指定: 今日 + 1年
3. オプション`--reason`(必須): 取消イベントの理由として`stored_events`に記録される。
4. **Granted エントリ(確定済み付与)も取消+再作成される**が、Account側の実際の付与(`paid_leave_grants`)
   には一切影響しない(イベントアーキテクチャ上、Schedule側の取消イベントが Account を購読しない)。
5. **冪等性**: 内容が変わらないエントリについては取消・再作成が発生しない(2回実行しても1回目と
   同じ結果)。
6. **エラー耐性**: 対象社員の一部でエラーが発生しても、他の社員の処理は継続される。実行結果
   (対象者数・成功/失敗件数)はコンソール出力・`admin_command_runs`テーブルに記録される。

Cancelled(既に取消済み)エントリは再度取消の対象外とするため、対象は`Cancelled`以外の全ステータス
(Scheduled, AssessmentPending, Eligible, NotEligible, NeedsReview, Granted)。

## UC-P00X: 社員ごとの有給/特別休暇自動付与ON/OFFを設定する

出向者・休職者・契約上休暇を個別管理する社員など、付与ルールの対象条件には合致するが
自動付与の対象から外したい社員を個別に除外できるようにする
(docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。

1. `users.paid_leave_auto_grant_enabled` / `users.special_leave_auto_grant_enabled`
   (いずれもboolean, default true)で制御する。特別休暇は休暇種別を問わず1つのフラグで
   一括ON/OFFする(種別ごとの個別制御はしない)。
2. `App\Domain\UserManagement` 配下でCommand(`SetPaidLeaveAutoGrantEnabled` /
   `SetSpecialLeaveAutoGrantEnabled`)→Event(`PaidLeaveAutoGrantEnabledSet` /
   `SpecialLeaveAutoGrantEnabledSet`)→`UserProjector`経由で更新する
   (`hire_date`/`usage_start_date`と同じパターン)。
3. 設定経路は2つ(裏側の設定値は1つずつなので二重管理にはならない):
   - 社員個別編集画面(`UserRoleEditPage.tsx`)から、入社日等と同じ「保存ボタンで確定」方式
     で切り替える(`PUT /api/users/{user}/paid-leave-auto-grant-enabled` /
     `.../special-leave-auto-grant-enabled`、権限は`user.update`)。
   - 付与ルール管理画面(`PaidLeaveAdminPage.tsx` / `SpecialLeaveAdminPage.tsx`)の各ルールの
     「対象社員」一覧から、ルールの対象条件に現在マッチする社員一覧を表示し
     (`GET /api/paid-leave/grant-rules/{rule}/target-users` /
     `GET /api/special-leave/grant-rules/{rule}/target-users`)、行ごとのチェックボックスで
     即時に切り替える(同じ更新APIを叩く)。
4. デフォルトは`true`。マイグレーション適用直後は既存の全社員が有効のままであり、
   本機能追加による既存挙動の変化はない。

## UC-P003: 有給を申請する

1. 社員が有給申請を作成する
2. 対象日を選択する
3. 全休、午前半休、午後半休、時間休を選択する
4. `approval.execute` Permissionを持つ任意の社員から承認者を選択する
5. 有給残数を確認する
6. 勤務予定日であることを確認する
7. 申請する

申請単位ごとの取得日数は以下のように決まる。

- 全休: 1.0日
- 午前半休・午後半休: 0.5日
- 時間休: 取得時間 ÷ 対象日の所定労働時間(`employee_calendar_entries.work_style.prescribed_daily_minutes`)

手順5(有給残数を確認する)・手順6(勤務予定日であることを確認する)は申請時にAPIで検証し、
不足・対象外の場合は申請自体を拒否する(422)。勤務予定日であることの判定と、時間休の時間数が所定労働時間の1日未満であることの
判定は、勤怠文脈の休暇の反映(`ApplyLeaveToAttendanceDay`。判定は`LeaveApplicabilityPolicy`)が行う。有給の申請の
Handlerは勤怠の勤務予定を読んで拒否せず、休暇の申請・再提出の連鎖の中で勤怠が拒否して連鎖全体(申請を含む)が取り消される
(特別休暇・代休も同じ。時間休の日数への換算のための所定労働時間の読み取りだけは申請のHandlerに残る)。同一社員・同一対象日への重複申請
(提出中・承認済みが既にある場合)も拒否する。これらの重複申請チェックはPaidLeaveドメインの
外側(Workflow・申請入口側)の判定として残る(CLAUDE.md原則14、spec.md「Command/Handler」節)。

本人向けの付与一覧(`GET /paid-leave/grants/mine`)は、使える残りがある付与だけを返す(取り消した付与(status=revoked)と残日数0の付与は表示しない)。
管理者向けの一覧(`GET /paid-leave/grants/user/{userId}`)は取消済みを含む。

自分の申請一覧(`GET /paid-leave/requests/mine`)は、対象日の降順(同じ日はidの降順)に20件ずつのページングで返す(応答は`{ data, links, meta }`。`page`・`target_date`(`Y-m-d`。指定するとその日の申請だけ)を受ける)。画面のページ番号はURLの`requestPage`に載る。

申請そのものの進行状況(`paid_leave_requests.status`: submitted → approved / returned /
cancelled)は独立ステータス系列として引き続き管理するが、実際にGrantへどう充当するか
(Allocation)を決めるのは常に`PaidLeaveAccountAggregate`である。申請時点では
`DesignatePaidLeaveUsage` Commandにより`PaidLeaveUsageDesignated`イベントが記録され、
未確定(`confirmed=false`)のUsageが1件作られる(下記「Usageのライフサイクル」参照)。

### 期間指定でまとめて申請する(複数日分)

フロントエンドは日付を1件ずつ選ぶ方法と、期間(開始日〜終了日)を選ぶ方法の2通りで
複数日分をまとめて申請できる。バックエンドAPIは1申請=1対象日のままだが(日ごとの
勤務予定・残数チェックを崩さないため)、同じ申請操作で作成した行は`request_group_id`
(nullable uuid)で束ねる。取得単位(全休/半休/時間休)は対象日が1日のみの場合に限り
選択でき、2日以上をまとめて申請する場合は全休固定になる(半休・時間休は1日単位の
概念のため)。

## UC-P004: 有給を承認する

1. 承認者が有給申請を確認する
2. 問題なければ承認する
3. 承認により、`PaidLeaveRequestAggregate`が申請を承認済みにし(`paid_leave_request.approved`)、
   有給の口座(`PaidLeaveAccountAggregate`)が`ConfirmPaidLeaveUsage`で消化記録を確定する
   (`paid_leave_request.approved`を受けるReactor)
4. `PaidLeaveAccountAggregate`が最新のGrant時系列に対しAllocationを実行し、Usageを確定する
5. `PaidLeaveUsageConfirmed`イベント・Allocation結果に応じた`PaidLeaveUsageAllocated`
   イベント(0〜複数件)を記録する
6. 勤怠の休暇ビュー(`attendance_day_leaves`)の申請状態を承認済みにし、対象日の勤怠を日次計算し直す
   (勤怠文脈のReactor。勤怠日が無ければ`source=leave`で作る。論点3・5)

手順3〜6は各文脈のReactorが同じ連鎖の中で行い、利用者の承認操作1回で全体が1トランザクションになる。
承認者は対象日の勤怠を直接書き換えない(勤怠の`work_type`には有給区分を書かない。変更セット
`20261009-keep-leave-work-type-on-edit`の論点10)。休暇の有無・種類・取得単位は勤怠の休暇ビュー
から判定される。

手順4(Allocation)は「Allocationの算出アルゴリズム」節の通り。残数が不足していても承認は拒否せず、
充当できた分だけ充当する(論点17。有給は従来どおり部分充当)。承認済みの日次勤怠が既に締め(ロック)済みの場合は
承認できない(論点14。締め済みの日は申請・承認・差戻し・取消・再提出の全ての遷移を拒否する。修正申請
ワークフローを使う)。

承認・差戻し・取消は、汎用申請(workflow_requests)やバックオフィス処理と同様、独立した
ステータス系列(申請の状態`PaidLeaveRequestAggregate`: 申請中 → 承認済み / 差戻し / 取消。
差戻しからは再申請・取消)で管理する(承認とバックオフィス処理を分ける方針と同じ考え方)。差戻しは承認者のみ行える。
取消は申請者自身または管理者が行え、提出中(未承認)・承認済みのいずれの申請も取り消せる
(承認済みの取消の詳細は「実装上のポイント」参照)。
申請の状態の正は`paid_leave_request.*`イベント(休暇申請文脈)で、`paid_leave_requests`は
休暇申請文脈のProjectorが作る。残数側は申請テーブルを更新しない。

### 差戻し・再申請・取消(休暇の共通ルール、変更セット論点5・8)

有給・特別休暇・代休とも、差戻し・再申請・取消は次のとおり(有給の設計書`docs/09:315-316`の
「差戻しで未確定の消化記録を取り消し、再提出は新規の消化記録」に揃えた。特別・代休も同じ)。

- **差戻し**: ワークフローが差し戻されると、休暇の申請は申請前の状態(差戻し)に戻る。残数側は未確定の
  消化記録を取り消す(残高に含まれない)。勤怠の休暇ビューから外れ、勤怠は該当日を再計算する
  (休暇だけの日なら論点3・15の解除ルールで空の勤怠日を削除する)。差戻し中の申請は承認できず、申請者が
  「提出する」で再提出するか、取り消す。
- **再申請**: 差戻しから申請詳細の「提出する」で同じ内容のまま再提出する(`workflow_request.submitted`)。休暇の
  申請は申請中に戻り、残数側は新しい消化記録を作る。再提出では対象日・取得単位を変えられない。内容を変える場合は
  取消して新規に申請する。差し戻された休暇は申請前の状態のため、月次提出のガードの対象外(下書きと同じ)。
- **取消(申請中・承認済み)**: 申請者または管理者が取り消すと、休暇申請が取消になり、残数側が消化記録を取り消す
  (承認済みは充当を解除し残高を戻す)。勤怠は該当日を再計算する。申請中ならワークフローも取り消す。
  **承認済みのワークフローは取り消さない**(承認は取り消せない。設計原則13)。業務側の取消は休暇申請のイベントとして残る。
- **ワークフロー側からの取消**: 申請中・差戻し中のワークフローが取り消されると、休暇申請も取り消す。
- **却下**: 業務側(`subject_type`)を持つ申請は却下できない(変更セット論点16)。差し戻しを使う。
- **締め済みの日**: 締め(ロック)済みの日にかかる休暇は、申請・承認・差戻し・取消・再提出の全ての遷移で
  拒否する(変更セット論点14。管理者の例外は設けない。締め済みの月の休暇を変える場合は先に月次の確定取消を行う)。
- **同じ日の衝突**: 同じ日に休暇が重なる場合は勤怠文脈が判定する(変更セット論点7。全休を含む併存・同じ半休の重複・
  休暇の合計が所定労働時間を超える組合せを拒否する。午前有給+午後代休は通る)。

### 有給申請の引き継ぎ(本変更前の申請)

本変更前に申請された有給は、`stored_events`の作り直し(論点18。`leave:rebuild-event-store`、
`docs/27-release-runbook.md` 3.1)で、旧イベント列から新しい申請の状態機械のイベント(`paid_leave_request.*`と
口座の消化記録)へ変換する。移行イベント(`paid_leave_request.migrated`)は作らない。作り直し後は全ての申請が
通常の申請と同じ経路で処理され、承認・差戻し・取消・再提出は新しい集約で行う。cutover前(旧`paid_leave.*`)の
8件も、作り直しで消化記録が再現されるため、承認済みのものは通常どおり取り消せる(取消で残数を戻す)。

期間指定でまとめて申請した複数日分(同じ`request_group_id`を持つ行)は、承認者がそのうち
1件を承認する操作だけで、まだ提出中の残りの日もまとめて承認する(1日ごとに個別承認する
手間を減らすため)。差戻しはこの連鎖の対象外とし、日ごとに個別に行う(1日だけ差戻したい
場合があるため)。承認画面の詳細には、対象社員の直近1年間(申請中・承認済みの合計)の
有給取得日数を表示し、自動付与のルールに依らず承認者が目視で判断できるようにする
(この日数表示はシステムが上限を強制するものではなく、あくまで判断材料)。

## PaidLeaveAccountAggregateの内部モデル

`PaidLeaveAccountAggregate`はreplayのみで再構築される内部状態として、Grant一覧
(`grantId`ごとの`grantedOn`/`expiresOn`/`grantedDays`/`revoked`/`allocations`等)と
Usage一覧(`usageId`ごとの`usedOn`/`usedDays`/`confirmed`/`cancelled`/`allocations`等)を
保持する。GrantとUsageはそれぞれ独立したUUID(`grantId`/`usageId`)を持ち、
Allocation(`(usageId, grantId)`の組+充当日数)によって結び付けられる。Projection
(`paid_leave_usage_allocations`)を個々に参照・表示できるようにするための設計であり、
Aggregate自身はProjection/Eloquentへは一切アクセスしない。

### Grant時系列スタックの不変条件

1. **新規Grantは最新Grant以降の日付**: 非取消の現在最新Grantの`grantedOn`より前の日付は拒否する
   (過去日付の挿入は不可)。**同じ日は可**(同じ社員に同じ付与日の付与を複数記録できる。例: 2026-04-01に
   半年分10日と1年半分11日)。`changeGrantDate`も、ひとつ前のGrantと同じ日付にできる。すでに記録されている
   付与ID(`grantId`)の付与は拒否する(`GrantPaidLeave`に`grantId`を渡せる。一括付与は付与予定の行IDから決めた
   UUIDを渡し、再実行しても二重に付与されない)。
2. **変更・取消は最新Grantのみ**: `changeGrantAmount`/`changeGrantDate`/`changeGrantExpiry`/
   `revokeGrant`は、現在の最新の非取消Grantに対してのみ実行できる。過去のGrantを直接
   書き換えることはできない(古いGrantを変更したい場合は、間の全Grantを順に取り消して
   最新の状態にしてから操作する必要がある)。**最新**とは、付与日が最も遅く、その中で記録の順
   (`order`。集約内の連番で、記録した順に大きくなる)が最も大きいGrantをいう。同じ日の2件のうち先の
   付与を取り消す(変更する)には、後の付与を先に取り消す。
   - 同じ日の付与の直し方: 半年分の日数を直すには、後に記録した1年半分を取り消してから半年分を取り消し、
     手動で付与し直す(取消の順は後の付与が先)。このとき、付与予定の行は取り消した付与を指したまま
     付与済みで残る。
3. **減額の下限**: Grant減額(`changeGrantAmount`)はAllocation合計を下回れない。
4. **有効期限短縮の制約**: 有効期限短縮(`changeGrantExpiry`)はAllocation件数0のGrantのみ
   許可する。
5. **単一Grant内の整合性**: `grantedOn <= expiresOn`を常に維持する(日付・有効期限
   変更のいずれでもこの前後関係が崩れる変更は拒否する)。
6. **取消時のAllocation解除**: Grant取消(`revokeGrant`)時は当該Grantの全Allocationを
   解除する(`PaidLeaveUsageAllocationReleased`を発行。Usage自体は取消しない)。

### Allocationの算出アルゴリズム(`AllocationPlanner`)

Usage確定(`confirmUsage`)時、副作用のないDomain Service`AllocationPlanner`
(`App\Domain\PaidLeaveAccount\Support\AllocationPlanner`)が、以下の順でどのGrantへ
どれだけ充当するかを算出する。承認画面のPreview APIを追加する際も同じロジックを
共有する想定(Phase 5、現時点ではスコープ外)。

1. **Step 1(有効期限近い順)**: `usedOn`時点で有効(`grantedOn <= usedOn <= expiresOn`)
   かつ未取消のGrantのうち、空きがあるものを`expiresOn`→`grantedOn`→`order`の古い順
   (失効が近い順。同点は付与日、さらに同点は先に記録した付与)に充当する。
2. **Step 2(直近未来の付与日のGrantのみ)**: Step 1で不足が残る場合、`usedOn`より後の
   `grantedOn`を持つ未取消Grantのうち`grantedOn`が最も近い付与日のGrantだけを対象に、`order`の順に
   残り不足分を充当する(1件目の残りが足りなければ同じ日の次のGrantに続ける。別の付与日の
   Grantへは進まない)。
3. **Step 3(未充当のまま残す)**: それでも不足が残る場合、残りは充当せず未充当のまま
   Usageに残す。確定(承認)自体は失敗させない(残高不足でも承認するかどうかは人間が
   判断する。CLAUDE.md原則参照)。

### 未充当Usageの自動再Allocation

Grant増額・有効期限延長・Usage取消によるAllocation解除など(Grant追加は下記「付与時の未充当の充当」)、「空きが発生した契機」
の都度、Aggregateは未充当のUsage(確定済み・未取消・充当不足があるもの)を`usedOn`昇順
(同日は登録順)に再評価し、空きが生まれたGrantへ自動的に充当する
(`allocateUnallocatedUsages`)。既存のAllocationは組み替えない(常に「残りの不足分」だけを
追加充当する、安定ソートの再Allocation)。同一Command実行内・同一Aggregateバージョン内で
完結させることで、楽観的排他との整合を保つ。

### 付与時の未充当の充当(Reactor)

先に使用(承認・確定)してから後で付与された場合は、付与されたときに充当する(変更セット`20261009-keep-leave-work-type-on-edit`
論点19 決定10-1)。`paid_leave_account.grant_created`を購読するReactor(`UnallocatedUsageOnPaidLeaveGrantReactor`)が
`AllocateUnallocatedPaidLeaveUsages`(`viaReactor=true`)を発行し、集約の`allocateUnallocatedOnGrant`が、その利用者の確定済み・
未取消で充当が使用日数に足りない消化記録へ、**確定の古い順**に、**その付与の残りの範囲**で`usage_allocated`を記録する
(業務ルールは`GrantTimeAllocationPlanner`)。付与の有効期間と対象日の関係は問わない。既に充当済み・取消済みの消化記録は対象外で、
充当できるものが無ければ何も記録しない(冪等)。承認時の充当(`AllocationPlanner`)は変えない。付与の作成自体(`grant`)は
充当せず、充当はこのReactorが同じトランザクションで行う。

## Usageのライフサイクル

1. **designated(未確定)**: 申請時点で`DesignatePaidLeaveUsage`により`PaidLeaveUsageDesignated`
   イベントが記録され、`confirmed=false`のUsageが作られる。この時点ではAllocationは
   行われない。
2. **confirmed(確定)**: 承認時に`ConfirmPaidLeaveUsage`により`PaidLeaveUsageConfirmed`が
   記録され、続けてAllocationが実行される(`PaidLeaveUsageAllocated`、0〜複数件)。
3. **cancelled(取消)**: `CancelPaidLeaveUsage`により、既存のAllocationがあれば全て解除
   (`PaidLeaveUsageAllocationReleased`)したうえで`PaidLeaveUsageCancelled`が記録され、
   解放された枠は他の未充当Usageへ自動的に再Allocationされる(取消はUsage単位で行い、
   1件の承認済み申請が複数Grantにまたがって充当されていても取消は常にそのUsage全体に
   対して行う)。

全日(1.0)・半日(0.5)・時間単位(hourly、端数日数を`usedDays`としてそのまま反映)いずれも
同じUsage経路で扱う。時間単位年休(時間を単位とした独立の残高管理・別建て制度)の新規構築は
対象外だが、既存本番機能である「1日未満の時間数を指定した有給申請」自体は継続提供する
(spec.md「実装方針の変更」)。未知の`usageType`のみ`DomainRuleException`で例外化する。

## Workflow統合(申請フローとの連携)

「申請(進行状況)」の正は`workflow_requests`側、「有給固有の申請内容」の正は
`paid_leave_requests`側という分離を維持する(CLAUDE.md原則14)。

- 申請提出時: `RequestPaidLeaveHandler`は有給の申請集約(`PaidLeaveRequestAggregate`)に
  `paid_leave_request.requested`を記録し、`paid_leave_request.shared`で申請・承認文脈のReactor
  (`SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor`)へワークフローの提出を依頼する
  (提出をHandler内で直接行わない。変更セット論点4・仕様確定事項C)。残数側は
  `paid_leave_request.requested`を受けるReactorが`DesignatePaidLeaveUsage`を発行し、
  `PaidLeaveAccountAggregate`が申請IDで消化記録を作る。申請Handlerは勤怠日・残数・他の申請を読み書きしない。
- 承認時: 申請・承認文脈の承認が`workflow_request.approved`を発行し、休暇申請文脈が
  `paid_leave_request.approved`を記録する。残数側は`ConfirmPaidLeaveUsage`で消化記録を確定する。
  まとめ申請の兄弟(申請中のもの)は休暇申請文脈が承認し、申請・承認文脈が兄弟のワークフローを承認する
  (逆方向のReactor。差戻し中・取消済み・承認済みの兄弟は対象外)。
- 差戻し・再申請・取消: 上の「差戻し・再申請・取消(休暇の共通ルール)」のとおり。差戻しでは残数側が未確定の
  消化記録を取り消し、再提出(`workflow_request.submitted`)では新しい消化記録を作る。
- 承認済み申請の取消: `paid_leave_request.cancelled`を受けて残数側が`CancelPaidLeaveUsage`を発行し、
  Allocationを解除して残高を復元する。承認済みのワークフローは取り消さない(設計原則13)。
- 申請の状態の正は休暇申請文脈(`paid_leave_request.*`)。残数側は申請テーブルを更新しない。

`paid_leave_requests`のAPI応答形状(`/paid-leave/grants`・`/requests`・`/usages`・
`/history`・`/grant-rules`)はcutover前後で変更していない。応答フィールドは
`PaidLeaveUsageAllocationProjector`が旧Projectorの全列を引き継いで供給する。

## UC-P005: 有給消滅警告を出す

1. バッチが有効期限90日以内の有給を検索する
2. 残日数がある社員を抽出する
3. 社員と管理者へ Teams 通知する
4. 警告履歴を記録する

`paid-leave:warn-expiring` コマンドとしてcronから毎日実行する。対象は
「残日数(`paid_leave_grants.remaining_days`。Allocationからの非正規化キャッシュ)が
0より大きく、有効期限(`expires_on`)が今日から90日以内、かつまだ警告していない
(`paid_leave_grants.expiry_warned_at` が未設定)」付与。警告後は`expiry_warned_at`を
記録し、重複して通知しない。同じ社員・同じ付与日・同じ有効期限の付与(同じ日に複数記録した付与など)は
まとめて1件の警告にし、日数は合計して通知する(警告済みの印は束ねた全付与に付ける)。「社員と管理者へ通知する」はTeamsが通知専用の
単一チャンネル(webhook)である現在の実装上、対象者名を含む1件の通知として送る
(docs/03-architecture.md、CLAUDE.md「Teamsは通知専用」)。

警告を記録するイベントは`PaidLeaveAccountAggregate::raiseGrantWarning()`が発行する
`PaidLeaveGrantWarningRaised`(`grantId`/`warningType`/`message`。旧`PaidLeaveGrantAggregate::
raiseWarning`/`paid_leave.warning_raised`の置き換え。`RaisePaidLeaveGrantWarning` Command
経由)。残高等の不変条件には関与しない、警告済みフラグ記録専用のイベントである。

## UC-P006: 年5日取得義務を警告する

1. バッチが社員×付与日(基準日)の組のうち、付与日数の合計が10日以上の組を抽出する
2. 取得義務期間内の取得日数を確認する
3. 5日未満で期限が近い場合に警告する
4. 社員、承認者、管理部へ通知する

年10日以上の年次有給休暇が付与される労働者には、使用者による年5日の取得時季指定義務がある。
(出典: 都道府県労働局所在地一覧)

`paid-leave:warn-five-day-obligation` コマンドとしてcronから毎日実行する。取得義務期間は
付与日(基準日。`granted_on`)から1年とする。判定は**社員×付与日(基準日)の組ごと**に行う
(同じ日の付与が複数あっても警告は組に1件)。取消済みの付与は除き、組の付与日数の合計が10日以上の
組を対象にする(比例付与の7日+8日なども対象)。取得日数は、使用日(`usedOn`)が[基準日, 基準日+1年)にある
消化(`paid_leave_usages`のうち確定済み・未取消・時間単位以外の`used_days`)の合計とし、どの付与に充当
したか(複数の付与にまたがる・繰越分に充当された)は問わない。期限まで60日以内かつ取得日数が
5日未満の組に警告する。警告後は組の全付与の`paid_leave_grants.five_day_obligation_warned_at`を
記録し、重複通知しない。「承認者」は有給申請ごとに都度指定され固定の対応者を持たないため
(CLAUDE.md「承認者は都度指定」)、通知は社員本人と管理部宛の1件として送る。
`PaidLeaveGrantWarningRaised`イベント(`warningType: five_day_obligation`)を記録する。

このバッチ(`WarnFiveDayObligation`)の取得日数を有給口座の充当(Allocation)ベースで数える本格統合は
今回の再設計のスコープ外とし、集計元は上記の`paid_leave_usages`のままとする(spec.md「対象外」)。

## UC-P007: 有給履歴を確認する

1. 社員本人が自分の有給履歴を確認する、または管理者・人事担当者が対象社員を選んで
   有給履歴を確認する
2. 付与・申請・承認・差戻し・取消・消化のイベントを日時の新しい順に一覧表示する

`paid_leave_grants`/`paid_leave_requests`/`paid_leave_usage_allocations`/
`paid_leave_balances` の現在の残高・ステータス一覧(UC-P001〜UC-P004の画面)とは別に、
`stored_events`(EventStore)を正の記録として直接検索し、対象社員に関する一連のイベントを
時系列で表示する。`stored_events`の`aggregate_uuid`は`PaidLeaveAccountAggregate`の
AggregateId(`streamIdFor(userId)`。社員の`user_id`から派生する)であるため、旧ドメインのように個々のGrant/
Request idで絞り込む必要はなく、`LeaveHistoryQuery`の`additionalAggregateIds`に有給口座の集約IDを渡して
一連のイベント(Grant系・Usage系を含む)を取得できる(特別休暇・代休と同じ読み方)。応答の`aggregate_id`・`version`は
口座のストリームの値になる(社員の集約の値ではない)。

自分の履歴は誰でも閲覧できる。他の社員の履歴は`paid_leave.read` Permissionを持ち、対象Userを
含むScopeが有効な場合だけ閲覧できる(`GET /paid-leave/grants/user/{userId}`等)。

## UC-P008: 有給付与を取り消す

1. 人事担当者・管理者(`leave.manage` Permission)が、発行済みの有給付与(`paid_leave_grants`)
   の中から取り消す対象を選ぶ
2. 取消理由を入力する(任意)
3. `revokeGrant`の不変条件により、現在最新の非取消Grantでなければ拒否される
4. `RevokePaidLeaveGrant` Commandにより、当該Grantの全Allocationを解除
   (`PaidLeaveUsageAllocationReleased`)したうえで`PaidLeaveGrantRevoked`イベントを記録し、
   `paid_leave_grants.status`を`revoked`に、`revoked_at`/`revoked_by_user_id`/
   `revoke_reason`を設定する。`paid_leave_grants.remaining_days`を0にする

画面(有給付与ポリシー画面)では最新の非取消付与のみ取消ボタンを押せる。消化済みの付与を取り消す場合は確認文で付け替えを案内する。

`POST /paid-leave/grants/{grant}/revoke`(`RevokePaidLeaveGrant`
Command/`RevokePaidLeaveGrantHandler`)。旧ドメインでは「消化済み(`used_days > 0`)の
Grantは取消不可」というガードだったが、新ドメインの不変条件は「取消は現在最新の非取消
Grantのみ」「取消時に全Allocationを解除して残高を復元」であり、Allocation済み(消化済み)
であっても最新Grantであれば取消可能・Allocationは自動的に解除・再配分される(cutoverに
伴う意図的な仕様変更。「実装上のポイント」参照)。同じ構造の取消を特別休暇
(`special_leave_grants`、`RevokeSpecialLeaveGrant`)にも実装する(こちらは旧ドメインの
まま、対象外)。

## UC-P009: 管理者が社員の有給申請を取り消す・消化明細を確認する

1. 人事担当者・管理者(`leave.manage` Permission)が対象社員の有給消化明細
   (`paid_leave_usage_allocations`/`paid_leave_usages`)を一覧で確認する
   (`GET /paid-leave/usages/user/{userId}`。`used_on`の新しい順。関連する申請の現在の
   `status`(`request_status`)も併せて返し、`approved`以外(既に取消・返却済み)の明細は
   取消不可であることを画面側で判別できるようにする)
2. 承認済みの申請を選び、取消理由等を確認したうえで管理者が直接取り消す
   (`POST /paid-leave/requests/{id}/admin-cancel`)

消化明細(`paid_leave_usage_allocations`)は`PaidLeaveUsageAllocated`/
`PaidLeaveUsageAllocationReleased`イベントから作成・更新される派生データであり、
消化明細1件だけを独立して取消・巻き戻すという操作は存在しない(1件の承認済み申請が
複数の付与にまたがって消化することがあり、取消は常に申請単位(Usage単位)で行う。
ドメインとしての取消は`CancelPaidLeaveRequestHandler`が`CancelPaidLeaveUsage`を発行し、
対象Usageの全Allocationを巻き戻す形で実装済み)。

UC-P003で説明した申請者本人による取消(`POST /paid-leave/requests/{id}/cancel`)は
`cancelledByUserId === 対象申請のuser_id`のみ許可する自己申請限定の経路のままとし、
管理者向けにはそれとは別の`admin-cancel`エンドポイントを追加する。内部的には同じ
`CancelPaidLeaveRequest` Command/`CancelPaidLeaveRequestHandler`を使うが、
`isAdminAction: true`を渡すことで本人一致チェックをバイパスする(`cancelledByUserId`には
取消操作を行った管理者自身のIDを渡し、監査上「誰が取り消したか」を正しく記録する)。
承認済み申請のみ取消可能・締め済み月は取消不可等、既存の業務ルールはすべてそのまま適用される。
同じ構造を特別休暇(`POST /special-leave/requests/{id}/admin-cancel`、
`GET /special-leave/usages/user/{userId}`)・代休(`POST /compensatory-leave/requests/{id}/admin-cancel`、
`GET /compensatory-leave/usages/user/{userId}`)にも実装する。

## UC-P010: 既存システムからの有給データを移行する(cutover。作り直しに置き換え済み)

2026年9月のcutover時点の有給の残高は、当時の移行イベント(`paid_leave_account.migrated`)で口座に取り込んだ。
この移行は、変更セット論点18で`stored_events`の作り直し(`leave:rebuild-event-store`。`docs/27-release-runbook.md` 3.1)に
置き換えた。

- 移行イベント・移行コマンド(`MigratePaidLeaveAccount`・`paid-leave:migrate-accounts`)・`POST /paid-leave/migrate`・
  `PaidLeaveAccountAggregate::migrateGrants()`は削除した。新しい移行は行わない。
- 作り直しでは、cutover時点の残数を旧付与と突き合わせる。旧で再現できる付与(残数が一致する)は、旧付与の
  `grant_created`をそのまま使う。再現できない残高(システム外から持ち込んだ残高)は、cutoverの付与ID・残数・有効期限で
  `paid_leave_account.grant_created`を作り、`source`を`carried_over`とする。cutoverの情報(モード・備考)は付与イベントの
  `meta`に残る。付与の移行監査列(`original_granted_days`・`cutover_metadata`)は持たない。
- 付与の残数の上限は、切替時点の実際の残日数(`remainingDaysAtCutover`)とする。元の付与日数が不明でも架空の上限は作らない。
- 移行前の個別の消化履歴は、旧イベントの消化記録から作り直しで再現する。再現できない並びは作り直しが失敗するため、
  試し実行で確認する(`docs/27-release-runbook.md` 3.1)。

移行後は通常のGrant操作(`GrantPaidLeave`等)・Usage操作がそのまま利用できる。

## UC-P011: 付与予定(Schedule)をローリング生成する

1. `paid-leave:roll-schedules`コマンドがcronから毎日実行される
2. `hire_date`設定済み・`paid_leave_auto_grant_enabled=true`の在籍社員を対象に抽出する
3. 社員ごとに`RecalculateFutureSchedule` Commandを発行する。候補は今日から1年先まで
   (`ScheduleCandidateGenerator`。通常付与/比例付与/シフト勤務の各区分。判定不能な場合は
   `NeedsReview`)、`fromDate`は明日、`overrideManualEdits=false`。候補が無い社員にも発行する
4. 判定(出勤率Assessment、UC-P012)はこのコマンドでは実行しない(作成のみ)。判定は
   `paid-leave:assess-schedules`(毎月1日の自動判定と手動の実行)が行う
5. 「今日」は`system_settings.default_timezone`の日付(`App\Support\BusinessDate`)。再計算のReactor・
   `ReapplyPaidLeaveSchedulePolicyJob`・勤務形態の現在の割当の判定でも同じ

旧`paid-leave:grant-scheduled`(UC-P002、`GrantScheduledPaidLeaveHandler`による日次全件
評価)を置換するバッチで、`App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`
(AggregateId = `PaidLeaveScheduleAggregate::streamIdFor(userId)`。社員IDから派生する別のストリーム)が状態を管理する(docs/changesets/20260906-paid-leave-schedule-assessment/spec.md)。
1年先まで先出しでScheduleを生成しておくことで、UC-P013の一覧画面から確定前の付与予定を
事前に確認できるようにする。1社員の失敗が他の社員の処理を止めないよう、行単位で成否を
収集して継続する(部分失敗許容)。既存の`paid_leave_grant_rules`ベースのバッチ判定
(UC-P001/UC-P002)とは独立した別ドメインであり、両者は共存しない(cutover的な置換)。

入社日・利用開始日・自動付与・勤務形態の変更(勤務形態の割当の登録・削除、勤務形態の区分判定に関わる列の変更)では、
`RecalculateFutureSchedule`による未来分の再計算が走る。割当の登録・削除は、削除の対象月が当月より後でも
再計算を行う(結果が変わらなければイベントは増えない)。毎日の`roll-schedules`は、先の月に登録した割当が
有効になった日(月替わり)の再計算のきっかけになる。
再計算は、候補を作る期間の始まりより前の付与予定日の行には、状態に関わらず触れない(取消も置換もしない)。
当日・過去の行は`roll-schedules`(`fromDate`=明日)では変わらない。
また、付与済みの行と同じ付与予定日・名目の継続勤務月数の予定は作らない(取消済みの行と同じ組は作り直せる)。

付与予定の行は、付与予定日と名目の継続勤務月数(`nominalServiceMonths`。何回目の付与か。例: 初回6、次は18)の組で
見分ける。同じ日に名目の月数の違う行を持てる(年度はじめの前倒しで、半年分と1年半分を同じ日に付与する場合など)。
再計算での「同じ行」の判定、付与済みの行の消費、行の作成の冪等は、この組で行う。名目の月数が無い古い行・候補は、
付与予定日だけで見分ける。一覧の並びは付与予定日、同じ日は名目の月数の順で、画面の区分の列に「継続◯か月分」を出す。

**毎日の再計算で取り消されるもの**(再計算の結果、候補に無い未確定の行は取り消される):
- 付与ルールを無効にした・対象のルールが無くなった社員の、明日以降の未確定の行(自動付与は有効のまま)。
- `paid-leave:schedule:rebuild --to`で作った、1年より先の未確定の行(候補は1年先までのため)。

**毎日の再計算で守られるもの**: 個別修正済みの行(`overrideManualEdits=false`)、付与済み(`Granted`)の行、
取消済みの行、当日以前の行。

## UC-P011b: 法定付与ポリシー・付与ルール変更時に付与予定を再作成する

1. 人事担当者・管理者が付与ルール(`POST`/`PUT /paid-leave/grant-rules`)、または法定
   通常付与表・比例付与表の新バージョン(`POST /paid-leave/grant-policies`・
   `POST /paid-leave/proportional-grant-policies`)を作成・編集する
2. リクエスト成功後、`App\Jobs\ReapplyPaidLeaveSchedulePolicyJob`がDBキュー経由で
   非同期に発行される(管理画面の保存操作はJob完了を待たない)
3. Jobは`paid-leave:roll-schedules`と同じ対象社員条件(`employment_status=active`・
   `hire_date`設定済み・`paid_leave_auto_grant_enabled=true`)で全社員を走査し、
   `ScheduleCandidateGenerator`で最新のルール・法定Policyに基づく候補を算出し直した上で
   `RecalculateFutureSchedule`(`overrideManualEdits: true`)を発行する
4. `Granted`/`Cancelled`の確定済みエントリは対象外(常に不変)。それ以外の未確定エントリは、
   UC-P014で個別修正(`ManuallyEditScheduleEntry`)済みであっても保護されず、新しい内容へ
   再作成される(内容が変わらない社員は冪等に何もしない)

法定付与ポリシー・付与ルールの内容そのものが変わった場合、確定していない付与予定は
「あくまで見込み」であり、ユーザー確認(UC-P014のOverride含む)の有無に関わらず最新の
ルールに追従させる、という運用判断による
(docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md)。

同じJobは、既定の会社カレンダーの年度開始日(有給の一斉付与日)が変わったときにも発行される
(`ReapplyScheduleOnFiscalYearStartChangedReactor`。名前だけの変更では発行しない。UC-C009)。
デプロイ後など、手動で全社員を作り直すときは、運用コマンド`php artisan paid-leave:schedule:reapply-policy`
(運用コマンド画面からも実行できる)を使う。Jobと同じ処理を同期で行い、作り直した社員の数と、付与予定日・日数が変わった
行の数を出力する(`docs/27-release-runbook.md` 3.3)。

## UC-P012: 出勤率Assessmentを実行する

自動判定(毎月1日)と、画面からの手動の実行、1行ずつの再判定の3つの経路がある。判定の内容(期間・みなし出勤・閾値)は
どの経路でも`PaidLeaveScheduleAssessmentPlanner`が決める(UC-P002の「判定期間とみなし出勤」参照。社労士の確認が前提)。

1. `paid-leave:assess-schedules`コマンドが、状態が`Scheduled`(予定)で付与予定日が今日以前(`system_settings.default_timezone`
   の日付)の行を、社員ごとにPlannerで判定し、`RunAttendanceRateAssessment` Commandを発行する(1社員の判定は1トランザクション)。
   1社員の失敗で全体は止めず、失敗した社員と理由を出力に並べる。1件でも失敗があれば終了コード1。
2. 実行の経路は、キュー(`queue:work`は既定60秒で打ち切られる)を使わず、スケジューラー(`routes/console.php`)から直接動かす。
   - 定期: `paid-leave:assess-schedules --trigger=schedule`を**毎月1日03:00**(既定のタイムゾーン)に実行する。
     cron式は`ScheduleAssessmentRunService::SCHEDULE_CRON`で、画面に出す次回実行日時の計算と共有する。
   - 手動: 付与予定画面の「今すぐ判定する」(確認ダイアログあり)が`POST /paid-leave/schedule-assessment-runs`で記録
     (`status=queued`、`trigger=manual`)を作るだけにし(202。実行中・待機中があれば409)、
     `paid-leave:assess-schedules --pending`(毎分)が待っている記録を1件拾って実行する。
   - 運用コマンド画面からは実行させない(`#[AdminExecutable]`を付けない。経路を1つにするため)。
3. 実行の記録は`admin_command_runs`(運用の記録。イベントにしない)。コマンド自身が`running`→`succeeded`/`failed`に更新し、
   集計`summary`(`targets`/`assessed`/`eligible`/`not_eligible`/`needs_review`/`failed`)を書く。一部失敗は
   `status=failed`、`error_message`は「N件の判定に失敗しました」。定期の実行は`requested_by_user_id`がnull。
4. 二重実行の防止: 記録の作成・取り出しは`Cache::lock('paid-leave-assessment-run')`の中で行い、`queued`/`running`の記録があれば
   手動の受付は409、定期の実行は記録を作らずに終える。`running`のまま`started_at`から2時間を超えた記録、`queued`のまま
   30分を超えた記録は、状態の取得・次の実行・受付のときに`failed`(「応答がないため失敗とみなしました」)にする。
5. 実行状況: `GET /paid-leave/schedule-assessment-runs/status`(`leave.manage`)が
   `{ next_run_at, latest_run: { id, status, trigger, requested_by_name, started_at, finished_at, summary, error_message } | null }`
   を返す。付与予定画面の上部の「自動判定」カードに、次回の予定・実行中・前回の結果を出す。
6. **判定が付与予定日より遅れることがある**: 判定は毎月1日(または手動)なので、付与予定日が月の途中の行(年度開始が1日以外、
   入社日基準のルール)は、判定と付与が最大で約1か月遅れる。付与日は付与予定日にさかのぼって記録されるが、判定と付与までの間、
   その日数は使えない。
7. 判定結果は`PaidLeaveScheduleAssessmentRecorded`イベントとして記録され、`paid_leave_schedule_assessments`
   (Assessmentの版履歴。1つの付与予定に複数行)に`period_start`/`period_end`/`denominator_days`/`attendance_days`/
   `excluded_days`/`deemed_attendance_days`/`attendance_rate`/`policy_version`/`automatic_result`/`final_result`/
   `override_reason`として保存し、`paid_leave_schedule_entries.latest_assessment_id`が最新の版を指す
8. 既にUC-P014のOverrideが行われている場合でもこのイベント自体は発行されるが、
   エントリの導出ステータスはOverride結果(`paid_leave_schedule_assessments.final_result`)を
   優先し、自動判定に上書きされない

`POST /paid-leave/schedule-entries/{entry}/reassess`(「再判定」ボタン)では、1行を同じPlannerの条件で手動再実行できる
(まとめて付与する2件の行を指定したときは、組の2行を判定して両方記録する)。勤怠データが事後修正された場合に手動で再判定する用途を想定する。

## UC-P013: 付与予定一覧を確認する

1. 人事担当者・管理者が「有給休暇」画面の「付与予定を確認」ボタンから`/admin/paid-leave/schedule`
   画面で付与予定一覧を確認する(`GET /paid-leave/schedule-entries`)
2. `filter`で状態を絞り込む。値は`all`(すべて)/`eligible`(付与対象)/`not_eligible`(対象外)/
   `needs_review`(要確認)/`changed`(変更あり)/`overdue`(付与予定日超過・未付与)。
   - `changed`(変更あり)は`is_manually_overridden=true`の行(UC-P014の区分・候補日数の個別修正を
     経た行)を示し、状態は問わない。
   - `overdue`は`scheduled_on`が今日以前で、状態が`Scheduled`/`AssessmentPending`/`Eligible`/
     `NeedsReview`の行。`NotEligible`は「付与しない」という判定結果のため含めない。
3. 社員名の部分一致(`user_name`)、付与予定日(`scheduled_on`)の期間(`scheduled_on_from`/
   `scheduled_on_to`。両端を含む)で絞り込む。`filter`とはANDで組み合わせる。絞り込み条件は
   URLクエリに保持する。
4. 付与予定日超過(`overdue`)の件数を、「有給休暇」画面の「付与予定を確認」ボタン(件数の
   バッジ)と、付与予定画面の上部の警告に出す。件数専用のAPIは設けず、`filter=overdue`の件数を使う。
5. 一覧の行から詳細パネルを開き、`GET /paid-leave/schedule-entries/{entry}`でAssessment内訳
   (判定対象期間・分母/分子・出勤率・判定根拠の`policy_version`)を確認する

一覧の列は社員/付与予定日/区分(`normal`(通常付与)/`proportional`(比例付与)/`shift`(シフト勤務)/
`needs_review`(要確認))/付与候補日数/出勤率/判定状態(`status`)。判定不能な場合は区分・判定の
双方が要確認(`needs_review`/`NeedsReview`)になりうる。

## UC-P014: 判定結果をOverrideする

1. 人事担当者・管理者が、`NeedsReview`または自動判定に納得できないエントリを選ぶ
2. 最終判定(`Eligible`/`NotEligible`)と理由(必須)を入力する
3. `POST /paid-leave/schedule-entries/{entry}/override`により`OverrideScheduleAssessment`
   Commandが発行され、`PaidLeaveScheduleAssessmentOverridden`イベントが記録される
4. 以降、当該エントリの導出ステータスはUC-P012の自動判定結果より本Override結果
   (`paid_leave_schedule_assessments.final_result`)を優先する

区分・候補付与日数の個別修正は別の操作(下記)で、判定結果のOverrideを兼ねない。

### 区分・候補日数の個別修正(PATCH)

1. 人事担当者・管理者が付与予定の詳細パネルの「4. 区分・日数を修正」で、区分(`category`)または
   付与候補日数(`candidate_grant_days`)を変更し、修正理由(必須)を入力して保存する。
   送るのは現在値から変わった項目だけで、変わった項目が無いときは保存できない
2. `PATCH /paid-leave/schedule-entries/{entry}`により`ManuallyEditScheduleEntry` Commandが発行され、
   `PaidLeaveScheduleEntryManuallyEdited`イベントが記録される。Projectionの`category`/
   `candidate_grant_days`/`is_manually_overridden`(=true)/`manual_override_reason`等が更新される
3. 入力項目は次のとおり。
   - `category`: `normal`/`proportional`/`shift`のいずれか
   - `candidate_grant_days`: 0.5以上の0.5刻み
   - `reason`: 必須(1000文字以内)
   - `category`と`candidate_grant_days`の少なくとも一方が必須
4. 修正できるのは区分・候補日数のみ。付与予定日(`scheduled_on`)は修正対象外とする。付与予定日を
   動かすと、元の日付の予定が翌日以降の再計算で作り直されて二重付与になるおそれがあり、
   一括付与の「新規付与は最新の付与と同じ日か後」(過去日付は拒否する)という不変条件とも食い違うため。
5. 確定済み(`Granted`)・取消済み(`Cancelled`)の行は修正できない(422)。
6. 修正した行は以後の自動再計算(`RecalculateFutureSchedule`)から保護される。ただし付与ルール・
   法定付与ポリシーの再適用(UC-P011b、`overrideManualEdits=true`)では、未確定の行はこの修正も含めて
   新しい内容で作り直される(確定済みの行は対象外)。
7. 区分を`needs_review`から修正しても判定状態は自動では変わらない。必要に応じて再判定(UC-P012)または
   判定結果のOverride(UC-P014の本体)を行う。

## UC-P015: 付与予定を一括付与する

1. 人事担当者・管理者が一覧を`eligible`フィルタで絞り込み、対象エントリを複数選択する
   (チェックボックスは`Eligible`の行だけに付く)
2. 一括付与の前に確認ダイアログ(`ConfirmActionDialog`)を出す。本文には件数・合計付与日数・
   付与予定日の範囲(最早〜最遅)と「付与後は付与予定画面から取り消せません。取消は使用状況から
   付与ごとに行います。」を示す。選んだ行に付与予定日前の行(`scheduled_on`が今日より後で、まだ到来していない行)が
   含まれる場合は、その件数と「出勤率の判定期間にまだ経過していない日が含まれています」旨の警告を
   加える。付与予定日前の行を含む場合も付与は可能である(警告のみ)
3. 確定すると`POST /paid-leave/schedule-entries/bulk-grant`(`schedule_entry_ids`)を実行する
4. 社員ごとに`ApplyScheduledGrants` Commandが発行され、エントリごとに`PaidLeaveAccountAggregate::grant()`
   (UC-P002と共通のGrant発行経路)が成功した後、`PaidLeaveScheduleEntryGranted`イベントで
   当該エントリのステータスを`Granted`へ遷移し、`grant_id`(付与された`paid_leave_grants.id`)を設定する
5. 対象社員の`paid_leave_balances`(UC-P007と同じ経路)に反映される

一括付与は、渡された行を(付与予定日, 名目の継続勤務月数)の順に並べ替えてから付与する(同じ社員・同じ日の行は
名目の月数の小さい順に付与される)。有給口座の付与IDは、付与予定の行のIDから決めたUUIDv5
(名前は`paid_leave_schedule_grant:{行ID}`。名前空間は`UserManagementStreamId`と同じ方式)にする。
同じ行をもう一度付与しようとしても、有給口座が同じ付与IDを拒否するので二重には付与されない。

まとめて付与する2件(組。UC-P002の「まとめて付与する2件(組)の規則」)は、片方だけを選べない。APIは、組の片方だけが
指定されたら422(「まとめて付与する2件は、同時に選んでください。」)を返し、何も付与しない。付与は1回の`ApplyScheduledGrants`で
2件を続けて行い、有給口座の付与は2件のイベントを1回のpersist(`GrantPaidLeaves`)で記録する。片方の付与に失敗したら、
もう片方も付与しない(2行とも失敗として返す)。

`Eligible`以外のエントリが選択に混入していた場合や、1件のAggregate例外が他のエントリの
処理を止めないよう、エントリ単位で成功/失敗を収集し、レスポンスに部分失敗を含めて返す
(黙って握りつぶさない。行単位で成否を集計して返す「行単位継続」方針)。レスポンスは
`success_count`/`failure_count`と、行ごとの`schedule_entry_id`/`success`/`message`/`grant_id`。
画面では「N件成功 / M件失敗」と失敗理由を表示する。

## 実装上のポイント

- 付与ルール (`paid_leave_grant_rules` / `paid_leave_grant_rule_steps`) はマスタ化し、
  継続勤務期間ごとの付与日数・出勤率条件をハードコードしない。
- 消化(Allocation)は「Allocationの算出アルゴリズム」節の通り、有効期限が近い付与分から
  優先的に消し込み、それでも不足する場合に限り直近未来Grant1件だけを追加で充当する。
  `paid_leave_usage_allocations`が充当関係のSource of Truthで、`paid_leave_grants`の
  `allocated_days`/`remaining_days`・`paid_leave_balances`はそこからの非正規化キャッシュに
  過ぎない。
- UC-P001〜UC-P006はすべて実装済み。UC-P002(自動付与)・UC-P005(消滅警告)・
  UC-P006(年5日警告)は`paid-leave:grant-scheduled` / `paid-leave:warn-expiring` /
  `paid-leave:warn-five-day-obligation` の3コマンドとしてcronから毎日実行する
  (routes/console.php)。MVP自体は UC-P001(付与ルールマスタ)・付与の手動実行・
  UC-P003(有給申請)・UC-P004(有給承認・消化)までを最小範囲としていたが
  ([21-mvp-scope.md](./21-mvp-scope.md) 参照)、以降のフェーズでバッチ3種、さらに
  PaidLeaveAccountAggregateへの全面移行(本章)を実装した。
- UC-P003/UC-P004は汎用申請(workflow_requests)とは別の独立したCommand/Event
  (`paid_leave_requests` を有給固有の申請内容の正データとする専用ドメイン)として実装する。
  承認時に`attendance_days`への反映とAllocation実行という汎用申請の承認(バックオフィス
  タスク自動生成のみ)とは異なる業務ルールを持つため。
- UC-P002の継続勤務期間・出勤率判定には `users.hire_date`(入社日)を使う。MS365には
  対応する属性がないため同期対象外で、`user_profile.update` Permissionを持つ担当者が個別に
  設定する必要がある(未設定の社員は自動付与の対象外になる)。
- UC-P005/UC-P006の警告は同一の `PaidLeaveGrantWarningRaised` イベントを共有し、
  `warningType` (`expiry` / `five_day_obligation`) で区別する。重複通知を防ぐため、
  `paid_leave_grants.expiry_warned_at` / `five_day_obligation_warned_at` にそれぞれ
  一度警告した事実を記録し、以降の実行では対象から除外する(一度きりの警告。期限が過ぎても
  再警告はしない)。
- 期間指定の複数日申請・1回の承認でのまとめ承認(`request_group_id`)は特別休暇
  (`special_leave_requests`)・代休(`compensatory_leave_requests`、後述)にも同じ仕組みで
  実装済み。3ドメインとも、同じ`request_group_id`を持つ行のうち1件が承認されると、
  まだ提出中の他の行もまとめて承認される。差戻しは対象外で、日ごとに個別に行う。
- **承認済み申請の取消**: 有給・特別休暇・代休のいずれも、承認済みの申請を申請者本人が
  即座に取り消せる(承認者の再承認は不要)。有給は`CancelPaidLeaveUsage`によりAllocationを
  解除して残高を復元する(特別休暇・代休は口座集約の取消イベント`*_account.usage_cancelled`で
  残数を戻す)。対象日の勤怠は`work_type`を書き換えない(休暇値を持たない。変更セット論点10)。
  勤怠は休暇ビューから休暇が外れた日を再計算し、休暇だけで作られた空の勤怠日(`source=leave`)は
  削除して休暇の前の状態に戻す(論点15)。対象日を含む月次勤怠が既に提出・承認・締め済みの場合は
  取消できない(`AttendanceEditGuard::assertMutable`が他の編集操作と同じ基準で拒否する。
  修正が必要な場合は修正申請ワークフローを使う)。期間指定でまとめて承認された申請の取消は
  1件ずつ個別に行う(承認のようなグループ単位のカスケードはない)。
- 既知の制約: `attendance_daily_calculations`(日次集計)は現時点で休暇の種類を区別せず
  `actual_start_at`/`actual_end_at` のみから計算する。全休日は労働時間が0分として集計される
  (欠勤ではなく有給消化であることは勤怠の休暇ビュー(`attendance_day_leaves`)で判別できる。
  `work_type`は作業内容のみで休暇を表さない。給与計算上の
  「有給分の賃金換算」は本実装のスコープ外。給与計算ソフト側で休暇ビューを見て加算する、
  または後続フェーズで日次集計に有給分を組み込む対応が必要)。
- 承認画面Allocation Preview API・Grant管理UI・社員別有給画面は、今回の再設計のスコープ外
  (spec.md「対象外」参照)。ドメインロジック(Aggregate・Allocation算出)自体は完成済みの
  ため、画面実装のみが必要になった時点で別途変更セットを起こす想定。
  `PaidLeaveScheduleAggregate`/Assessment・法定通常/比例付与判定・シフト勤務判定・
  ローリングSchedule生成バッチ(1年先まで)は、別の変更セット
  (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md)で実装済み
  (UC-P011〜UC-P015参照)。

## 特別休暇の申請と残数(口座集約、変更セット`20261009-keep-leave-work-type-on-edit`)

特別休暇は、申請の状態と残数・使用を別の文脈で持つ(設計原則15)。

- 申請の状態(申請中・差戻し・承認済み・取消)は休暇申請文脈の`special_leave.*`が持つ。
  申請の提出は申請・承認文脈のReactor(`SubmitWorkflowRequestOnSpecialLeaveRequestSharedReactor`)が行う。
- 付与の残数と消化記録(作成・確定・取消)は利用者単位の口座集約`SpecialLeaveAccountAggregate`
  (`special_leave_account.*`)が持つ。申請時に消化記録を作り、承認時に付与へ充当し、差戻し・取消で消化記録を
  取り消す(Reactor: `SpecialLeaveUsageOnSpecialLeaveRequestReactor`)。
- 付与は`special_leave_account.grant_registered`で登録し、取消は`special_leave_account.grant_revoked`で記録する。
  本人向けの付与一覧(`GET /special-leave/grants/mine`)は取り消した付与(status=revoked)と残日数0の付与を表示しない。管理者向けの一覧は取消済みを「取消済み」として含む。
  自分の申請一覧(`GET /special-leave/requests/mine`)も有給と同じく、対象日の降順・20件ずつのページング(`page`・`target_date`)。
  既存の付与・消化は、`stored_events`の作り直し(`leave:rebuild-event-store`)で口座のイベントに変換する(`docs/27-release-runbook.md` 3.1)。
- 承認時に残数が不足していても承認は拒否せず、充当できた分だけ充当し、充当できなかった分は
  `unallocatedDays`として確定イベントに記録する(論点17)。
- 差戻し・再申請・取消・締め・同じ日の衝突の扱いは「差戻し・再申請・取消(休暇の共通ルール)」のとおり。
- 休暇の有無は勤怠の休暇ビュー(`attendance_day_leaves`)で判定し、勤怠日の`work_type`は使わない。
- 出勤率(自動付与の判定)は、特別休暇の出勤率ビュー(`special_leave_attendance_rate_days`・
  `special_leave_attendance_rate_leaves`)を読む。全休の特別休暇は出勤扱い、半休・時間休は分母に応じて数える
  (現行の判定結果を維持する。論点9)。

## 代休(`App\Domain\CompensatoryLeave`)

有給・特別休暇とは異なり、代休の付与(Grant)は申請ではなく休日出勤の勤怠実績から自動導出される。
所定休日・法定休日に実働がある日次勤怠が保存される都度、勤怠の`attendance_day.calculated`・
`attendance_day.daily_calculation_adjusted`・`attendance_day.deleted`を購読する同期Reactor
(`SyncCompensatoryLeaveAccountGrantOnAttendanceDayCalculatedReactor`)が、代休の口座集約
(`CompensatoryLeaveAccountAggregate`)へ`compensatory_leave_account.grant_synced`を記録する(変更セット
仕様確定事項D・原則15)。同期は計算イベントの利用者・勤務日・日区分・実労働分で判定し、勤怠日のテーブルは読まない。
休日出勤でなくなった・実績が削除された場合、draft状態のGrantのみ取り消す
(確定済みGrantは触らない。整合性は月次確認画面の警告`compensatory_leave_warnings`で扱う)。
代休は今回の有給ドメイン再設計の対象外で、引き続き従来通りの独立ドメインとして実装されている。
付与の残数と消化記録(申請→作成、承認→充当、差戻し・取消→解除)は代休の口座集約が持つ。
申請の状態は`compensatory_leave.*`(休暇申請文脈)が持つ。
本人向けの付与一覧(`GET /compensatory-leave/grants/mine`)は取り消した付与(status=cancelled)と残りの無い付与(残日数・残分数とも0)を表示しない。仮(draft)・確定(confirmed)の付与は残りがあれば表示する(時間単位は日数0・分数ありでも表示)。管理者向けの一覧は取消済みを含む。
自分の申請一覧(`GET /compensatory-leave/requests/mine`)も有給と同じく、対象日の降順・20件ずつのページング(`page`・`target_date`)。

月次勤怠の提出(`AttendanceMonthSubmitted`)を受けて、対象月のdraft Grantを一括で
`confirmed`に確定する(口座集約の`compensatory_leave_account.grant_confirmed`。月の判定は
文字列の前方一致ではなく日付範囲で行う。`system_settings.compensatory_leave_valid_days`が設定されていれば
提出時点からのN日後を`expires_on`とし、未設定なら無期限)。確定後のGrantのみが消化申請
(`compensatory_leave_requests`、特別休暇と同じ申請・承認・消化のフロー)の対象になる。
申請の差戻しでは未確定の消化記録を取り消し、再提出では新しい消化記録を作る(「差戻し・再申請・取消」参照)。
取得単位(全休/半休/時間単位)は`system_settings.compensatory_leave_unit`
(`daily`/`half_day`/`hourly`)で制限し、半休判定の閾値は
`compensatory_leave_half_day_threshold_minutes`で設定する。未使用の確定済みGrantは
取消申請でき(`compensatory_leave_grant_cancellations`)、`compensatory_leave_requires_approval`
の設定に応じて即時取消/承認要のいずれかになる。詳細は`docs/03-architecture.md`・
`app/Domain/CompensatoryLeave/`を参照。

### 代休の手動付与・管理者直接取消

休日出勤の勤怠実績からの自動導出(上記)とは別に、人事担当者・管理者(`leave.manage`
Permission)が管理者操作として代休を手動付与できる。付与理由の例: 自動導出漏れの事後対応、
制度移行時の付与調整など。任意の日数を自由入力させるのではなく、実際に休日出勤した対象日
(`work_date`)を指定させ、その日の`attendance_days`(実労働時間)から自動導出フローと
**同じ換算ルール**(`system_settings.compensatory_leave_unit`等、
`App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator`)で付与日数を
算出する(`POST /compensatory-leave/grants`、`GrantCompensatoryLeave`
Command/`GrantCompensatoryLeaveHandler`)。対象日に休日出勤の実績があるかは、代休の口座側の休日出勤ビュー
(`compensatory_holiday_work_days`。計算イベントから作る)で確認する。Handlerは勤怠日のテーブルを読まない。
対象日に休日出勤の実績が無い場合はエラーになる。

手動付与は承認不要のため、作成と同時に`status=confirmed`となり(月次提出による確定
ステップを経ない)、口座集約の`compensatory_leave_account.grant_manually_granted`で記録する。
付与には元になった勤怠日への紐付けを持たない(`compensatory_leave_grants.attendance_day_id`の
外部キーとユニーク制約は撤去済み)。

管理者は`POST /compensatory-leave/grants/{grant}/revoke`で代休Grantを直接取り消せる
(`source`が`attendance`/`manual`のどちらでも利用可能)。既存の社員起点の
取消申請→承認フロー(上記、`request-cancellation`/`grant-cancellations/{id}/approve`)とは
別の、承認を経ない管理者専用の即時取消経路であり、代休の口座集約の付与取消
(`compensatory_leave_account.grant_cancelled`)で行う(`used_days`が0より大きい場合は同様に取消不可)。
旧付与集約とそのCommand/Handlerは口座集約へ置き換えて削除した。旧イベントは作り直しで新しいイベントに変換し、削除した。

有給・特別休暇のUC-P007と同様、`GET /compensatory-leave/history/mine`(本人)・
`GET /compensatory-leave/history/user/{userId}`(`leave.manage` Permission)で
代休の付与・申請・承認・差戻し・取消のstored_eventsを時系列(新しい順)で確認できる
(`App\Domain\Leave\Support\LeaveHistoryQuery`共通実装。手動付与・自動導出のどちらの
Grantも区別なく含まれる)。
