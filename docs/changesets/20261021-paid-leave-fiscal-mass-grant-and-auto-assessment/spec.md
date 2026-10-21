# 有給の年度はじめ一斉付与を初期値にし、出勤率の自動判定を戻す

ステータス: 実装中

## 変更要望(原文)
> (自動判定を戻す変更セットと、PR#109の機能の突き合わせを)お願いします。加えて今の会社では年度はじめに
> 一括付与される運用です。有給は所定の基準より前に付与することを認めていますので、できるはずですが、
> 本システムの初期値を年度はじめに一括付与としてください。

確認への回答(2026-10-21)
- 出勤率の判定時期: 「毎月1日もしくは手動タイミングで判定をしてください。自動判定の実行状況(次回実行予定や、
  実行中など)を画面に表示してください。」
- 前倒しで短縮された期間の出勤率: 希望なし(推奨案で進める)
- ルールに付与日数の表が無い場合: 「初期ルールに法定の写しを入れる」
- 一斉付与の月: 「会社カレンダーの年度に連動」
- 比例付与の社員の日数: 「初期設定でルールを作成する(細かくルール設定できるようにする)」

## 背景・目的
会社の運用は「年度はじめに全社員へ一斉付与(法定の基準日より前倒し可)」。本システムにも一斉付与の仕組み
(`grant_cycle_type=mass_grant_month`)はあるが、初期状態のままでは次の理由で使えない。
- 初期の全社ルールに付与日数の表が無く、全社員が「要確認・0日」になる。
- 管理画面から付与方式を設定できない。
- 一斉付与の月が、会社の年度と連動していない。
- 出勤率の判定が自動で行われない。9/14の競合解消(`ff64862`)で失われた。

本変更で、導入直後から次の流れで使えるようにする。
1. 「年度はじめ一斉付与」で付与予定が作られる。
2. 毎月1日に自動で判定される。
3. 管理者が付与予定画面で確認し、一括付与する。

## 現状(As-Is)
- 付与ルール(`paid_leave_grant_rules`)
  - 付与方式は`grant_cycle_type`(`anniversary`/`mass_grant_month`)と`mass_grant_month`(1-12)で持つ
    (`2026_09_14_000000`)。既定は`anniversary`(`PaidLeaveController::storeRule:96`も同じ)。
  - 管理画面(`PaidLeavePolicyPage.tsx:413-446`、`PaidLeaveGrantRuleEditPage.tsx:83-87`、プレビュー
    `PaidLeaveGrantRulePreview.tsx`)には付与方式の入力が無い。
  - 初期の全社ルール「全社共通(4月一斉付与)」(`2026_09_14_000001`)には表(steps)が無い。
    `resolveGrantDays:251-267`で要確認・0日になる。
  - 表(`paid_leave_grant_rule_steps`: rule_id / continuous_service_months / grant_days、unique(rule_id, months)で、
    rule_idの外部キーがこのuniqueを索引に使う)には区分が無い。表は区分に関係なく全員に使われる。
    - 一方、作成時の法定照合(`PaidLeaveController:860-918`)は、全社ルールなら通常表、勤務形態のルールなら
      その区分の表で行う。
  - ルールの選択(`ScheduleCandidateGenerator::matchingRuleFor:116-135`)は、勤務形態のルール→全社ルールの順。
    `first()`に並び順の指定が無い。
- 一斉付与の日付(`massGrantScheduledDates:189-221`)
  - `hire_date`から、毎年の一斉付与月1日を並べる。付与済みの履歴は見ない。
  - 名目の継続勤務月数は、初回6か月、以後+12か月。
  - 会社カレンダー(`company_calendars.fiscal_year_start_month/day`、既定4/1、`is_default`は高々1件)とは
    連動していない。
  - 会社カレンダーの変更はイベントになっている(`CompanyCalendarUpdated`は変更後の全項目だけを持つ。
    `CompanyCalendarDefaultChanged`は作成時にも出る)。
  - 年度の計算は`GenerateCompanyCalendarYearsHandler::fiscalYearFor:106-114`。
  - `CompanyCalendarController:91-92,147-148`は、月日の組み合わせ(4/31、2/29など)を確かめていない。
- 出勤率の判定
  - 実行するのは`PaidLeaveScheduleController::reassess:190-229`(1行ずつ)だけ。
  - `paid-leave:roll-schedules`は付与予定を作るだけ。`docs/09` UC-P011/P012と`routes/console.php:27-31`は
    「付与予定日に自動判定」と書いていて、実装と違う。
  - 判定期間は`[scheduled_on − grant_cycle_months, scheduled_on]`で、当日と未来日を含む。初回も12か月前から
    数えるので、入社前の期間を含んで必ず要確認になる。
  - 閾値・判定周期(`resolveAssessmentParameters:415-436`)は勤務形態のルールしか見ない。この処理がControllerの
    中にある。
  - 判定は`AttendanceRateAssessor`(`POLICY_VERSION`あり)が`leave_attendance_rate_days`を読んで行う。移行データ
    (`PaidLeaveGrant`の`source=carried_over`)の有無も見る(`Controller:200-203`)。
- 実行の基盤
  - cronは`schedule:run`だけ。キューは`queue:work --stop-when-empty --max-time=50`を毎分起動する
    (`--timeout`は既定の60秒、`routes/console.php:19-21`)。
  - `config/app.php`のタイムゾーンはUTC。業務日付は`system_settings.default_timezone`(Asia/Tokyo)を使うのが
    既存の流儀(`DailyBatchTimezoneGroups`など)。
  - 運用コマンドの記録`admin_command_runs`(`RunAdminCommandJob`が更新)
    - `requested_by_user_id`はNOT NULL。
    - 出力は先頭の100000文字だけを残す。
- 法定付与日数マスタ: `paid_leave_grant_policies`(通常)、`paid_leave_proportional_grant_policies`(比例。週の
  区分'4'/'3'/'2'/'1')。値は`PaidLeaveGrantPolicySeeder`のとおり。
- 関連する変更セット(実装の順序の前提)
  - `20261021-paid-leave-schedule-reactor-tests`: 再計算で過去日付の行に触れない、付与済みの行と同じ日付を
    作らない、を直す。
  - `20261021-paid-leave-aggregate-stream-id`: 付与予定・有給口座の集約IDを分離する。
  - `20261021-paid-leave-same-day-grants`: 同じ日の付与と、付与予定の名目の月数。
  - `20261021-paid-leave-schedule-recalc-on-assignment-change`: `roll-schedules`を再計算にする。
  - 本変更は、これらの後に実装する。

## 設計前提の検証
- 正データと派生データ
  - 付与ルール・付与日数の表はマスタ(管理APIで直接更新する正データ)。付与予定・判定は
    `PaidLeaveScheduleAggregate`のイベントが正。
  - 会社カレンダーは他の文脈(勤怠)の正データ。本変更では、付与予定の文脈に自分の読み取りビューを作り、
    イベントから写す(原則15)。
  - 付与予定のイベントは、付与予定日・日数を確定した値として持つ。マスタを読むのはCommandを出す時点だけなので、
    Projectionを作り直しても結果は変わらない(原則2)。
  - バッチの実行状況は運用の記録で、業務の状態ではない。既存の`admin_command_runs`に記録し、イベントにはしない
    (前例: 運用コマンドの履歴)。
- 定義と実態
  - 「一斉付与月」を付与ルールにも持つと、会社の年度と二重になる。正は会社カレンダー(論点3)。
  - 表に区分が無いため、照合の表と適用の表がずれている(論点2)。
  - 一斉付与の日付を入社日だけから並べると、年度開始日を変えたときに付与済みの履歴と食い違う(論点3)。
- 原因の分類
  - 自動判定の欠落は競合解消の誤り。
  - 初回の判定期間、全社ルールの閾値の無視、ルールの選択の不定は実装のミス。
  - 表の区分の欠落、一斉付与月の二重管理は設計の誤り。対症療法(初回だけ閾値を下げる等)は採らない。

## 仕様検討

### 論点1: 付与方式の初期値と、画面からの設定
- 選択肢:
  - A. DBの既定値・作成APIの既定値・画面の初期選択を、すべて年度はじめにする。画面に付与方式の入力を足す。
  - B. 初期の全社ルールだけ年度はじめにする。
- 決定: A。
  - 値は`mass_grant_month`(表示名「年度はじめに一斉付与」)/`anniversary`(「入社日を基準に付与」)のまま。
  - 列の既定値を`mass_grant_month`にし、`storeRule`で未指定のときの既定値も同じにする。
  - 作成・編集の画面にラジオボタンを置く(初期選択は年度はじめ)。年度はじめのときは「既定の会社カレンダーの
    年度開始日: M月D日」を併記する。
  - プレビュー(`PaidLeaveGrantRulePreview`)の文言を、付与方式ごとに直す。
  - 初期の全社ルールの名前を「全社共通(年度はじめ一斉付与)」に変える。
- 理由: 新規導入でも新規のルール作成でも、要望を満たす。画面から設定できないと、初期値から戻せない。
- 未確定・要確認事項: なし

### 論点2: 付与日数の表を区分ごとに持つ
- 選択肢:
  - A. 表に区分の列を足す。1つのルールで、通常と比例(週4/3/2/1日)の表を持つ。
  - B. 比例付与用の全社ルールを、週日数ごとに作る。
  - C. 表は区分なしのまま、比例付与は法定表を使う(ユーザーは「細かく設定できるように」を選んだ)。
- 決定: A。
  - `paid_leave_grant_rule_steps`に`grant_category`(string, NOT NULL, 既定`'normal'`)を足す。値は`normal`/
    `proportional_4`/`proportional_3`/`proportional_2`/`proportional_1`。
  - uniqueを(rule_id, grant_category, continuous_service_months)にする。MySQLでは外部キーがuniqueを索引に
    使うので、新しいuniqueを追加してから古いものを消す。
  - 区分の決め方(`resolveGrantDays`)
    - 通常の社員: `normal`の表。
    - 比例の社員: 週所定日数(`weekly_scheduled_days`)が1〜4なら`proportional_N`。週所定日数が無ければ、
      年間所定日数(`annual_scheduled_days`)で決める(労基則24条の3: 169〜216→4、121〜168→3、73〜120→2、48〜72→1)。
      週0日、または年間48日未満は、要確認(日数未定)。
    - シフトの社員: `normal`の表(今の動き。勤務形態のルールが無い場合、今の目安の比例表より日数が増えることがある)。
    - 該当する区分の表が無い、または継続勤務月数に合う段が無い: 要確認(今どおり。法定表へは自動で切り替えない)。
  - 法定の照合は、行の`grant_category`に対応する法定表で行う。
  - 初期の全社ルールに入れる表(現在有効な法定マスタの版から写す。マスタが無ければシーダと同じ値)
    - 通常: 6→10, 18→11, 30→12, 42→14, 54→16, 66→18, 78→20
    - 週4: 6→7, 18→8, 30→9, 42→10, 54→12, 66→13, 78→15
    - 週3: 6→5, 18→6, 30→6, 42→8, 54→9, 66→10, 78→11
    - 週2: 6→3, 18→4, 30→4, 42→5, 54→6, 66→6, 78→7
    - 週1: 6→1, 18→2, 30→2, 42→2, 54→3, 66→3, 78→3
  - 既存の勤務形態のルールの移行: 勤務形態が比例に分類されるルールは、既存の表を`proportional_{N}`に移す
    (今の照合と同じ区分。Nは上の決め方)。それ以外は`normal`のまま。
  - 画面: 作成・編集の「付与日数」を区分ごとのタブにする。新規作成の初期値は法定の写し(法定マスタのAPIから取る)。
- 理由: ユーザー指定(法定の写し、比例も細かく設定)を、ルールの選択を変えずに満たす。照合と適用のずれも解消する。
  既存の比例のルールを移すのは、移行の後に要確認へ後退させないため。
- 未確定・要確認事項: なし

### 論点3: 一斉付与の日付を会社カレンダーの年度に連動させる
- 選択肢:
  - A. 既定の会社カレンダーの年度開始月日を、全社の一斉付与日にする。
  - B. 社員の勤務形態に紐づくカレンダーの年度開始月日を使う。
  - C. 付与ルールに月を持ち続ける。
- 決定: A。
  - 読み取りビュー(付与予定の文脈)
    - `paid_leave_fiscal_year_settings`(1行)を、Projector(`PaidLeaveFiscalYearSettingsProjector`)で作る。
      - 列: `company_calendar_id`、`fiscal_year_start_month`、`fiscal_year_start_day`、`last_changed_event_id`。
      - 写す元のイベント: `CompanyCalendarCreated`・`CompanyCalendarUpdated`・`CompanyCalendarDefaultChanged`・
        `CompanyCalendarDeleted`。
    - 年度開始月日、または既定のカレンダーが実際に変わったときだけ、`last_changed_event_id`をそのイベントのidにする。
    - `FiscalYearStartResolver`はこのビューを読む。行が無ければ4月1日。
  - 作り直しのきっかけ: Reactor(`ReapplyScheduleOnFiscalYearStartChangedReactor`)が同じイベントで動き、
    ビューの`last_changed_event_id`がそのイベントのidと一致したときだけ、`ReapplyPaidLeaveSchedulePolicyJob`を
    発行する。
    - Projectorは同じイベントでReactorより先に動く(既存の前提)。
    - 名前の変更など、年度開始と関係の無い更新では発火しない。
  - 日付の規則(`massGrantScheduledDates`を置き換える)
    - 年度開始日の求め方は`GenerateCompanyCalendarYearsHandler::fiscalYearFor`と同じ規則にする(共通の関数に
      切り出して使う)。
    - 付与済みの行が無い社員
      - 初回は「入社日より後で最初の年度開始日」と「入社日+`first_grant_after_months`」の早い方
        (前倒しは可、遅らせない)。名目は6か月。
      - 初回が前倒しになる場合(最初の年度開始日が、入社日+`first_grant_after_months`より前)は、同じ年度開始日に
        2回目(名目18か月)もまとめて付与する(ユーザー指定、2026-10-21)。
        - 例: 2026-01-01入社なら、2026-04-01に10日(名目6か月)と11日(名目18か月)の2件。2027-04-01に12日(名目30か月)、
          以後毎年4/1。
        - 年度開始日と半年の日が同じ日(例: 2025-10-01入社で4/1)は前倒しではないので、1件(10日)だけ。
          次は翌年の4/1に11日。
        - 半年の日が最初の年度開始日より前(例: 2026-04-01入社。半年は10/1、最初の年度開始日は2027-04-01)は、
          半年後(2026-10-01)に10日。次は2027-04-01に11日(前倒し)。
      - 2回目以降(まとめた場合は3回目以降)は、前回より後の毎年の年度開始日。名目は+12か月ずつ。
      - 同じ日に2件を持つには、`20261021-paid-leave-same-day-grants`(同じ日の付与、付与予定の名目の月数)が先に要る。
    - 付与済みの行がある社員
      - 起点を「最後の付与済みの付与予定日と、その名目の継続勤務月数」にする。
      - 次回は、起点より後で最初の年度開始日。名目は+12か月。以後は毎年。
      - 年度開始日を変えた年は、前回から1年以内(短い間隔)で次の付与が来る。前回から1年を超えて遅らせられない
        ためで、前倒しの扱いになる。
      - 付与済みの行の情報(日付と名目の月数)は、付与予定の文脈のProjectionから読む。名目の月数が行に無ければ、
        付与予定のイベントに足す(実装時に確かめる)。
  - `paid_leave_grant_rules.mass_grant_month`列は使わなくなるので削除する。API・画面・型からも外す。
    - migrationで、既定のカレンダーの年度開始月と違う`mass_grant_month`を持つルールがあれば、ルール名と月を
      出力に出す(付与日が変わるため)。
  - 会社カレンダーの入力の検証
    - `CompanyCalendarController`で、存在しない月日(4/31、2/29など)を422にする。
    - 2/29は閏年にしか無いので、年度開始日には使えない。
- 理由: 年度の正は会社カレンダーで、二重に持つとずれる。docs/08では、カレンダーを複数作るのは祝日の違いのため
  なので、年度は全社で1つとみる(A)。付与済みを起点にするのは、年度開始日を変えたときに二重付与・付与漏れの
  日付を作らないため。
- 未確定・要確認事項: なし

### 論点4: ルールの選択を決まった順にする
- 決定: `PaidLeaveGrantRuleResolver`で、勤務形態のルール→全社ルールの順、同じ種類は`created_at`→`id`の順。
  付与予定の日付・日数と、判定(論点5)で共通に使う。
- 理由: 全社ルールが複数あっても結果が決まる。全社ルールを1件に制限すると、既存のAPIとシーダの振る舞いが変わり、
  範囲が広がる。
- 未確定・要確認事項: なし

### 論点5: 出勤率の判定期間と、前倒しのみなし出勤
- 選択肢:
  - A. 今のまま(付与予定日の12か月前から当日まで)。
  - B. 「前回の付与日(初回は入社日)から、付与予定日の前日まで」で判定する。前倒しで短くなった分は全出勤と
    みなす(平6.1.4基発1号の考え方)。
  - C. Bから、みなし出勤を除く。
- 決定: B(ユーザーは希望なし。推奨案)。
  - 対象の行の位置づけ(同じ社員の付与予定のうち、取消を除く)
    - 初回: 付与予定日が「入社日+`first_grant_after_months`」以前で、それより前の行が無い行。
    - 前回: 付与予定日が対象の行より前の行のうち、最も遅い行。
  - 期間
    - 終わりは、付与予定日の前日。
    - 始まりは、初回なら入社日、2回目以降は前回の付与予定日。初回でも前回も無い行(利用開始日の都合で前の行が
      作られていない)は、「付与予定日−`grant_cycle_months`」。
  - 短縮期間(みなし出勤)
    - 本来の基準日は、初回なら「入社日+`first_grant_after_months`」、2回目以降なら「前回の付与予定日+
      `grant_cycle_months`」。
    - 付与予定日が本来の基準日より前なら、付与予定日から本来の基準日の前日までを短縮期間とする。
    - 短縮期間の所定労働日数は、暦日で按分して推計する: round(実期間の所定労働日数 × 短縮期間の暦日数 ÷
      実期間の暦日数)。
  - まとめて付与する2件(同じ付与予定日の名目6か月と18か月)は、1回の判定を共有する。
    - 判定の期間・みなし日数は、名目6か月の行(初回)で決める。
    - 名目18か月の行には、同じ判定の結果を記録する。
    - 一方だけが付与対象・対象外になることはない。判定の上書きをしたときも、2件に同じ結果を記録する。
  - 出勤率 = (出勤日数 + みなし日数) ÷ (所定労働日数 + みなし日数)。閾値はルールの`min_attendance_rate`
    (論点4のルール)。
  - 記録
    - 判定のイベント(`PaidLeaveScheduleAssessmentRecorded`)に`deemedAttendanceDays`(既定0。古いイベントは0として
      読む)を足す。
    - `denominatorDays`/`attendanceDays`には、みなし日数を含めない(記録から出勤率を計算し直せるようにする)。
    - `paid_leave_schedule_assessments`に`deemed_attendance_days`列を足す。
    - `AttendanceRateAssessor::POLICY_VERSION`を`v2`に上げる。
  - 利用開始日の規則(今のまま): 期間の始まりが`usage_start_date`より前で、移行データ(`carried_over`の付与)が無い場合は
    要確認。
  - 業務ルールの置き場所: `PaidLeaveScheduleAssessmentPlanner`(付与予定の文脈のSupport)。Controllerの`reassess`と
    自動判定の両方から使う。
    - 移行データの有無の確認は、今と同じく`PaidLeaveGrant`を読む。原則15の既存の例外として、Plannerのdocblockに
      明記する。
- 理由: 今は初回が必ず要確認で、年度はじめの一斉付与が成り立たない。按分による推計は、未来の勤務予定が
  無くても決まる。
- 未確定・要確認事項: なし(社労士確認が前提であることを、docs/09と画面の判定内訳に明記する)

### 論点6: 自動判定の実行時期・経路
- 選択肢:
  - A. 毎日、付与予定日が来た行を判定する(PR#109と同じ)。
  - B. 毎月1日と、手動での一括実行(ユーザー指定)。
- 決定: B。
  - コマンド`paid-leave:assess-schedules`
    - 対象は、状態が「予定」で、付与予定日が今日以前の行。「今日」は`system_settings.default_timezone`の日付。
    - 社員ごとにPlannerで判定の内容を決め、`RunAttendanceRateAssessment`を発行する。
    - 1社員の失敗で全体を止めない。失敗した社員と理由を出力に並べる。1件でも失敗があれば終了コード1。
  - 実行の経路: キュー(既定の60秒で打ち切られる)を使わず、スケジューラーから直接動かす。
    - 定期: `Schedule::command('paid-leave:assess-schedules --trigger=schedule')->monthlyOn(1, '03:00')->timezone(既定のタイムゾーン)->withoutOverlapping(180)->runInBackground()`。
    - 手動: 画面の操作で、記録(`status=queued`、`trigger=manual`)を作るだけにする。
      `Schedule::command('paid-leave:assess-schedules --pending')->everyMinute()->withoutOverlapping(180)->runInBackground()`が、
      待っている記録を1件取り出して実行する。
    - コマンドは自分で記録を`running`→`succeeded`/`failed`に更新する。
    - 運用コマンド画面からは実行させない(`#[AdminExecutable]`を付けない)。経路を1つにするため。
  - 記録(`admin_command_runs`)
    - `requested_by_user_id`をnullableにする(定期の実行はnull)。
    - 集計を入れる`summary`(json、nullable)列を足す。
      `{targets, assessed, eligible, not_eligible, needs_review, failed}`の件数で、コマンドが書く。
      出力の切り詰めに左右されない。
    - 一部失敗のときは`status=failed`、`error_message`に「N件の判定に失敗しました」と書く。
  - 二重実行の防止
    - 記録の作成と確認は、`Cache::lock('paid-leave-assessment-run')`の中で行う。
    - `queued`/`running`の記録があれば、手動の受付は409。定期の実行は記録を作らずに終える。
  - 固まった記録の扱い: `running`のまま`started_at`から2時間を超えた記録、または`queued`のまま30分を超えた記録は、
    状態の取得や次の実行のときに`failed`(「応答がないため失敗とみなしました」)にする。
  - 判定が付与予定日より最大で約1か月遅れることがある(年度開始が1日以外、入社日基準のルール)。付与日は付与予定日に
    さかのぼって記録されるが、判定と付与までの間、その日数は使えない。このことをdocs/09に書く。
- 理由: ユーザー指定。キューの60秒の打ち切りや、運用コマンドとの二重の経路で、記録が「実行中」のまま固まるのを避ける。
- 未確定・要確認事項: なし

### 論点7: 自動判定の実行状況の表示
- 決定
  - API(`leave.manage`)
    - `GET /paid-leave/schedule-assessment-runs/status`
      - 返り値は`{ next_run_at, latest_run: { id, status, trigger, requested_by_name, started_at, finished_at, summary, error_message } | null }`。
      - `next_run_at`は、スケジュールの定義と同じcron式(定数で共有する)と既定のタイムゾーンから計算する。
    - `POST /paid-leave/schedule-assessment-runs`: 202を返す。実行中なら409。
  - 画面: 付与予定画面の上部に「自動判定」カードを置く。
    - 待機中: 「次回の自動判定: 11月1日 3:00」。
    - 前回の結果: 「前回: 10月1日 3:02 完了(対象12件 / 付与対象10件 / 対象外1件 / 要確認1件 / 失敗0件)」。
      失敗のときは`error_message`を表示する。
    - 実行中(queued/running): 「判定を実行中です」と表示し、5秒ごとに状態を取り直す。終わったら一覧と超過の件数を
      取り直す。
    - 「今すぐ判定する」は確認ダイアログを出してから実行する(本文「付与予定日を過ぎた『予定』の行を、今すぐ判定
      します。」)。実行中は押せない。
  - 置き場所: 実行の記録の作成・取得・固まった記録の扱いは、`App\Services\PaidLeave\ScheduleAssessmentRunService`に置く。
    運用の基盤に依存するので、アプリケーション層に置く。
  - 運用コマンド画面の履歴は、依頼者がnullなら「システム」と表示する。型の`requested_by_user_id`をnullableにする。
- 理由: ユーザー指定。人事が使う画面で完結させる。
- 未確定・要確認事項: なし

### 論点8: 既存の環境のデータの扱い
- 決定
  - migrationは、マスタの変更(列の追加・削除、既定値、全社ルールへの法定の写し、比例のルールの表の移行、名前の
    変更)だけを行う。Jobはdispatchしない。
  - 付与予定の作り直しは、新しい運用コマンド`paid-leave:schedule:reapply-policy`(`#[AdminExecutable]`。中身は
    `ReapplyPaidLeaveSchedulePolicyJob`と同じ)で、デプロイの後に実行する。
    - 出力には、作り直した社員の数と、付与予定日・日数が変わった行の数を出す。
    - 手順を`docs/27`に書く。
  - 導入1年目は、移行データ(`carried_over`の付与)が無い既存の社員の判定は、ほぼ全員が要確認になる(利用開始日の
    規則)。受け入れ条件の注記に書く。
- 理由: migrationの途中で業務の処理を動かすと、テスト(`QUEUE_CONNECTION=sync`)や、後のmigrationとの順序で
  壊れやすい。変わった件数を運用者が確かめられるようにする。
- 未確定・要確認事項: なし

### 論点9: 今回の範囲外にする、消えていたPR#109の機能
- 決定: 次は扱わない(対象外に書く)。
  - 個別修正と再計算の食い違いを要確認にする仕組み
  - 社員ごとのタイムゾーン
  - 30日を超えた付与対象の警告ログ
- 理由: 年度はじめ一斉付与・自動判定とは独立に直せる。
- 未確定・要確認事項: なし

### 論点10: 2件まとめの付与と既存の社員の扱い(論点3・5・8を補う。食い違うときはこちらを正とする)
- まとめた2件目(名目18か月)の判定
  - 判定の期間は、すべて前倒しで短縮された期間に当たる(6〜18か月目)。全出勤とみなして付与対象にする。
  - ただし、利用開始日の規則による要確認は残す。
  - 1件目(名目6か月)の判定とは共有しない(論点5の「判定を共有」を置き換える)。初回が対象外でも、2件目は付与対象になる。
  - 社労士確認が前提であることを、docs/09に書く。
- 2件は常に組で扱う(付与予定の集約の規則)
  - 組の目印は、同じ社員・同じ付与予定日で、名目の月数が初回とその+12か月の2行。
  - 判定の上書き・再判定・個別修正・付与・置き換え(Superseded)・取消は、組の2行に同じ操作をする。
  - 一括付与では、組の片方だけを選べない。APIは、組の片方だけが指定されたら422にする。画面も、片方を選ぶともう片方も選ぶ。
  - 付与は、1回の`ApplyScheduledGrants`で2件を続けて行う。片方の付与に失敗したら、もう片方も付与しない。有給口座の付与は
    2件のイベントを1回のpersistで記録する。
- 付与済みを起点にするとき(論点3): 最後の付与日に付与済みの行が2件あれば、名目の月数の大きい方を起点にする。
- 既存の社員(ユーザー回答 2026-10-21: 「社員の利用開始日をもとに付与を計画してください。利用開始の次の付与日から付与する
  ようにしてください」)
  - 付与日と名目の月数は、入社日から計算する(上の規則のまま)。
  - 利用開始日より前の付与日の行は作らない(今の`ScheduleCandidateGenerator`も、利用開始日より前の候補を作らない)。
    付与日が利用開始日と同じ日なら作る。
  - 利用開始日より前の分(手動の付与など)は、システムの計算に入れない。
  - 例: 2026-01-01入社で、利用開始日が2026-10-01の社員。2026-04-01の2件は作らず、2027-04-01の名目30か月(12日)から作る。
- 2026-03-31入社の社員には、翌日の2026-04-01に2件(計21日)を付与する(ユーザー回答: 「そのままでよい」)。
- 日付の計算: `addMonths`を`addMonthsNoOverflow`にする。8/30・8/31入社の半年後が3/2・3/3になって、法定の3/1より遅れる
  ことを防ぐ。前倒しかどうかの判定にも同じ計算を使う。
- 名目の月数は、`first_grant_after_months`から計算する(初回=`first_grant_after_months`、以後+12)。規則の説明の「6・18か月」は
  既定値の例。
- 論点3の「名目の月数が行に無ければ、付与予定のイベントに足す(実装時に確かめる)」は、`20261021-paid-leave-same-day-grants`で
  足すと決まった。
- テスト(仕様確定事項のテストに足す)
  - 2026-01-01入社: 2026-04-01に10日+11日、2027-04-01に12日(名目30か月)。
  - 2025-10-01入社: 2026-04-01に10日の1件。
  - 2026-04-01入社: 2026-10-01に10日、2027-04-01に11日(名目18か月)。
  - 比例(週3日)のまとめ付与: 5日+6日。
  - まとめた2件の判定: 初回が対象外でも、2件目は付与対象。
  - 組の操作: 上書き・付与・置き換えが2行に及ぶ。片方だけの一括付与は422。
  - まとめた2件の後、付与済みを起点にした次回(名目の大きい方+12)。
  - 利用開始日による開始: 上の例。
  - 8/31入社の半年後が3/1。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
各論点の決定を正とする。実装は次の2段階に分ける。

### 段階1: 付与方式・日数の表・年度連動
- migration
  1. 付与方式の既定値の変更、`mass_grant_month`列の削除(違う月のルールを出力に出す)。
  2. `grant_category`の追加と、uniqueの付け替え(追加してから削除)。
  3. 比例のルールの表の移行、全社ルールへの法定の写し、全社ルールの名前の変更。
  4. `paid_leave_fiscal_year_settings`の作成。今の既定のカレンダーから初期値を入れる。
- Support: `PaidLeaveGrantRuleResolver`、`FiscalYearStartResolver`、年度開始日の共通の関数。
  `ScheduleCandidateGenerator`の日付と日数の変更。
- Projector・Reactor: `PaidLeaveFiscalYearSettingsProjector`、`ReapplyScheduleOnFiscalYearStartChangedReactor`。
- API: `PaidLeaveController`(付与方式、区分ごとの表と照合)、`CompanyCalendarController`(月日の検証)。
- 運用コマンド: `paid-leave:schedule:reapply-policy`。
- frontend: ルールの作成・編集・プレビュー・一覧(付与方式と区分ごとの表)。型の変更。
- テスト
  - Unit
    - 日付: 年度開始が4/1・10/1・4/16のそれぞれ。前倒しと6か月の分岐。付与済みを起点にした次回
      (4/1付与済み→年度開始を10/1に→同じ年の10/1、名目+12)。
    - 日数: 比例の週3日と、年間日数による区分。週0日は要確認。区分の表が無いと要確認。
    - ルールの選択の順。
  - Feature
    - ルールのAPI: 既定は年度はじめ。区分ごとの照合で422。
    - migrationの結果(写し、比例の移行、名前)。
    - カレンダーの年度開始の変更→作り直し(シナリオ)。名前だけの変更では作り直さない。
    - 月日の検証。
  - 期待値が変わる既存のテストの一覧(実装者が直す)
    - `RollPaidLeaveSchedulesCommandTest`、`ScheduleCandidateGeneratorTest`(mass_grant_monthの前提)、
      `PaidLeaveGrantRuleAdminTest`(mass_grant_month)
    - `grant_cycle_type`を指定していないルールを作るテスト(既定値の変更で年度はじめになる)
    - `ScenarioSeeder`のルール(付与方式を`anniversary`と明示する)

### 段階2: 判定期間・自動判定・実行状況
- `PaidLeaveScheduleAssessmentPlanner`(論点5)。`reassess`をこれに置き換える。
- 付与予定の文脈の「今日」を、`system_settings.default_timezone`の日付に揃える。
  - 対象: 再計算のReactor 2本、`roll-schedules`、`ScheduleCandidateGenerator::currentWorkStyleFor`/`userIdsCurrentlyOn`、
    `ReapplyPaidLeaveSchedulePolicyJob`。
  - `20261021-paid-leave-schedule-recalc-on-assignment-change`の論点3で、この変更セットへ先送りしたもの。
- イベントの`deemedAttendanceDays`、`paid_leave_schedule_assessments.deemed_attendance_days`、`POLICY_VERSION=v2`。
- `paid-leave:assess-schedules`と、`routes/console.php`の2つのスケジュール(論点6)。`roll-schedules`の
  コメントを実態に合わせる。
- migration: `admin_command_runs`の`requested_by_user_id`をnullableにし、`summary`を足す。
- `ScheduleAssessmentRunService`と、API 2本(論点7)。
- frontend: 自動判定のカード、判定内訳のみなし日数と注記、運用コマンドの履歴の「システム」。
- テスト
  - Unit(Planner)。年を固定して、2026年の前提で書く。
    - 前倒しの初回: 入社2026-01-10、年度開始4/1、`first_grant_after_months=6`。期間は1/10〜3/31(81日)、
      短縮期間は4/1〜7/9(100日)。所定55日・出勤50日なら、みなし日数=round(55×100/81)=68、
      出勤率=(50+68)/(55+68)=95.9%で付与対象。
    - 前倒しでない初回は、みなし0日。
    - 2回目以降の期間の始まりと短縮期間。
    - 全社ルールの閾値を使う。
    - 期間の終わりは前日。
    - 初回でも前回も無い行。
  - Feature
    - コマンド: 来た「予定」だけを判定する。1社員の失敗で他は止まらず、終了コード1。summaryを記録する。
      タイムゾーンの境界(UTCの前日、JSTの当日)。
    - スケジュール: 手動の記録を拾って実行する。固まった記録を失敗にする。二重の受付は409。
    - API: 状態の取得(記録なし/実行中/完了/失敗)、202、409、403。
  - frontend: カードの各状態と、ボタン・確認ダイアログ。
  - e2e(scenario-15): 「今すぐ判定する」で実行され、結果が表示される。

## 受け入れ条件
- 新規の環境で、付与ルールを触らずに付与予定を作ると、既定のカレンダーの年度開始日(既定4/1)の一斉付与になる。
  日数は、通常の社員は法定の通常表、比例の社員は週日数に応じた比例表になる。
- 既存の環境では、migrationと`paid-leave:schedule:reapply-policy`の後に同じ状態になる。既存の比例のルールは
  要確認に後退しない。
- ルールの作成・編集画面で、付与方式(初期選択は年度はじめ)と区分ごとの付与日数を設定できる。法定を下回ると、
  区分ごとにエラーになる。
- 既定のカレンダーの年度開始日を変えると、未確定の付与予定の日付が追従する。付与済みの社員は、最後の付与から
  1年以内の日付になる。名前だけの変更では作り直さない。
- 毎月1日03:00(既定のタイムゾーン)と「今すぐ判定する」で判定が動く。付与予定日が来た「予定」の行が、付与対象/
  対象外/要確認になる。
- 前倒しの初回付与が、入社前の期間のせいで要確認にならない。判定内訳にみなし日数が出る。ただし導入1年目で
  移行データの無い社員は、利用開始日の規則により要確認になる。
- 付与予定画面に、次回の予定・実行中・前回の結果が出る。二重に実行できない。固まった記録は2時間で失敗になる。
- 業務ルールのUnitテスト、文脈の連鎖のシナリオテスト、frontendのunit/story、e2eがPASSする。期待値を変えた既存の
  テストは、一覧のとおり。
- docs(下記)とコードのコメントが、実装と一致する。

## 対象外
- AggregateIdの分離・Reactorのテスト(別の変更セット。本変更より先に実装する)
- 個別修正と再計算の食い違いの要確認、社員ごとのタイムゾーン、30日超の警告ログ
- 自動付与(付与は今どおり管理者の一括付与)
- 付与方式を勤務形態のカレンダーごとに変えること
- シフトの社員の、年間所定日数による比例の判定
- 有効な全社ルールを1件に制限すること
- 個別の年度を`CompanyCalendarYearFiscalYearCorrected`で補正した場合(年度開始日には本体の月日を使う)
- 年度開始日を変えた後に`RebuildPaidLeaveScheduleCommand`(過去分の作り直し)を使うと、過去も今の年度開始日で
  計算し直されること(docs/09に注記するだけ)
- 古いmigration(`2026_08_10_020900`)がイベントなしで`is_default`を立てているため、Projectionを作り直すと既定の
  カレンダーが失われうること(`paid_leave_fiscal_year_settings`はmigrationで初期値を入れるので、付与日には影響しない)

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`
  - 付与方式: 年度はじめが初期値、既定のカレンダーの年度開始日に連動、付与済みを起点にする規則。
  - 付与日数の表の区分と、初期の全社ルール。
  - 判定期間・みなし出勤(社労士確認が前提)。
  - UC-P011(作成のみ)と、UC-P012(毎月1日の自動判定・手動・実行状況・最大1か月の遅れ)。
  - 過去分の作り直しの注記。
- `docs/16-database-schema.md`
  - `paid_leave_grant_rules`、`paid_leave_grant_rule_steps.grant_category`、`paid_leave_schedule_assessments.deemed_attendance_days`、
    `paid_leave_fiscal_year_settings`(新規)
  - `admin_command_runs`(表の定義が無いので足す)
- `docs/17-events.md`: `paid_leave_schedule.assessment_recorded`の`deemedAttendanceDays`。
- `docs/08-usecases-calendar-shift.md`: UC-C009に、既定のカレンダーの年度開始日が有給の一斉付与日にも使われることと、
  月日の検証を足す。
- `docs/27-release-runbook.md`: デプロイの後に`paid-leave:schedule:reapply-policy`を実行する。
- `docs/testing/scenario-tests.md` §18: 自動判定の手順。
- `docs/changesets/README.md`

## モック・アセット
なし

### 実装前メモ(ui-interaction-patterns §3.3)
- 自動判定のカード: 状態の表示と、操作1つ(確認ダイアログあり)。状態は、記録なし/待機中/実行中(ポーリング)/
  完了/失敗/取得エラー。
- ルールのフォーム: 既存のフォームに入力を足す。付与日数は区分ごとのタブ。未保存の確認は今のまま。

## 実装対象
- backend
  - `database/migrations/`(段階1で4本、段階2で2本)
  - `app/Domain/PaidLeaveSchedule/`(Support、Projectors、Reactors、Events、Aggregates)
  - `app/Services/PaidLeave/ScheduleAssessmentRunService.php`
  - `app/Console/Commands/`(`AssessPaidLeaveSchedulesCommand`、`ReapplyPaidLeaveSchedulePolicyCommand`)
  - `routes/console.php`、`routes/api.php`
  - `app/Http/Controllers/Api/`(`PaidLeaveController`、`PaidLeaveScheduleController`、
    `PaidLeaveScheduleAssessmentRunController`(新規)、`CompanyCalendarController`)
  - `app/Http/Resources/PaidLeaveGrantRuleResource.php`、`app/Models/`、`database/seeders/ScenarioSeeder.php`
  - `tests/`
- frontend
  - `src/pages/paidLeave/`、`src/components/PaidLeaveGrantRulePreview/`
  - 自動判定のカード(新規)
  - `src/api/`、`src/hooks/`、`src/pages/admin/AdminCommandsPage.tsx`
  - `e2e/scenario-15-paid-leave-schedule.spec.ts`
- docs: 上記

## 検証方法
- backend: `php artisan test --filter='PaidLeave|CompanyCalendar|AdminCommand'`と、全件(既知の
  `ReadModelFingerprintCommandTest`の失敗を除く)。MySQLでのmigrationの確認(uniqueの付け替え)。
- frontend: `npx tsc -p tsconfig.app.json --noEmit`、`npm run lint`、`npx vitest run --project=unit`、
  `npx vitest run --project=storybook`(関連の範囲)。
- e2e: `npm run test:e2e -- scenario-00-master-data scenario-03-paid-leave scenario-15-paid-leave-schedule`

## レビュー履歴
- 初版(2026-10-21)
- 2026-10-21 独立設計レビュー(同等モデル)の指摘を反映して、全体を書き直した。設計前提の「定義と実態」に
  付与済みの履歴との食い違いを足した。
  - 要修正
    - 年度開始日を変えると二重付与の日付になる→付与済みを起点にする(論点3)。
    - カレンダーのイベントから変化を判定できない→自分の読み取りビューと`last_changed_event_id`(論点3)。
    - タイムゾーンがUTCになる→既定のタイムゾーン(論点6)。
    - 比例のルールが要確認に後退する→表を移す(論点2)。
    - キューの打ち切りで「実行中」のまま固まる→スケジューラーで直接動かし、固まった記録の扱いを決めた(論点6)。
    - SUMMARY行が切り詰めで消える→`summary`列(論点6)。
    - 初回の定義が無い→初回・前回・本来の基準日を定義した(論点5)。
  - 推奨
    - uniqueの付け替えの順。
    - migrationからJobを出さない→運用コマンド。
    - 違う月のルールを出力に出す。
    - 月日の検証。
    - 年間日数による区分。
    - 期待値が変わるテストの一覧。
    - プレビュー・型。
    - 受付の競合→ロック。運用コマンド画面からは実行させない。
    - cron式を共有する。
    - アプリケーション層に置く。
    - 判定のイベントの項目・`POLICY_VERSION`。
    - 最大1か月の遅れの明記。
  - 軽微
    - Unitテストの年を固定した。
    - `admin_command_runs`の表の定義を足す。
    - 利用開始日の規則の書き方を直した。
    - 導入1年目の注記。
    - 過去分の作り直しの注記。
    - 古いmigrationの注記。

- 2026-10-21 ユーザーの指定(初回が前倒しのときは、年度開始日に半年分と1年半分の2件をまとめて付与する)を、論点3の
  日付の規則と論点5の判定に足した。土台は`20261021-paid-leave-same-day-grants`に分けた。

- 2026-10-21 独立設計レビュー(同等モデル)の指摘と、ユーザー回答(既存の社員は利用開始日から計画する、年度末の入社は
  そのまま)を、論点10として反映した。
  - 要修正
    - 判定の共有をやめ、2件目は全出勤とみなした。
    - 2件を組で扱うことを集約の規則にした。
    - まとめ付与のテストを足した。
  - 推奨
    - 起点の名目の月数の取り方。
    - 既存の社員の扱い。
    - 年度末の入社。
    - `addMonthsNoOverflow`。
    - 名目の月数の出し方。

- 2026-10-21 実装の前に、`20261021-paid-leave-schedule-recalc-on-assignment-change`で先送りした「今日」のタイムゾーンの
  統一を、段階2に足した。ルールの選択の順(論点4)は、その変更セットで`matchingRuleFor`に入れた。ここでは
  `PaidLeaveGrantRuleResolver`に切り出して、判定と共有する。

## 実装結果
未着手
