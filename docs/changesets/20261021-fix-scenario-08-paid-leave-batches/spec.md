# e2eのscenario-08で失敗している有給バッチの2件を直す

ステータス: 実装中

## 変更要望(原文)
> シナリオのエラーの修正もこの作業の一環として対応をお願いします。
> 問題があれば教えてください。

(対象: `frontend/e2e/scenario-08-fiscal-year-cycle.spec.ts`の「境界条件の単発確認」で失敗している2件。
変更前の`259c8fa`でも同じように失敗していた)

## 背景・目的
有給の付与予定・同日付与・年度はじめの一斉付与の一連の変更(`20261021-*`)の検証で、scenario-08の2件が失敗した。
どちらもe2eの側が古いのが原因で、アプリの不具合ではない。シナリオを今の仕組みに合わせて直す。

## 現状(As-Is)
- 失敗1: `paid-leave:warn-five-day-obligation: 年5日未取得の付与に警告が出る`(420行付近)
  - 対象の社員は管理者(`SCENARIO_USERS.admin`)。`granted_on`=今日−305日の付与を手動付与のAPIで作る。
  - 直前の`warn-expiring`のテスト(395行付近)が、同じ管理者に`granted_on`=昨日の付与を作っている。
  - 有給口座は、最新の付与より前の日付の付与を拒否する(過去の日付は不可。同日は可)ため、422になる。
  - 2件のテストが同じ社員を使い、実行の順に依存していることが原因。
- 失敗2: `paid-leave:grant-scheduled: 継続勤務の記念日にコマンドがエラー無く完走する`(446行付近)
  - `php artisan paid-leave:grant-scheduled`を呼んでいる。
  - このコマンドは削除済み(PR#109の`d6d826b`で`paid-leave:roll-schedules`に置き換え)。
  - 今の仕組みでは、付与は管理者の一括付与。付与予定は`paid-leave:roll-schedules`が作り、出勤率は
    `paid-leave:assess-schedules`が判定する。
- 古い記述
  - `docs/testing/scenario-tests.md`シナリオ6の「技術的な前提」と手順4(263行、299行付近)
  - `frontend/e2e/README.md`(77行、141行付近)
  - `scenario-08`の説明のコメント(380行付近)
  - `scenario-99-additional.spec.ts`のコメント(344行付近)

## 設計前提の検証
- 正データと派生データ: e2eはAPIとartisanのコマンドだけを使い、データを直接書かない。変更しない。
- 定義と実態: シナリオの手順4は、自動付与(`grant-scheduled`)がある前提で書かれている。今は自動付与が無く、
  「付与予定の作成→判定→管理者の一括付与」の流れ。シナリオの定義を、実態に合わせる。
- 原因の分類
  - 失敗1: テストの作りのミス。社員の共有と、実行の順への依存。
  - 失敗2: 仕組みの変更にシナリオが追従していない。

## 仕様検討

### 論点1: 5日取得義務の警告のテストの対象の社員
- 選択肢:
  - A. 5日取得義務の警告のテストを、失効の警告のテストより前に置く(順を入れ替える)。
  - B. 5日取得義務の警告のテストに、別の社員を使う。他のシナリオ・テストで、今日−305日より後の日付の有給を
    付与されない社員にする。
- 決定: B。
  - 実装者が、`ScenarioSeeder`と他のe2e(手動付与・一括付与を行う箇所)をgrepして、条件に合う社員を選ぶ。
  - 選んだ理由を、テストのコメントに書く。
  - 条件に合う社員がいなければ、止めて報告する。
- 理由: Aは、ほかのシナリオが先に同じ社員に付与すると、また壊れる。社員を分ければ、順に依存しない。
- 未確定・要確認事項: なし

### 論点2: 継続勤務の記念日のテスト
- 選択肢:
  - A. テストを消す。
  - B. 今の流れの疎通確認に置き換える。
    - 入社日を今日のちょうど6か月前にし、`paid-leave:roll-schedules`→`paid-leave:assess-schedules --trigger=manual`を実行する。
    - どちらもエラー無く完走し(終了コード0)、出力の件数が出ることを確かめる。
- 決定: B。
  - テスト名は「paid-leave:roll-schedules・assess-schedules: 継続勤務の記念日の付与予定が作られ、判定がエラー無く完走する
    (疎通確認)」。
  - 判定の結果(付与対象・要確認など)は確かめない。勤怠データが無いので、判定は利用開始日などの規則で要確認になりうる。
    PHPUnitの`AssessPaidLeaveSchedulesCommandTest`でカバー済み。
  - 付与(一括付与)は画面の操作なので、scenario-15でカバー済み。
- 理由: シナリオ6の手順4の目的(実時刻に依存するバッチがエラー無く動くこと)は、今の仕組みでも成り立つ。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `frontend/e2e/scenario-08-fiscal-year-cycle.spec.ts`
  - 5日取得義務の警告のテストの対象を、論点1の社員にする。
  - 記念日のテストを、論点2のとおり置き換える。
  - 説明のコメント(380行付近)の`grant-scheduled`を直す。
- `frontend/e2e/scenario-99-additional.spec.ts`: 344行付近のコメントの`grant-scheduled`を直す。
- `frontend/e2e/README.md`: 77行・141行付近の`grant-scheduled`を直す。
- `docs/testing/scenario-tests.md`シナリオ6
  - 「技術的な前提」から`grant-scheduled`を外し、`roll-schedules`・`assess-schedules`にする。
  - 手順4の「年次自動付与」を、論点2の内容に書き換える(自動付与は無く、付与は管理者の一括付与であること)。
  - `PaidLeaveScheduledBatchTest.php`は改名済み(`PaidLeaveWarningBatchTest.php`)なので直す。

## 受け入れ条件
- `npm run test:e2e -- scenario-08-fiscal-year-cycle`が全件PASSする。
- 続けて`scenario-00`・`03`・`15`も実行し、PASSする(社員の選び方で他のシナリオと衝突していないこと)。
- `grant-scheduled`の記述が、docs/testing・frontend/e2eに残っていない(`special-leave:grant-scheduled`は別のコマンドなので除く)。

## 対象外
- アプリのコードの変更。
- シナリオ6の手順1〜3(12か月の勤怠サイクル)。

## ドキュメントへの影響
- `docs/testing/scenario-tests.md`(上記)
- `frontend/e2e/README.md`
- `docs/changesets/README.md`

## モック・アセット
なし

## 実装対象
- `frontend/e2e/scenario-08-fiscal-year-cycle.spec.ts`、`frontend/e2e/scenario-99-additional.spec.ts`、`frontend/e2e/README.md`
- `docs/testing/scenario-tests.md`

## 検証方法
- `npx tsc --noEmit -p e2e/tsconfig.json`
- `npm run test:e2e -- scenario-08-fiscal-year-cycle`、続けて`scenario-00-master-data scenario-03-paid-leave scenario-15-paid-leave-schedule`

## レビュー履歴
初版(2026-10-21)

## 実装結果
未着手
