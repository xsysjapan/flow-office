# 有給の年度はじめ一斉付与を初期値にし、出勤率の自動判定を戻す

ステータス: 検討中

## 変更要望(原文)
> (自動判定を戻す変更セットと、PR#109の機能の突き合わせを)お願いします。加えて今の会社では年度はじめに
> 一括付与される運用です。有給は所定の基準より前に付与することを認めていますので、できるはずですが、
> 本システムの初期値を年度はじめに一括付与としてください。

確認への回答(2026-10-21):
- 出勤率の判定時期: 「毎月1日もしくは手動タイミングで判定をしてください。自動判定の実行状況(次回実行予定や、
  実行中など)を画面に表示してください。」
- 前倒しで短縮された期間の出勤率: 希望なし(推奨案で進める)
- ルールに付与日数の表が無い場合: 「初期ルールに法定の写しを入れる」
- 一斉付与の月: 「会社カレンダーの年度に連動」
- 比例付与の社員の日数: 「初期設定でルールを作成する(細かくルール設定できるようにする)」

## 背景・目的
会社の運用は「年度はじめに全社員へ一斉付与(法定の基準日より前倒し可)」。本システムにも一斉付与の
仕組み(`grant_cycle_type=mass_grant_month`)はあるが、次の理由で、初期状態のままでは使えない。
- 初期の全社ルールに付与日数の表が無く、全社員が「要確認・0日」になる。
- 管理画面から付与方式を設定できない。
- 一斉付与の月が会社の年度と連動していない。
- 出勤率の判定が自動で行われない。判定は9/14の競合解消(`ff64862`)で失われた。

本変更で、導入直後から「年度はじめ一斉付与」で予定が作られ、毎月1日に自動で判定され、管理者が付与予定画面で
確認して一括付与できる状態にする。

## 現状(As-Is)
- 付与ルール(`paid_leave_grant_rules`)
  - 付与方式は`grant_cycle_type`(`anniversary`/`mass_grant_month`)と`mass_grant_month`(1-12)で持つ
    (`2026_09_14_000000`)。既定値は`anniversary`で、`PaidLeaveController::storeRule:96`も同じ。
  - 管理画面(`PaidLeavePolicyPage.tsx:413-446`、`PaidLeaveGrantRuleEditPage.tsx:83-87`)には付与方式の
    入力欄が無く、APIからしか設定できない。
  - 初期の全社ルール「全社共通(4月一斉付与)」(`2026_09_14_000001`)は`mass_grant_month=4`で、付与日数の
    表(steps)が無い。そのため`ScheduleCandidateGenerator::resolveGrantDays:251-267`で日数が決まらず、
    要確認・0日になる。
  - 付与日数の表(`paid_leave_grant_rule_steps`: rule_id / continuous_service_months / grant_days)には区分の
    次元が無い。表は区分(通常・比例・シフト)に関係なく全員に使われる。一方、作成時の法定最低日数の照合
    (`PaidLeaveController:860-873,884-`)は、全社ルールなら通常表、勤務形態のルールならその区分の表で行う。
    照合に使う表と、実際に適用する表がずれている。
  - ルールの選択(`ScheduleCandidateGenerator::matchingRuleFor:116-135`)は勤務形態のルール→全社ルールの順。
    `first()`に並び順の指定が無く、全社ルールが複数あると選ばれ方が決まらない(`ScenarioSeeder:207-218`が
    2件目を作る)。
- 一斉付与の日付(`ScheduleCandidateGenerator::massGrantScheduledDates:189-221`)
  - 初回は「入社後最初の一斉付与月1日」と「入社+`first_grant_after_months`」の早い方。2回目以降は毎年の
    一斉付与月1日。
  - 会社カレンダーの年度開始(`company_calendars.fiscal_year_start_month/day`、既定4/1、`is_default`は
    高々1件)とは連動していない。
- 出勤率の判定
  - 実行するのは`PaidLeaveScheduleController::reassess:190-229`(1行ずつの手動操作)だけ。毎日のcron
    `paid-leave:roll-schedules`(`RollPaidLeaveSchedulesCommand`)は付与予定を作るだけ。
  - `docs/09` UC-P011/P012と`routes/console.php:27-31`は「付与予定日に自動判定」と書いていて、実装と違う。
  - PR#109版(`f860060:backend/app/Console/Commands/RollPaidLeaveSchedulesCommand.php`)には、自動判定と、
    1社員の失敗で全体を止めない処理、失敗時の終了コードがあった。
  - 判定期間は`[scheduled_on − grant_cycle_months, scheduled_on]`で、付与予定日当日と未来日を含む。
    初回(前倒しを含む)も12か月前から数えるので、入社前の期間が含まれ、必ず「要確認」
    (`usage_start_date_boundary`等)になる。
  - 閾値・判定周期(`resolveAssessmentParameters:415-436`)は勤務形態のルールしか見ず、全社ルールを無視する。
    これらの解決処理がControllerの中にあり、cronから再利用できない(原則9・16)。
  - 判定は`AttendanceRateAssessor`が`leave_attendance_rate_days`を読んで行う。
- バッチの実行状況
  - 「次回実行予定」「実行中」を記録・表示する仕組みは無い。
  - 管理画面の運用コマンド(`admin_command_runs`、`RunAdminCommandJob`、`AdminCommandsPage`)は、手動実行の
    履歴(queued/running/succeeded/failed、出力、終了コード)を持つ。
  - `admin_command_runs.requested_by_user_id`はNOT NULLで、システムによる実行を記録できない。
  - cronは`schedule:run`だけで、キューは`queue:work --stop-when-empty`を毎分起動する(`routes/console.php:19-21`)。
- 法定付与日数マスタ: `paid_leave_grant_policies`(通常)、`paid_leave_proportional_grant_policies`
  (比例。`weekly_scheduled_days_category`='4'/'3'/'2'/'1')。値は`PaidLeaveGrantPolicySeeder`のとおり。

## 設計前提の検証
- 正データと派生データ
  - 付与ルール・付与日数の表・会社カレンダーはマスタ(管理APIで直接更新する正データ)。付与予定・判定は
    `PaidLeaveScheduleAggregate`のイベントが正で、`paid_leave_schedule_entries`/`_assessments`は派生。
  - 本変更では、付与予定・判定の状態を直接書く経路を作らない。自動判定も、既存の`RunAttendanceRateAssessment`
    Commandを発行する(原則1・2・9)。
  - バッチの実行状況は運用の記録で、業務状態ではない。既存の`admin_command_runs`(通常のテーブル)に記録し、
    イベントにはしない(前例: 運用コマンドの履歴)。
- 定義と実態
  - 「一斉付与月」は付与ルールの値として持っているが、ユーザーの要望は「会社の年度に連動」。年度の正は会社
    カレンダーなので、付与ルールに月を二重に持たない(論点3)。
  - 付与日数の表に区分が無いため、全社ルールに法定の写しを入れると比例付与の社員にも通常の日数が付く。表に
    区分の次元を足す(論点2)。
  - ドキュメントと実装のずれ(自動判定)は、`ff64862`で機能が失われたもの(前の変更セット
    `20261020-paid-leave-schedule-page-restore`で特定済み)。
- 原因の分類
  - 自動判定の欠落は競合解消の誤り。
  - 初回判定が必ず要確認になる、全社ルールの閾値が無視される、ルールの選ばれ方が決まらない、の3つは
    実装のミス。
  - 照合の表と適用の表のずれ、一斉付与月の二重管理は設計の誤り。
  - 対症療法(初回だけ閾値を下げる等)は採らず、判定期間と日数の表の設計を直す。

## 仕様検討

### 論点1: 付与方式の初期値と、画面からの設定
- 選択肢:
  - A. DBの既定値・作成APIの既定値・画面の初期選択を、すべて「年度はじめに一斉付与」にする。画面に付与方式の
    入力欄を足す。
  - B. 既定値は変えず、初期の全社ルールだけ一斉付与にする。
- 決定: A。
  - 付与方式の値は`mass_grant_month`(年度はじめに一斉付与)/`anniversary`(入社日を基準に付与)のまま
    使う。画面の表示名は「年度はじめに一斉付与」「入社日を基準に付与」にする。
  - `grant_cycle_type`の列の既定値を`mass_grant_month`に変える(新規migration)。`storeRule`で未指定時の
    既定値も同じにする。
  - 画面: ルールの作成フォーム・編集画面に、付与方式のラジオボタンを足す。初期選択は年度はじめ。
    年度はじめを選んだときは「会社カレンダー(既定)の年度開始日: M月D日」を併記する。
- 理由: 「本システムの初期値を年度はじめに一括付与」という要望を、新規導入・新規ルール作成のどちらでも
  満たす。画面から設定できないと、初期値を変えたあとに戻すこともできない。
- 未確定・要確認事項: なし

### 論点2: 付与日数の表を区分ごとに持つ(初期ルールに法定の写しを入れる)
- 選択肢:
  - A. `paid_leave_grant_rule_steps`に区分の列を足し、通常と比例(週4/3/2/1日)の表を1つのルールで持つ。
    初期の全社ルールには、法定の通常表と比例表をすべて写す。
  - B. 比例付与用の全社ルールを、週日数ごとに別ルールとして作る(ルールの選び方に週日数の条件が要る)。
  - C. 表は区分なしのまま、比例付与の社員は法定表を使う(ユーザーは「細かくルール設定できるように」を選んだ)。
- 決定: A。
  - `paid_leave_grant_rule_steps`に`grant_category`(string, NOT NULL, 既定`'normal'`)を足す。値は
    `normal`/`proportional_4`/`proportional_3`/`proportional_2`/`proportional_1`。unique制約を
    (rule_id, grant_category, continuous_service_months)に変える。既存の行は`normal`になる。
  - 日数の決め方(`resolveGrantDays`)
    - 区分`normal`の社員: ルールの`normal`の表を使う。
    - 区分`proportional`の社員: 勤務形態の週所定日数(1〜4)に対応する`proportional_N`の表を使う。
    - 区分`shift`の社員: 今の動きどおり`normal`の表を使う(年間所定日数による判定はしない)。
    - 該当する区分の表が無い、または継続勤務月数に合う段が無い場合は、今と同じく「要確認・日数未定」にする。
      法定表へは自動で切り替えない(ルールの指定を優先し、不足を見えるようにする)。
  - 法定最低日数の照合(`validateStepsAgainstStatutoryMinimum`)は、行ごとの`grant_category`に対応する
    法定表で行う。`normal`は通常表、`proportional_N`は比例表の週N日。
  - 初期の全社ルールの表(新規migration。全社ルールが表を1行も持たない場合だけ入れる)
    - 写す元は、`paid_leave_grant_policies`と`paid_leave_proportional_grant_policies`のうち、現在有効な版。
      シーダがまだ入っていない環境では、`PaidLeaveGrantPolicySeeder`と同じ値を使う。
    - 通常: 6→10, 18→11, 30→12, 42→14, 54→16, 66→18, 78→20
    - 週4: 6→7, 18→8, 30→9, 42→10, 54→12, 66→13, 78→15
    - 週3: 6→5, 18→6, 30→6, 42→8, 54→9, 66→10, 78→11
    - 週2: 6→3, 18→4, 30→4, 42→5, 54→6, 66→6, 78→7
    - 週1: 6→1, 18→2, 30→2, 42→2, 54→3, 66→3, 78→3
  - 画面: ルールの作成・編集の「付与日数」を区分ごとのタブにする(通常/週4日/週3日/週2日/週1日)。新規作成
    フォームの初期値は、法定表の写し(全区分)にする。
- 理由: ユーザー指定(初期ルールに法定の写し、比例付与も細かく設定できる)を、ルールの選び方を変えずに
  満たせる。区分ごとに照合すれば、照合の表と適用の表のずれも解消する。
- 未確定・要確認事項: なし

### 論点3: 一斉付与の日付を会社カレンダーの年度に連動させる
- 選択肢:
  - A. 既定の会社カレンダー(`is_default=true`)の年度開始月日を、全社の一斉付与日にする。
  - B. 社員の勤務形態に紐づく会社カレンダーの年度開始月日を使う(社員ごとに違いうる)。
  - C. 付与ルールに月を持ち続ける(連動しない)。
- 決定: A。
  - `mass_grant_month`方式の付与日は「既定の会社カレンダーの`fiscal_year_start_month`/`fiscal_year_start_day`」
    にする。既定のカレンダーが無ければ4月1日を使う(`CompanyCalendarController`の既定値と同じ)。
  - 規則は今の`massGrantScheduledDates`のまま、月の1日を「年度開始月日」に置き換える。
    - 初回: 「入社日より後で最初の年度開始日」と「入社日+`first_grant_after_months`」の早い方
      (前倒しは可、遅らせない)。
    - 2回目以降: 前回より後の、毎年の年度開始日。
  - `paid_leave_grant_rules.mass_grant_month`列は使わなくなるので、migrationで削除する。API・画面・型からも
    外す。
  - 既定のカレンダーの年度開始月日が変わったとき、または既定のカレンダーが切り替わったときは、
    PaidLeaveSchedule側のReactorで`ReapplyPaidLeaveSchedulePolicyJob`を発行する。未確定の予定を
    作り直す(既存の「ルール変更時の作り直し」と同じ扱い。原則15)。
    - 対象のイベントは、会社カレンダーの更新・既定の切替のイベントのうち、年度開始月日または`is_default`が
      変わったもの。実装時にイベント名を確定する。
    - 更新がイベントになっていない場合は、そのことを報告して止める。
- 理由: 年度の正は会社カレンダーで、二重に持つとずれる。docs/08では複数のカレンダーは祝日の違いのためのもので、
  年度は全社で1つとみるのが自然。そこで、社員ごとに変わるB案より、既定のカレンダーを使うA案にする。
- 未確定・要確認事項: なし

### 論点4: ルールの選ばれ方を決まるようにする
- 選択肢:
  - A. 並び順を指定する(勤務形態のルール→全社ルール。同じ種類が複数あれば作成日時が古い順→id)。
  - B. 有効な全社ルールを1件に制限する(作成・有効化のAPIで422)。
- 決定: A。あわせて、判定(論点6)・日数・日付で同じルール選択を使う(共通の`PaidLeaveGrantRuleResolver`)。
- 理由: Bは既存のAPIの振る舞いと`ScenarioSeeder`を変える必要があり、範囲が広い。Aで結果は決まる。
- 未確定・要確認事項: なし

### 論点5: 出勤率の判定期間(初回・前倒し)
- 選択肢:
  - A. 今のまま(付与予定日の12か月前から当日まで)。
  - B. 判定期間を「前回の付与日(初回は入社日)から、付与予定日の前日まで」にする。前倒しで短くなった分は
    全出勤とみなす(行政通達 平6.1.4基発1号の考え方)。
  - C. Bのうち、みなし出勤を足さない(実際の在籍期間だけで判定)。
- 決定: B(ユーザーは希望なし。推奨案で進める)。
  - 期間の終わり: 付与予定日の前日(当日と未来日を含めない)。
  - 期間の始まり
    - 初回: 入社日。
    - 2回目以降: 前回の付与予定日。同じ社員の前の付与予定(取消を除く)の`scheduled_on`を使う。
      無ければ「付与予定日 − `grant_cycle_months`」。
  - 前倒しの判定: 初回で、付与予定日が「入社日+`first_grant_after_months`」より前の場合。
    - 短縮された期間は、付与予定日から本来の基準日の前日まで。
    - この期間の所定労働日数は暦日で按分して推計する: 所定労働日の推計 = 実期間の所定労働日数 ×
      短縮期間の暦日数 ÷ 実期間の暦日数(四捨五入)。推計した日数を、分母・分子の両方に足す。
    - 2回目以降の一斉付与で期間が12か月より短い場合も、同じ扱いにする。
  - 既存の`usage_start_date`による要確認の規則は残す。ただし判定するのは、上の期間の始まりより後の部分。
  - 判定の結果には、実際の分母・分子に加えて、みなし出勤の日数を残す。Assessmentのイベントにみなし日数の
    項目を足すか、既存の除外日数の項目で表すかは、実装時に既存のpayloadを確かめて決める。payloadを変えるなら
    `docs/17`も直す。
  - 判定の業務ルール(期間・みなし日数・閾値の解決)は、`PaidLeaveScheduleAssessmentPlanner`(Support)に
    置く。Controllerの`reassess`と自動判定の両方から使う(原則9・16)。
- 理由: 今は初回が必ず要確認になり、年度はじめの一斉付与が成り立たない。通達の考え方に沿うBにする。
  按分による推計は、将来の勤務予定がまだ作られていなくても決まる。
- 未確定・要確認事項: なし(社労士確認が前提であることを、docs/09と画面の判定内訳に明記する)

### 論点6: 自動判定の実行時期と対象
- 選択肢:
  - A. 毎日、付与予定日が来た行を判定する(PR#109と同じ)。
  - B. 毎月1日に、付与予定日が来た行を判定する。手動でも一括で実行できる(ユーザー指定)。
- 決定: B。
  - 新しいコマンド`paid-leave:assess-schedules`を作る(`#[AdminExecutable]`)。
  - 対象は、状態が「予定」(Scheduled)で、付与予定日が今日以前(アプリのタイムゾーン)の行。社員ごとに
    `PaidLeaveScheduleAssessmentPlanner`で期間・閾値を決め、`RunAttendanceRateAssessment`を発行する。
  - 1社員の失敗で全体を止めない。失敗した社員と理由を出力に並べ、1件でも失敗があれば終了コード1にする
    (PR#109と同じ)。
  - 出力の最後の行に、集計をJSONで出す: `SUMMARY {"targets":N,"assessed":N,"eligible":N,"not_eligible":N,"needs_review":N,"failed":N}`。
  - 毎月1日03:00(アプリのタイムゾーン)に起動する。起動はcronから直接ではなく、`admin_command_runs`に
    実行記録を作り、`RunAdminCommandJob`をキューに入れる形にする。手動実行と同じ経路で記録が残る。
    - `routes/console.php`に`->monthlyOn(1, '03:00')->withoutOverlapping()`のクロージャを置く。
    - `admin_command_runs.requested_by_user_id`をnullableにする(migration)。システムによる実行はnull。
  - 手動実行: 付与予定画面の「今すぐ判定する」から実行する(下記の論点7)。
  - 同時実行の防止: 状態が`queued`/`running`の記録があるときは、新しい実行を受け付けない(409)。cronの側も
    記録を作らずに終える。`RunAdminCommandJob`の既存のロックも併用する。
  - 「付与予定日を過ぎても予定のまま」の行は、次の1日まで「付与予定日超過」に出る(現行の絞り込みのまま)。
- 理由: ユーザー指定。月1回にすると、前月末までの勤怠の入力・修正を反映しやすい。
- 未確定・要確認事項: なし

### 論点7: 自動判定の実行状況の表示
- 選択肢:
  - A. 付与予定画面の上部に「自動判定」カードを置く。次回の実行予定、今の状態(待機中/実行中)、前回の結果、
    「今すぐ判定する」ボタンを出す。
  - B. 運用コマンド画面(`/admin/commands`)の履歴だけで見せる。
- 決定: A。
  - API
    - `GET /paid-leave/schedule-assessment-runs/status`(`leave.manage`)。返り値:
      `{ next_run_at, latest_run: { id, status, trigger: 'schedule'|'manual', requested_by_name, started_at, finished_at, summary, error_message } | null }`。
    - `next_run_at`は、次の毎月1日03:00をサーバーで計算した値。
    - `summary`は、出力のSUMMARY行を解析した値。行が無ければnull。
  - `POST /paid-leave/schedule-assessment-runs`(`leave.manage`)。実行記録を作ってキューに入れ、202を返す。
    実行中なら409。
  - 画面
    - 待機中: 「次回の自動判定: 11月1日 3:00」。
    - 前回の結果: 「前回: 10月1日 3:02 完了(対象12件 / 付与対象10件 / 対象外1件 / 要確認1件 / 失敗0件)」。
      失敗時はエラーの要旨。
    - 実行中(queued/running): 「判定を実行中です」と表示し、5秒ごとに状態を取り直す(運用コマンド画面の
      既存のポーリングと同じ)。
    - 終わったら、付与予定の一覧と超過件数を取り直す。
    - 「今すぐ判定する」は確認ダイアログを経て実行する。本文は「付与予定日を過ぎた『予定』の行を、今すぐ判定
      します。」。実行中はボタンを無効にする。
- 理由: ユーザー指定(次回実行予定や実行中を画面に表示)。人事が使う付与予定画面で完結させる。既存の運用記録を
  使えば、新しいテーブルが要らない。
- 未確定・要確認事項: なし

### 論点8: 既存の環境のデータの扱い
- 選択肢:
  - A. migrationで、初期の全社ルール(表が空)に法定の写しを入れ、付与予定の作り直しをキューに入れる。
  - B. 新規の環境だけ対象にし、既存の環境は管理者が手で設定する。
- 決定: A。
  - 表を入れる対象は、有効な全社ルールのうち表が1行も無いもの。
  - migrationの最後に`ReapplyPaidLeaveSchedulePolicyJob`をdispatchする。
  - `mass_grant_month`列の削除で、既存の`anniversary`のルールは影響を受けない。既存の`mass_grant_month`の
    ルールは、年度開始月日に連動するようになる。既定のカレンダーが4/1なら、今と同じ結果になる。
- 理由: 既存の環境でも、今は全社員が「要確認・0日」で使えない。直さない理由が無い。
- 未確定・要確認事項: なし

### 論点9: 今回の範囲外にする、消えていたPR#109の機能
- 決定: 以下は今回扱わない(対象外に記載する)。
  - AggregateIdの分離: 別のタスクとして提案済み。
  - 再計算Reactorのテスト: 別のタスクとして提案済み。
  - 個別修正と再計算の食い違いを要確認にする仕組み、社員ごとのタイムゾーン、30日超の警告ログ: ユーザーの判断待ち。
- 理由: いずれも年度はじめ一斉付与・自動判定とは独立に直せる。範囲を広げない。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### 段階1: 付与方式・日数の表・年度連動
- backend
  - migration
    1. `paid_leave_grant_rules.grant_cycle_type`の既定値を`mass_grant_month`にする。`mass_grant_month`列を
       削除する。
    2. `paid_leave_grant_rule_steps`に`grant_category`(string, default `'normal'`)を足し、unique制約を
       (rule_id, grant_category, continuous_service_months)にする。
    3. 有効な全社ルールのうち表が空のものに法定の写し(論点2の値)を入れ、`ReapplyPaidLeaveSchedulePolicyJob`を
       dispatchする。
  - `PaidLeaveGrantRuleResolver`(`App\Domain\PaidLeaveSchedule\Support`): 社員と基準日から、適用するルールを
    決まった順で返す。勤務形態のルール→全社ルール、それぞれ`created_at`→`id`の順。
    `ScheduleCandidateGenerator`・判定(段階2)・`resolveAssessmentParameters`の代わりに使う。
  - `FiscalYearStartResolver`(同Support): 既定の会社カレンダーの年度開始月日を返す。無ければ4/1。
  - `ScheduleCandidateGenerator`
    - `massGrantScheduledDates`の「一斉付与月1日」を、年度開始月日に置き換える。
    - `resolveGrantDays`は、区分と週所定日数から`grant_category`を決めて、その表を引く(論点2)。
  - `PaidLeaveController`
    - store/update/resource から`mass_grant_month`を外す。`grant_cycle_type`の既定値を`mass_grant_month`にする。
    - steps の入力を`[{grant_category, continuous_service_months, grant_days}]`にする。`grant_category`を
      省略したら`normal`。
    - 法定最低日数の照合は区分ごとに行う。
  - Reactor(PaidLeaveSchedule側): 既定のカレンダーの年度開始月日、または既定の切替が変わったら、
    `ReapplyPaidLeaveSchedulePolicyJob`を発行する(論点3)。
- frontend
  - 型・API: `PaidLeaveGrantRule`に`grant_cycle_type`を足し、steps に`grant_category`を足す。
    `mass_grant_month`は外す。
  - ルールの作成フォーム(`PaidLeavePolicyPage`)と編集画面(`PaidLeaveGrantRuleEditPage`)
    - 付与方式のラジオボタンを置く。初期選択は年度はじめ。年度開始日を併記する。年度開始日は、既定の会社
      カレンダーを取得する既存のAPIから読む。無ければ「4月1日(既定)」。
    - 付与日数は区分ごとのタブにする。新規作成の初期値は、法定の写し(法定付与日数マスタのAPIから取得)。
  - ルール一覧に付与方式を表示する。
- テスト
  - Unit
    - `ScheduleCandidateGenerator`: 年度開始が4/1と10/1の両方で、前倒し・6か月目の分岐、2回目以降の毎年の
      日付が正しい。既定のカレンダーが無いと4/1。比例付与(週3日)の社員には`proportional_3`の表の日数が付く。
      区分の表が無ければ要確認。
    - `PaidLeaveGrantRuleResolver`: 全社ルールが2件あると古い方が選ばれる。勤務形態のルールが優先される。
  - Feature
    - ルールのAPI: 付与方式を省略すると年度はじめになる。区分ごとの法定最低日数で422になる。
    - migration後に全社ルールへ法定の写しが入る。
    - 既定のカレンダーの年度開始を変えると、未確定の付与予定が作り直される(シナリオ)。

### 段階2: 判定期間・自動判定・実行状況の表示
- backend
  - `PaidLeaveScheduleAssessmentPlanner`(Support): 付与予定1行に対して、期間・みなし日数・閾値を決める
    (論点5)。`AttendanceRateAssessor`の呼び出しまでを含め、Controllerの`reassess`はこれを使うだけにする。
  - `paid-leave:assess-schedules`コマンド(論点6)。SUMMARY行を出す。
  - `routes/console.php`
    - 毎月1日03:00のクロージャで、実行記録を作ってキューに入れる。
    - `paid-leave:roll-schedules`のコメントを実態に合わせる(判定はしない)。
  - migration: `admin_command_runs.requested_by_user_id`をnullableにする。
  - 実行記録の作成・状態取得の処理は、`App\Domain\PaidLeaveSchedule\Support\AssessmentRunService`に置く。
    cronとAPIの両方から使う。
  - API: `GET /paid-leave/schedule-assessment-runs/status`、`POST /paid-leave/schedule-assessment-runs`
    (論点7。`leave.manage`)。
  - `AdminCommandsPage`の履歴で依頼者がnullのときは「システム」と表示する(frontend)。
- frontend
  - 付与予定画面の上部に「自動判定」カードを置く(論点7)。
  - 詳細パネルの「勤怠データを確認」に、みなし出勤の日数と注記「前倒し付与で短縮された期間は全出勤とみなして
    います(社労士確認が前提)」を出す。
- テスト
  - Unit(Planner)
    - 初回・前倒し: 入社1/10、年度開始4/1、`first_grant_after_months=6`の場合。期間は1/10〜3/31(81日)、短縮
      期間は4/1〜7/9(100日)。実期間の所定労働日を55日、出勤50日とすると、みなし日数は
      round(55×100/81)=68日。出勤率は(50+68)/(55+68)=95.9%で付与対象になる。
    - 前倒しでない初回: みなし0日。
    - 2回目以降: 期間の始まりが前回の付与予定日になる。
    - 全社ルールの`min_attendance_rate`が使われる。
    - 期間の終わりが付与予定日の前日になる。
  - Feature
    - コマンド: 付与予定日が来た「予定」だけを判定し、未来の行は「予定」のまま残る。1社員が失敗しても他は
      判定され、終了コードは1。SUMMARY行の内容。
    - API: 状態の取得(記録なし/実行中/完了)、実行の受付(202)、実行中なら409、`leave.manage`が無ければ403。
    - cronのクロージャ: 記録を作ってJobをキューに入れる。実行中なら作らない。
  - frontend: 自動判定カードの各状態(次回予定、実行中のポーリング、前回の結果、失敗、ボタンと確認ダイアログ)。
  - e2e(scenario-15): 「今すぐ判定する」で実行され、結果が表示される。

## 受け入れ条件
- 新規の環境で、付与ルールを触らずに付与予定を作ると、全社員の付与予定が既定のカレンダーの年度開始日
  (既定4/1)の一斉付与で作られる。日数は、通常の社員は法定の通常表、比例付与の社員は週日数に応じた比例表になる
  (「要確認・0日」にならない)。
- 既存の環境でも、migration後に全社ルールへ法定の写しが入り、付与予定が作り直される。
- ルールの作成・編集画面で、付与方式(初期選択は年度はじめ)と区分ごとの付与日数を設定できる。法定を下回ると
  区分ごとにエラーになる。
- 既定のカレンダーの年度開始日を変えると、未確定の付与予定の日付がそれに追従する。
- 毎月1日03:00に自動判定が動く。「今すぐ判定する」でも同じ判定が動く。付与予定日が来た「予定」の行が
  付与対象/対象外/要確認になる。
- 前倒しの初回付与が、入社前の期間を理由に「要確認」にならない。短縮期間のみなし出勤が判定内訳に表示される。
- 付与予定画面に、次回の自動判定の予定、実行中の表示、前回の結果が出る。実行中は二重に実行できない。
- 業務ルール(日付・日数・期間・みなし日数・ルール選択)のUnitテスト、文脈間の連鎖(カレンダー変更→作り直し、
  コマンド→判定→付与対象)のシナリオテスト、frontendのunit/story、e2eがPASSする。既存のテストに退行が無い。
- `docs/09`・`docs/16`・`docs/17`(payloadを変える場合)・`docs/testing/scenario-tests.md`・
  `routes/console.php`のコメントが実装と一致している。

## 対象外
- AggregateIdの分離(別のタスクとして提案済み)。
- 再計算Reactorのテストの復元(別のタスクとして提案済み)。
- 個別修正と再計算の食い違いを要確認にする仕組み、社員ごとのタイムゾーン、30日超の警告ログ(PR#109にあった。
  必要かはユーザーの判断待ち)。
- 自動付与(判定のあと、付与まで自動で行うこと)。付与は今どおり管理者の一括付与。
- 付与方式を勤務形態のカレンダーごとに変えること(論点3のB案)。
- シフト区分の社員の年間所定日数による比例判定(今どおり通常の表を使う)。
- 有効な全社ルールを1件に制限すること(論点4のB案)。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`
  - 付与方式(年度はじめ一斉付与が初期値、既定のカレンダーの年度開始日に連動)。
  - 付与日数の表の区分。初期の全社ルール(法定の写し)。
  - 判定期間・前倒し時のみなし出勤(社労士確認が前提)。
  - UC-P011(作成のみ)、UC-P012(毎月1日の自動判定・手動の一括判定・実行状況の表示)。
- `docs/16-database-schema.md`: `paid_leave_grant_rules`(`mass_grant_month`の削除、既定値)、
  `paid_leave_grant_rule_steps.grant_category`、`admin_command_runs.requested_by_user_id`のnullable。
- `docs/17-events.md`: Assessmentのpayloadを変える場合のみ。
- `docs/08-usecases-calendar-shift.md`: 既定のカレンダーの年度開始日が、有給の一斉付与日にも使われる旨を
  UC-C009に1行足す。
- `docs/testing/scenario-tests.md` §18: 自動判定(今すぐ判定する)の手順。
- `docs/changesets/README.md`: 一覧に追加する。

## モック・アセット
なし

### 実装前メモ(ui-interaction-patterns §3.3)
- 自動判定カード: 状態表示+操作1つ(確認ダイアログあり)。状態は、記録なし / 待機中 / 実行中
  (ポーリング) / 完了 / 失敗 / 取得エラー。
- ルールのフォーム: 既存のフォームに入力を足す。付与日数のタブは区分ごと。未保存の確認は既存のまま。

## 実装対象
- backend
  - `database/migrations/`(4本)
  - `app/Domain/PaidLeaveSchedule/Support/`(`PaidLeaveGrantRuleResolver`、`FiscalYearStartResolver`、
    `PaidLeaveScheduleAssessmentPlanner`、`AssessmentRunService`、`ScheduleCandidateGenerator`の変更)
  - `app/Domain/PaidLeaveSchedule/Reactors/`(カレンダー変更)
  - `app/Console/Commands/AssessPaidLeaveSchedulesCommand.php`
  - `routes/console.php`、`routes/api.php`
  - `app/Http/Controllers/Api/PaidLeaveController.php`、`PaidLeaveScheduleController.php`、
    新しい`PaidLeaveScheduleAssessmentRunController.php`
  - `app/Http/Resources/PaidLeaveGrantRuleResource.php`、`app/Models/PaidLeaveGrantRule*.php`
  - `tests/Unit/PaidLeaveSchedule/`、`tests/Feature/PaidLeaveSchedule/`、`tests/Feature/PaidLeaveAccount/`
- frontend
  - `src/pages/paidLeave/`(`PaidLeavePolicyPage`、`PaidLeaveGrantRuleEditPage`、`PaidLeaveSchedulePage`)
  - 自動判定カードのコンポーネント(新規)
  - `src/api/`、`src/hooks/`、`src/pages/admin/AdminCommandsPage.tsx`(依頼者がnullのとき「システム」)
  - `e2e/scenario-15-paid-leave-schedule.spec.ts`、必要なら`scenario-00`
- docs: 上記「ドキュメントへの影響」

## 検証方法
- backend: `php artisan test --filter='PaidLeave'`、および全件(既知の`ReadModelFingerprintCommandTest`の失敗を除く)
- frontend: `npx tsc -p tsconfig.app.json --noEmit`、`npm run lint`、`npx vitest run --project=unit`、
  `npx vitest run --project=storybook`(関連範囲)
- e2e: `npm run test:e2e -- scenario-00-master-data scenario-03-paid-leave scenario-15-paid-leave-schedule`

## レビュー履歴
初版(2026-10-21)

## 実装結果
未着手
