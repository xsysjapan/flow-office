# 付与予定の再計算Reactorのテストを復元し、再計算の不具合を直す

ステータス: 検討中

## 変更要望(原文)
> このタスクをここで実行してください:「付与予定の再計算Reactorのテストを復元する」

(タスクの内容: 再計算のReactor 2本にテストが無い。以前あった6本のテストが9/14の競合解消`ff64862`で
消えたので、今の実装に合わせて書き直す)

## 背景・目的
付与予定を再計算する次の2本のReactorには、テストが1本も無い。原則16(文脈間の連鎖をテストで網羅する)に
反するので、テストを書く。
- `RecalculateScheduleOnUserConditionChangedReactor`: 入社日・利用開始日・自動付与の変更で再計算する
- `RecalculateScheduleOnWorkStyleChangedReactor`: 勤務形態の月次割当・勤務形態の更新で再計算する

調査と設計レビューで、再計算の連鎖に不具合が3件見つかった。いずれもテストで連鎖を確かめる範囲に入るので、
あわせて直す。
- 勤務形態を更新しても、再計算の対象から漏れる社員がいる。
- 過去日付の未付与の予定が取り消される。
- 付与済みの予定と同じ日付の予定が、もう一度作られる。

## 現状(As-Is)
- `RecalculateScheduleOnUserConditionChangedReactor.php`
  - `user.hire_date_set` / `user.usage_start_date_set` / `user.paid_leave_auto_grant_enabled_set`で、
    条件なしに発火する(38-51)。
  - 今日から1年先までの候補を`candidatesFor`で作り、`RecalculateFutureSchedule`を発行する(55-68)。
- `RecalculateScheduleOnWorkStyleChangedReactor.php`
  - `onUserWorkStyleAssignedForMonth`(47-50)で、割当の対象社員を再計算する。
  - `onWorkStyleUpdated`(52-73)は、区分の判定に使う5つの属性のキーがイベントにあれば発火する(34-40)。
    対象の社員は、`user_work_style_monthly_assignments`のうち`work_style_id`が一致し、`year_month >= 当月`の行(64-68)。
- 付与予定の計算(`ScheduleCandidateGenerator::currentWorkStyleFor:238-249`)は、「当月以前で最新の割当」を
  社員の勤務形態とみなす。割当は月ごとに必ずあるわけではない(飛び飛びでよい)。
- `PaidLeaveScheduleAggregate::recalculateFutureSchedule`(91-160)
  - 日付で絞り込まない。候補(今日から1年先)に無い未確定の行は、過去日付でも取消になる(102-133)。
    `docs/09-usecases-paid-leave.md:110-111`には「過去日付のエントリは自動再計算の対象外」とあり、食い違う。
  - 付与済み・取消済みの行を読み飛ばすとき(107-109)に、同じ日付の候補を消費しない。そのため、付与予定日当日に
    付与したあとで再計算すると、同じ日付の新しい予定が作られる(155-157)。個別修正済みの行は消費している(118)。
- `GrantCategoryClassifier`(34-56)は`agreed_scheduled_days_per_year`を見ない。この属性を変えても、区分は
  変わらない。
- 旧テスト(`f860060:backend/tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`、6本)は、
  付与ルールを作っていない、今は`private`のメソッドを呼ぶ、などの理由で、今の実装ではそのまま使えない。
- 今のテストで連鎖を通るのは`ManuallyEditScheduleEntryScenarioTest`の入社日変更の1件だけ。
- 勤務形態の更新(`UpdateWorkStyleHandler:34-38`)は、入力された属性だけをイベントに入れる。一方、イベントの
  docblock(`Events/WorkStyleUpdated.php:14`)は「属性一式」と書いている。

## 設計前提の検証
- 正データと派生データ: 入社日などは`UserAggregate`、勤務形態・割当は各集約のイベントが正。付与予定は
  `PaidLeaveScheduleAggregate`が正。テストの状態は、すべてCommandかマスタ(付与ルールはイベントにしていない)で作る。
- 定義と実態
  - 「社員の今の勤務形態」の定義が、付与予定の計算とReactorで食い違っている。正しいのは計算側。
  - 再計算の対象期間(今日以降)と、Aggregateが取消にする範囲(全期間)が食い違っている。正しいのは仕様書
    (過去日付は対象外)。
- 原因の分類: テストの欠落は競合解消の誤り。3件の不具合は実装のミス。

## 仕様検討

### 論点1: テストするケース
- 決定: `tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`(新規)に、次のケースを書く。
  - 共通の前提
    - `Carbon::setTestNow('2026-10-21')`。
    - 勤務形態は`CreateWorkStyle` Commandで作る。
      - 週5日(通常): `weekly_scheduled_days=5`
      - 週3日(比例): `weekly_scheduled_days=3`、`annual_scheduled_days=null`、`prescribed_weekly_minutes=1440`、
        `is_shift_based=false`
    - 付与ルールはマスタなのでEloquentで作る。入社日基準で、勤務形態ごとに日数を変える。
      - 週5日: 6→10, 18→11, 30→12, 42→14
      - 週3日: 6→5, 18→6, 30→6, 42→8
    - 社員は既存テストと同じ作り方(Factory)で作り、`SetUserHireDate(2024-01-01)`と、当月の勤務形態の割当
      (`AssignUserWorkStyleForMonth`)をCommandで行う。この時点で付与予定が1行できる(2027-07-01、継続42か月)。
    - イベントの件数は、`stored_events`の`paid_leave_schedule.*`の別名で数える。基準の件数は、前提を作り終えた後に取る。
  - 入社日・利用開始日・自動付与(1本目のReactor)
    1. 入社日を2025-01-01に変える。2027-07-01の行は取消になり、2027-01-01(継続24か月→11日)の行が新しくできる。
    2. 同じ入社日(2024-01-01)を設定し直しても、付与予定のイベントは増えない。
    3. 利用開始日を2027-08-01にすると、2027-07-01の行が取消になる。
    4. 自動付与を無効にすると未確定の行が取消になり、有効に戻すと作り直される。
    5. 付与済みの行は変わらない。付与予定日が今日(当日に付与した場合)でも、同じ日付の新しい行は作られない
       (論点4の修正を確かめる)。付与済みにする手順は`RunAttendanceRateAssessment`(付与対象)→`ApplyScheduledGrants`。
    6. 過去日付(付与予定日を過ぎた)の未付与の行は、入社日を変えても取消にならない(論点4の修正を確かめる)。
  - 勤務形態(2本目のReactor)
    7. 当月の割当を週3日から週5日に変えると、区分が比例から通常になり、日数が8日から14日に変わる。
    8. 週3日の勤務形態の属性を、次のように1つずつ`UpdateWorkStyle`で変えると、区分が比例から通常になる
       (日数は同じ勤務形態のルールなので変わらない。取消+新規で区分だけ変わることを確かめる)。
       - `weekly_scheduled_days` 3→5
       - `annual_scheduled_days` null→217
       - `prescribed_weekly_minutes` 1440→1800
       - `is_shift_based` false→true(区分はシフト)
    9. 名前だけを変えても、付与予定のイベントは増えない。
    10. 割り当てた社員がいない勤務形態を更新しても、何も起きない。
    11. 過去の月(2026-01)に1回だけ割り当て、今もそれが有効な社員も、勤務形態を更新すると再計算される
        (論点2の修正を確かめる)。
  - 原則2の確認: ケース1と7の後に、付与予定のProjectionを空にしてイベントから作り直し、同じ結果になることを確かめる。
  - `agreed_scheduled_days_per_year`は区分を変えないので、結果では確かめられない。対象社員の取り出し(論点2)の
    Unitテストと、発火の判定(属性のキーがあるか)の確認に留める。
- 理由: 旧テスト6本の観点(各きっかけ、無関係な変化では発火しない)を、今の実装で意味のある形にした。
  そのうえで、冪等・付与済み・過去日付・割当なし・過去の割当の境界を足した。
- 未確定・要確認事項: なし

### 論点2: 勤務形態を更新したときの、再計算の対象の社員
- 選択肢:
  - A. 付与予定の計算と同じ定義にする(当月以前で最新の割当が、この勤務形態の社員)。取り出しは
    `ScheduleCandidateGenerator`の公開メソッドにして、計算とReactorで共有する。
  - B. 今のまま(当月以降に割当がある社員だけ)。
- 決定: A。
  - `ScheduleCandidateGenerator::userIdsCurrentlyOn(string $workStyleId, CarbonInterface $today): array`を作る。
  - 社員ごとに、当月以前の最大の`year_month`の行を取り、その`work_style_id`が一致する社員を返す
    (unique(user_id, year_month)があるので重複しない)。`currentWorkStyleFor`も同じ定義を使う。
  - 当月より後にだけ割当がある社員は、今の計算では結果が変わらないので対象にしない。
  - Unitテスト: 当月以前の最新の割当がこの勤務形態/後の月に別の勤務形態へ変わった/過去にだけ割当がある/
    当月より後にだけ割当がある。
- 理由: Bでは、勤務形態の週日数を変えても、過去に割り当てたまま使っている社員の付与予定が、古い区分・日数のまま
  残る。業務ルールなので、Reactorのprivateメソッドではなく、テストで直接呼べる場所に置く(domain-test)。
- 未確定・要確認事項: なし

### 論点3: 結果に影響しない点
- 決定
  - APIで勤務形態を更新すると、`prescribed_weekly_minutes`が必須のため、名前だけの変更でも発火する。再計算は
    冪等で、結果は変わらないので直さない。
  - `WorkStyleUpdated`のdocblockを、実装(入力された属性だけ)に合わせて直す。`docs/17`は「属性一式」と
    書いていないので、変更しない。
- 理由: テストの復元が目的なので、結果に影響しない点まで広げない。
- 未確定・要確認事項: なし

### 論点4: 再計算で、過去日付の行と付与済みの行の扱いを直す
- 選択肢:
  - A. Aggregateで、再計算の対象期間の始まりより前の行には触れない。付与済み・取消済みの行を読み飛ばすときも、
    同じ日付の候補を消費する。
  - B. テストに今の動きを記録し、修正は別の変更セットにする。
- 決定: A。
  - `recalculateFutureSchedule`に、対象期間の始まり`string $fromDate`を引数で渡す。`scheduledOn < $fromDate`の行は、
    状態に関わらず触れない(取消も置換もしない)。
    - Reactor・`ReapplyPaidLeaveSchedulePolicyJob`・`RebuildPaidLeaveScheduleCommand`は、それぞれ候補を作るときの
      `from`を渡す。`RebuildPaidLeaveScheduleCommand`は過去の期間を指定するので、その`from`を渡せば今の動きのまま。
  - 付与済み・取消済みの行を読み飛ばすときも、`unset($desiredByScheduledOn[$entry['scheduledOn']])`する。
    - ただし`includeGrantedEntries=true`(過去分の作り直し)の付与済みの行は、今どおり対象にする。
    - 取消済みの行で候補を消費すると、取り消した日付に予定を作り直せなくなる(ケース4の自動付与を戻す操作)。
      そのため、消費するのは付与済みの行だけにする。
  - Unitテスト(`PaidLeaveScheduleAggregateTest`): 過去日付の未確定の行は残る/今日の付与済みの行と同じ日付の候補は
    作られない/取消済みの行と同じ日付の候補は作られる/`includeGrantedEntries=true`の動きは変わらない。
- 理由
  - 1件目(過去日付の行の取消)では、付与予定日を過ぎてまだ付与していない予定が、入社日や勤務形態の変更で
    黙って消える。「付与予定日超過」の確認や一括付与ができなくなる。
  - 2件目(同じ日付の行)は、二重付与につながる。
  - どちらもテストで連鎖を確かめると必ず出るので、ここで直す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `ScheduleCandidateGenerator::userIdsCurrentlyOn`(論点2)。`currentWorkStyleFor`も同じ定義を使う。
- `RecalculateScheduleOnWorkStyleChangedReactor::onWorkStyleUpdated`は、`userIdsCurrentlyOn`で対象を取る。
  docblockの「過去月のみの割当は対象外」を直す。
- `PaidLeaveScheduleAggregate::recalculateFutureSchedule`の修正(論点4)。`RecalculateFutureSchedule` Commandに
  `fromDate`を足す。呼び出し元4か所を直す。
- `Events/WorkStyleUpdated.php`のdocblock。
- テスト
  - `ScheduleRecalculationReactorTest`(新規、論点1のケース1〜11と再生の確認)
  - `ScheduleCandidateGeneratorTest`(`userIdsCurrentlyOn`)
  - `PaidLeaveScheduleAggregateTest`(論点4)

## 受け入れ条件
- 新しいテストがすべてPASSする。ケース5・6・11と論点4のUnitテストは、修正前のコードでは失敗する
  (実装者が確かめて報告する)。
- `php artisan test --filter=PaidLeaveSchedule`と、`RebuildPaidLeaveScheduleCommandTest`・
  `ReapplyPaidLeaveSchedulePolicyJobTest`の既存のテストに退行が無い。

## 対象外
- 退職日の設定による再計算。
- 勤務形態の割当の削除(`UserWorkStyleMonthlyAssignmentRemoved`)による再計算。今は購読するReactorが無い。
- 月が変わって、先の月に登録した勤務形態の割当が有効になったときの再計算。今は`roll-schedules`が作成しかしない。
- APIで名前だけを変えたときの不要な発火の抑止。
- 付与予定の計算が他の文脈のテーブルを直接読んでいる点の整理(原則15の既存の例外)。
- `docs/09:507`(roll-schedulesの対象が利用開始日で絞られる、という記述)と実装の違い。

上の勤務形態の割当の削除と月の切り替わりの2件は、付与予定の区分・日数が古いまま残る経路なので、別のタスクとして提案する。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`: 再計算は過去日付の行に触れないこと、付与済みの行と同じ日付の予定を作らないこと
  を、UC-P011の再計算の節に1文ずつ足す。
- `docs/17-events.md`: 変更なし。
- `docs/changesets/README.md`: 一覧の題名を更新する。

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/PaidLeaveSchedule/`(Aggregates、Commands/RecalculateFutureSchedule、Handlers、Reactors、
  Support/ScheduleCandidateGenerator)
- `backend/app/Jobs/ReapplyPaidLeaveSchedulePolicyJob.php`、`backend/app/Console/Commands/RebuildPaidLeaveScheduleCommand.php`
- `backend/app/Domain/Attendance/Events/WorkStyleUpdated.php`(docblock)
- `backend/tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`(新規)、
  `tests/Unit/PaidLeaveSchedule/`(Aggregate、Generator)
- `docs/09-usecases-paid-leave.md`

## 検証方法
- `cd backend && php artisan test --filter=PaidLeaveSchedule`、`--filter=RebuildPaidLeaveSchedule`

## レビュー履歴
- 初版(2026-10-21)
- 2026-10-21 独立設計レビュー(同等モデル)の指摘を反映した。
  - 要修正
    - ケース7・8が成り立たない(同じ日数の表、区分を変えない属性)→勤務形態ごとに日数を変え、属性ごとの
      変更前後の値を決めた。`agreed_scheduled_days_per_year`は結果で確かめない。
    - 過去日付の未付与の行の取消、付与済みの行と同じ日付の二重作成→論点4を新設して直す。
    - 対象の取り出しをprivateメソッドにしない→Generatorの公開メソッドにし、Unitテストを足した。
  - 推奨
    - 当月より後の割当を対象から外した。
    - 割当の削除・月の切り替わりの2件の連鎖を対象外に書いた。
    - 前提の値を固定した。
    - Commandで作れるものを明記した。
    - 再生の確認を足した。
    - イベントは別名で数えることにした。
  - 軽微: docblockの行番号を直した。`docs/17`は変更しない。

## 実装結果
未着手
