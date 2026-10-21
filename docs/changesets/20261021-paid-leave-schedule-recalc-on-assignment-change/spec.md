# 勤務形態の割当の削除・月替わりで付与予定を再計算する

ステータス: レビュー中

## 変更要望(原文)
> このタスクをここで実行してください:「勤務形態の割当の削除・月替わりで付与予定を再計算する」

(タスクの内容: 勤務形態の割当を削除したときと、先の月に登録した割当が月替わりで有効になったときに、付与予定が
再計算されず、古い区分・日数のまま残る。この2つの経路で再計算されるようにする。
`20261021-paid-leave-schedule-reactor-tests`の対象外から切り出したもの)

## 背景・目的
付与予定の計算は、「当月以前で最新の勤務形態の月次割当」を社員の勤務形態とみなし、区分と日数を決める
(`ScheduleCandidateGenerator::currentWorkStyleFor`)。次の2つの場合に、社員の勤務形態が変わるのに付与予定が直らない。
1. 割当を削除したとき
2. 先の月の割当が、月が変わって有効になったとき

この2つの経路で、付与予定を再計算する。

## 現状(As-Is)
- 割当の削除
  - イベントは`UserWorkStyleMonthlyAssignmentRemoved`(別名`user_work_style_monthly_assignment.removed`)。
    payloadは`userId`/`yearMonth`/`previousWorkStyleId`/`removedByUserId`。
  - 発行元は`DELETE /user-work-style-monthly-assignments/{id}`→`RemoveUserWorkStyleMonthlyAssignmentHandler`。
    当月より前の月は削除できない(既定のタイムゾーンで判定)。
  - Projectorは行を消す。同じイベントのReactorより先に動く。
  - 購読するReactorが無い。テストも無い。
- 月替わり
  - 毎日のcron`paid-leave:roll-schedules`(`RollPaidLeaveSchedulesCommand`)の対象は、在籍中・自動付与が有効・
    入社日ありの社員。
  - 社員ごとに、今日から1年先までの候補を作り、`EnsureFutureScheduleGenerated`を出す。これは足りない日付の行を
    作るだけで、既存の行は変えない。候補が無い社員には何も出さない。
  - そのため、先の月に登録した割当が有効になっても、既存の行は直らない。
- 再計算(`RecalculateFutureSchedule`、`fromDate`が必須)の性質
  - 内容(区分・日数)が同じ行には、何も記録しない(冪等)。
  - 個別修正の行は守る(`overrideManualEdits=false`)。
  - 付与済みの行は変えず、同じ日の候補を消費する。
  - `fromDate`より前の行には触れない。
  - 候補が無ければ、未確定の行を取り消す。
  - 全社員に対する再計算の前例は、`ReapplyPaidLeaveSchedulePolicyJob`。
- 「今日」は、`Carbon::today()`(アプリのタイムゾーンでUTC)。削除のHandlerは既定のタイムゾーン(Asia/Tokyo)を使い、
  基準が食い違っている。

## 設計前提の検証
- 正データと派生データ: 勤務形態の割当は勤怠の文脈の正データ。付与予定の文脈は、そのイベントにReactorで反応する
  (原則15)。cronからの再計算も、付与予定のCommand(`RecalculateFutureSchedule`)を出すだけ。
- 定義と実態: 付与予定の計算(当月以前で最新の割当)と、再計算のきっかけ(登録・勤務形態の更新だけ)が合っていない。
  削除と月の経過も、計算の結果を変える。
- 原因の分類: 実装の漏れ(きっかけの不足)。

## 仕様検討

### 論点1: 割当の削除
- 選択肢:
  - A. 削除のイベントを購読して、その社員を再計算する。
  - B. 毎日のcron(論点2)に任せる。翌日まで直らない。
- 決定: A。
  - `RecalculateScheduleOnWorkStyleChangedReactor`に`onUserWorkStyleMonthlyAssignmentRemoved`を足す。
  - 削除した月が当月以前なら再計算する(当月より後の月の割当は、今の計算の結果を変えない)。
  - 再計算の内容は既存の`recalculate`と同じ(今日から1年先、`fromDate`=今日)。
- 理由: 削除はすぐに付与予定へ反映したい。Projectorが先に行を消すので、Reactorの時点で計算は削除後の状態になる。
- 未確定・要確認事項: なし

### 論点2: 月替わり
- 選択肢:
  - A. 毎日のcronで、当月の割当が前月までの最新の割当と違う社員を見つけて、再計算する。
  - B. 毎日のcron`roll-schedules`を、「足りない行を作る」から「再計算」に変える。
  - C. 月初だけ、全社員を再計算する。
- 決定: B。
  - `roll-schedules`は、対象の社員ごとに`RecalculateFutureSchedule`(`fromDate`=今日、`overrideManualEdits=false`)を出す。
  - 候補が無い社員にも出す(未確定の行は取り消される。Reactorと同じ動き)。
  - 1社員の失敗で全体を止めないのは、今どおり。
- 理由
  - 再計算は冪等で、変化が無い社員には何も記録しない。処理量は、今の`Ensure`(社員ごとに集約を読む)と同じ。
  - 月替わりだけでなく、きっかけの漏れ全般が、翌日には直る。
  - Aは、検出のクエリが割当の削除と組み合わさったときに漏れやすい。Cは、月の途中の漏れを直せない。
- 注意: 候補が無い社員(付与ルールに合わない等)の未来の行は、毎日の実行で取り消されるようになる。今はReactorが
  動いたときだけ取り消されていた。ルールに合わない社員の予定は残す理由が無いので、この動きを仕様にする。
- 未確定・要確認事項: なし

### 論点3: 「今日」のタイムゾーン
- 決定: 今回は変えない(対象外)。
  - `roll-schedules`は02:00 UTC(日本時間11:00)に動くので、月替わりの判定は日本時間の日付と一致する。
  - Reactorの`Carbon::today()`がUTCなのは、日本時間の月初0:00〜8:59に割当を変えた場合だけ、前月の判定になる。
    翌日の`roll-schedules`で直る。
- 理由: 年度はじめ一斉付与の変更セットで、付与予定の文脈の日付を既定のタイムゾーンに揃える。そこで一緒に直す。
- 未確定・要確認事項: なし

### 論点4: 設計レビューで決めた点(論点1〜3を補う。食い違うときはこちらを正とする)
- `roll-schedules`の`fromDate`は、今日ではなく明日にする。
  - 年度はじめの一斉付与の変更セットでは、毎月1日の朝に判定する。
  - 同じ日の`roll-schedules`(日本時間11:00)が当日の行を置き換えると、その日に付けた判定が消える。当日の行は、
    `roll-schedules`では触らない。
  - Reactor(きっかけがはっきりしている再計算)は、今日のまま。
- 割当の削除のReactorには、条件を付けない(論点1の「当月以前なら」をやめる)。
  - 当月より前の削除は、Handlerがそもそも拒否する。
  - 再計算は冪等なので、当月より後の削除でも結果は変わらない。
  - 「当月より後の削除でイベントが増えない」テストは、冪等性の確認として残す。
- ルールの選択の順を、この変更セットで決める(年度はじめの一斉付与の変更セットの論点4から前倒しする)。
  - `matchingRuleFor`は、勤務形態のルール→全社ルールの順に選ぶ。同じ種類が複数あれば、`created_at`→`id`の順。
  - 毎日の再計算で、選ばれるルールが日によって変わり、置き換えが出続けるのを防ぐ。
- 毎日の再計算で取り消されるものを、仕様として書く(docs/09)。
  - 付与ルールを一時的に無効にした場合の、候補が無い社員の未来の行。個別修正の行は守る。
  - `paid-leave:schedule:rebuild --to`で作った、1年より先の行。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `RecalculateScheduleOnWorkStyleChangedReactor::onUserWorkStyleMonthlyAssignmentRemoved`(論点1)。
- `RollPaidLeaveSchedulesCommand`: `EnsureFutureScheduleGenerated`の代わりに`RecalculateFutureSchedule`を出す。候補が
  無い社員にも出す(論点2)。
  - `EnsureFutureScheduleGenerated`のCommand・Handlerは、ほかの利用者が無くなれば残すか消すかを実装時に確かめる。
    使われていなければ消す。
- `routes/console.php`と`docs/09` UC-P011の、`roll-schedules`の説明を「再計算」に直す。
- テスト(`ScheduleRecalculationReactorTest`に足す。既存のヘルパーを使う)
  - 当月の割当(週5日)を削除し、前月の割当(週3日)が有効になると、区分が通常から比例になり、日数が変わる。
  - 当月より後の月の割当を削除しても、付与予定のイベントは増えない。
  - 先の月(2026-11)に週5日の割当を登録しておき(10月の時点では週3日)、`setTestNow('2026-11-01')`で
    `roll-schedules`を実行すると、区分・日数が変わる。
  - 変化が無い日の`roll-schedules`では、付与予定のイベントが増えない。
  - 個別修正の行は、`roll-schedules`の再計算でも残る。
  - 候補が無くなった社員(自動付与は有効のまま、合うルールを無効にする)は、`roll-schedules`で未確定の行が取り消される。
  - `RollPaidLeaveSchedulesCommandTest`の既存の7件が、期待どおり通る(2回実行で重複しないことを含む)。

## 受け入れ条件
- 当月以前の割当の削除で、すぐに付与予定が再計算される。
- 先の月の割当が有効になった日の`roll-schedules`で、付与予定が再計算される。
- 変化が無ければ、付与予定のイベントが増えない。個別修正の行は守られる。
- 上のテストと、既存の`php artisan test --filter=PaidLeaveSchedule`がPASSする。
- `docs/09`・`routes/console.php`の説明が実装と一致する。

## 対象外
- 「今日」のタイムゾーンの統一(年度はじめ一斉付与の変更セット)。
- 退職による付与予定の取消(`roll-schedules`は在籍中の社員だけが対象。退職者の未来の行は残る)。
- 付与予定の計算が、既定の勤務形態(割当の無い社員)を見ない点(勤怠側の`WorkStyleFallbackResolver`とは定義が違う)。

## ドキュメントへの影響
- `docs/09-usecases-paid-leave.md`
  - UC-P011の`roll-schedules`を「1年先までの再計算」に直す。
  - 再計算のきっかけ(割当の削除)を足す。
- `docs/changesets/README.md`

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/PaidLeaveSchedule/Reactors/RecalculateScheduleOnWorkStyleChangedReactor.php`
- `backend/app/Console/Commands/RollPaidLeaveSchedulesCommand.php`、`backend/routes/console.php`(コメント)
- `backend/tests/Feature/PaidLeaveSchedule/`(`ScheduleRecalculationReactorTest`、`RollPaidLeaveSchedulesCommandTest`)
- `docs/09-usecases-paid-leave.md`

## 検証方法
- `cd backend && php artisan test --filter=PaidLeaveSchedule`

## レビュー履歴
初版(2026-10-21)

- 2026-10-21 独立設計レビュー(同等モデル)の指摘を、論点4として反映した。
  - `fromDate`を明日にした。
  - 削除のReactorの条件をやめた。
  - ルールの選択の順を前倒しした。
  - 毎日取り消されるものを明記した。
  - ドキュメントへの影響を足した: docs/09:39-43,110,513-515、`docs/testing/scenario-tests.md:434-443`、`RollPaidLeaveSchedulesCommand`のdocblock。

## 実装結果
未着手
