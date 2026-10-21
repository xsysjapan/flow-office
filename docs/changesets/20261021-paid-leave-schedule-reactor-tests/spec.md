# 付与予定の再計算Reactorのテストを復元する

ステータス: 検討中

## 変更要望(原文)
> このタスクをここで実行してください:「付与予定の再計算Reactorのテストを復元する」

(タスクの内容: 再計算Reactor 2本のテストが無い。以前あった6本のテストが9/14の競合解消`ff64862`で
消えたので、今の実装に合わせて書き直す)

## 背景・目的
付与予定を再計算する次の2本のReactorには、テストが1本も無い。原則16(文脈間の連鎖をテストで網羅する)に
反する。今の実装に合わせてテストを書く。
- `RecalculateScheduleOnUserConditionChangedReactor`: 入社日・利用開始日・自動付与の変更
- `RecalculateScheduleOnWorkStyleChangedReactor`: 勤務形態の月次割当・勤務形態の更新

## 現状(As-Is)
- `RecalculateScheduleOnUserConditionChangedReactor.php`
  - `user.hire_date_set` / `user.usage_start_date_set` / `user.paid_leave_auto_grant_enabled_set`で、
    無条件に発火する(38-51)。
  - `ScheduleCandidateGenerator::candidatesFor(today〜1年先)`で候補を作り、`RecalculateFutureSchedule`
    (`overrideManualEdits=false`)を発行する(55-68)。
- `RecalculateScheduleOnWorkStyleChangedReactor.php`
  - `onUserWorkStyleAssignedForMonth`(47-50): 割当の対象社員を再計算する。
  - `onWorkStyleUpdated`(52-73): 区分の判定に使う5つの属性のキーがイベントにあれば発火する(34-40)。
    対象社員は`user_work_style_monthly_assignments`のうち`work_style_id`が一致し、`year_month >= 当月`の
    行(64-68)。
- 付与予定の計算(`ScheduleCandidateGenerator::currentWorkStyleFor:238-249`)は、「当月以前で最新の割当」を
  社員の勤務形態とみなす。
- 旧テスト(`f860060:backend/tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`、6本)は
  次の理由で、今の実装ではそのまま使えない。
  - 付与ルールを作っておらず、今の実装では候補が0件になり、すべて取消になる。
  - 今は`private`の`createEntry`を直接呼んでいる。
  - 旧実装の前提(`EmployeeCalendarEntry`)に依存している。
- 今のテストでこの連鎖を通るのは`ManuallyEditScheduleEntryScenarioTest`の入社日変更の1件だけ。
- 勤務形態の更新(`UpdateWorkStyleHandler:34-38`)は、入力された属性だけをイベントに入れる。ところがイベントの
  docblock(`Events/WorkStyleUpdated.php:82`)は「変更後の属性一式」と書いていて、実装と食い違っている。

## 設計前提の検証
- 正データと派生データ
  - 入社日等は`UserAggregate`、勤務形態の割当・勤務形態は各集約のイベントが正。付与予定は
    `PaidLeaveScheduleAggregate`が正。
  - Reactorは他の文脈のイベントに反応して、自文脈のCommandを発行する(原則15どおり)。
  - テストの状態は、すべてCommand経由で作る。勤務形態の割当もProjectionへの直接作成ではなく、
    `AssignUserWorkStyleForMonth`で作る(既存のヘルパー`assignWorkStyle`はProjectionを直接作るので使わない)。
- 定義と実態
  - 「社員の今の勤務形態」の定義が、計算(当月以前で最新の割当)とReactor(当月以降に割当がある)でずれている。
    過去の月に1回だけ割り当てて、そのまま使い続けている社員は、勤務形態を更新しても再計算されない。
  - 割当が月ごとに必ずあるとは限らない。計算側は飛び飛びの割当を前提にしている。そのため、正しいのは
    計算側の定義(論点2)。
- 原因の分類: テストの欠落は競合解消の誤り。Reactorの対象の選び方は実装のミス。

## 仕様検討

### 論点1: テストするケース
- 決定: 次のケースを、`tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`(新規)に書く。
  - 共通の前提
    - 社員: 入社日2024-01-01、自動付与は有効。
    - 勤務形態: 週5日(通常)と週3日(比例)の2つ。割当は`AssignUserWorkStyleForMonth`で行う。
    - 付与ルール: 入社日基準、steps 6→10, 18→11, 30→12。勤務形態ごとに作る。
    - 付与予定: `paid-leave:roll-schedules`で作る。
    - 日付は`Carbon::setTestNow`で固定する。
  - 入社日・利用開始日・自動付与(Reactor1)
    1. 入社日を変える(`SetUserHireDate`)と、付与予定日・日数が新しい入社日で計算し直される(旧日付の行は取消、
       新しい行が作られる)。
    2. 同じ入社日を設定し直しても、付与予定のイベントは増えない(冪等)。
    3. 利用開始日を後ろにずらす(`SetUserUsageStartDate`)と、それより前の未確定の行が取消になる。
    4. 自動付与を無効にする(`SetPaidLeaveAutoGrantEnabled(false)`)と、未確定の行が取消になる。有効に戻すと
       作り直される。
    5. 付与済み(Granted)の行は、入社日を変えても変わらない。
  - 勤務形態(Reactor2)
    6. 当月の割当を週3日から週5日の勤務形態に変える(`AssignUserWorkStyleForMonth`)と、区分が比例から通常になり、
       日数がルールどおりに変わる。
    7. 区分の判定に使う属性(`weekly_scheduled_days`/`annual_scheduled_days`/`is_shift_based`/
       `agreed_scheduled_days_per_year`/`prescribed_weekly_minutes`)を、それぞれ`UpdateWorkStyle`で変えると、
       割り当てられた社員の付与予定が再計算される(dataProvider)。
    8. 無関係な属性(`name`だけ)を変えても、付与予定のイベントは増えない。
    9. 割り当てられた社員がいない勤務形態を更新しても、何も起きない。
    10. 過去の月(当月より前)に1回だけ割り当てて、今もそれが有効な社員も、勤務形態を更新すると再計算される
        (論点2の修正を確かめる)。
  - 確認方法
    - Projection(`paid_leave_schedule_entries`)の値と、`stored_events`の`paid_leave_schedule.*`の件数で見る。
    - 「イベントが増えない」は、操作の前後の件数の比較で確かめる。
- 理由: 旧テスト6本の観点(各トリガー、無関係な変化で発火しない)を、今の実装で意味のある形にした。
  冪等・付与済み・割当なし・過去の割当の境界も加えた。
- 未確定・要確認事項: なし

### 論点2: 勤務形態を更新したときの、再計算の対象社員
- 選択肢:
  - A. 計算側の定義に揃える。「当月以前で最新の割当がこの勤務形態」の社員と、「当月より後の月にこの勤務形態の
    割当がある」社員の両方を対象にする。
  - B. 今のまま(当月以降に割当がある社員だけ)。テストは今の動きを記録する。
- 決定: A。
- 理由: Bでは、勤務形態の週日数を変えても、過去に割り当てたままの社員の付与予定が古い区分・日数のまま残る。
  付与日数を誤る不具合になる。修正はReactorの対象の取り方だけで、小さい。
- 未確定・要確認事項: なし

### 論点3: その他の気になる点の扱い
- 決定
  - APIで勤務形態を更新すると、`prescribed_weekly_minutes`が必須のため、名前だけの変更でも発火する。
    再計算は冪等で、結果は変わらないので直さない。
  - `WorkStyleUpdated`のdocblockを、実装(入力された属性だけ)に合わせて直す。
  - 退職日の設定は再計算のきっかけにならない。今回は扱わない(対象外に書く)。
  - 付与予定の計算が他の文脈のテーブル(勤務形態・割当)を直接読んでいる点(原則15の例外)は、既存の設計で、
    今回は扱わない。
- 理由: テストの復元が目的で、結果に影響しない点まで広げない。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `backend/tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`(新規): 論点1のケース1〜10。
  - 状態はCommand経由で作る。
  - 勤務形態の割当は`AssignUserWorkStyleForMonth`で行う。`weekly_scheduled_days`等、APIで設定できない属性は
    `UpdateWorkStyle` Commandで設定する。
- `RecalculateScheduleOnWorkStyleChangedReactor::onWorkStyleUpdated`: 対象社員を論点2のAに変える。対象の
  取り出しは、Reactor内のprivateメソッドに分ける。docblockの「過去月のみの割当は対象外」を直す。
- `Events/WorkStyleUpdated.php`: docblockを「入力された属性だけ」に直す。
- テストの実行: `cd backend && php artisan test --filter=PaidLeaveSchedule`。

## 受け入れ条件
- 新しいテストファイルの全ケースがPASSする。
- ケース10が、修正前のReactorでは失敗し、修正後にPASSする(実装者が確かめる)。
- `php artisan test --filter=PaidLeaveSchedule`の既存のテストに退行が無い。

## 対象外
- 退職日の設定による再計算。
- APIで名前だけを変えたときの不要な発火の抑止。
- 付与予定の計算が他の文脈のテーブルを直接読んでいる点の整理。
- AggregateIdの分離(別の変更セット)、年度はじめ一斉付与・自動判定(`20261021-paid-leave-fiscal-mass-grant-and-auto-assessment`)。

## ドキュメントへの影響
- 変更なし。Reactorの対象の修正は、`docs/09`の「勤務形態の変更で再計算する」という記述の範囲内。
  `docs/17`の`work_style.updated`の記述は、実装時に確認し、「属性一式」と書いてあれば直す。
- `docs/changesets/README.md`: 一覧に追加する。

## モック・アセット
なし

## 実装対象
- `backend/tests/Feature/PaidLeaveSchedule/ScheduleRecalculationReactorTest.php`(新規)
- `backend/app/Domain/PaidLeaveSchedule/Reactors/RecalculateScheduleOnWorkStyleChangedReactor.php`
- `backend/app/Domain/Attendance/Events/WorkStyleUpdated.php`(docblockのみ)

## 検証方法
- `cd backend && php artisan test --filter=PaidLeaveSchedule`

## レビュー履歴
初版(2026-10-21)

## 実装結果
未着手
