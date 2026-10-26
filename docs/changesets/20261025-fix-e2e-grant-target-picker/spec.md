# e2e(scenario-16)で付与対象の社員を選べない不安定さの修正

ステータス: 完了

## 変更要望(原文)

```
このタスクをここで実行してください：「Fix MultiUserPicker options outside viewport」
```

(タスクの内容:有給付与ポリシー画面の「付与対象」の複数選択の候補が、Playwrightで「ビューポート外」と判定されてクリックできず、
scenario-16が`dispatchEvent('click')`で回避している。部品の位置の問題なら直し、scenario-16を`pickUser`に戻す。)

## 背景・目的

scenario-16は、付与対象の社員を共通の操作`pickUser`で選べず、`dispatchEvent('click')`で回避している。原因を確かめ、回避をやめる。

## 現状(As-Is)

- `frontend/src/components/MultiUserPicker/MultiUserPicker.tsx`:Radix Popover(Portal、`position: fixed`)+cmdk。構造は単一選択の`UserPicker`と同じ。
- `frontend/e2e/support/ui.ts`の`pickUser`:欄をクリック→検索語を入力→候補をクリック。
- `frontend/e2e/scenario-16-paid-leave-grant-revocation.spec.ts` 109〜115行:`/admin/paid-leave`を開いた直後に欄をクリックし、候補へ`dispatchEvent('click')`。
- 有給付与ポリシー画面(`frontend/src/pages/paidLeave/PaidLeavePolicyPage.tsx`)は、上から`PaidLeaveGrantRulesCard`(ルールごとに対象社員の一覧を非同期で読む
  `PaidLeaveGrantRuleTargetUsersSection`を含む)・`ManualGrantCard`(付与対象の欄)・`PaidLeaveUsageCard`の順。読み込み中は`LoadingState`
  (`role="status"`、見えない文言「読み込み中...」)を出す。

## 設計前提の検証(調査結果)

- 実機での再現と測定(1280×720、Playwright):
  - 画面の読み込みを待ってから欄を開くと、候補は画面内(上から約382px)に出て、`pickUser`でクリックできた。
  - scenario-16の中(ページを開いた直後に欄を開く)では、欄の位置は同じ(ページ上の絶対位置は約2930px)なのに、スクロール位置が1121pxで、
    欄が画面の下端より約1000px下にあった。候補は欄に付いて画面外に出ており、`position: fixed`のため、Playwrightがページをスクロールしても
    位置が変わらず「ビューポート外」が続いた。
  - scenario-16で`waitForLoadState('networkidle')`を挟むと、`pickUser`のまま2回続けてPASSした。
- 結論:欄を開いた後に、上のカード(付与ルールの対象社員の一覧など)の読み込みが終わって欄が下へ押し出されたことが原因。
  部品(`MultiUserPicker`)の位置の計算の不具合ではない。
- 実際の利用者への影響:読み込み中に欄を開いた場合だけ起き、スクロールすれば候補は欄に付いてくる。影響は小さい。
- 原因の分類:e2eの手順のミス(読み込みの完了を待たずに操作している)。

## 仕様検討

### 論点1: どこで直すか
- 選択肢:
  - A. 部品を直す(例:欄が画面外に出たら候補を閉じる)。原因が部品ではないため、対症療法になる。
  - B. scenario-16で、画面の読み込みが終わってから付与対象の欄を開く。
  - C. `pickUser`に「候補が画面外なら閉じて開き直す」再試行を入れる。原因(読み込み中の操作)を隠す。
- 決定: B
- 理由: 原因はe2eの手順。待つ条件は`networkidle`(Playwrightが非推奨)ではなく、画面上の読み込み中の表示が無くなること
  (`LoadingState`の「読み込み中...」が0件)にする。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

- `frontend/e2e/scenario-16-paid-leave-grant-revocation.spec.ts`:
  - `/admin/paid-leave`を開いた後、`await expect(adminPage.getByText('読み込み中...')).toHaveCount(0)`(実装時に、この文言が付与ルールのカードの
    読み込み中に出ることを確かめる。出ないなら、付与ルールの対象社員の一覧が表示し終わったことを示す要素を待つ)で読み込みの完了を待つ。
  - その後`pickUser(adminPage, '付与対象', SCENARIO_USERS.accountingStaff, APPLICANT_EMAIL, { exact: true })`で選ぶ。
    `dispatchEvent('click')`・Escape・回避の説明のコメントを削除する。選択結果の`expect`(listitem)は残す。
- 部品(`MultiUserPicker`)・`pickUser`は変えない。

## 受け入れ条件

- scenario-16が`pickUser`で付与対象を選び、続けて2回PASSする。
- scenario-16に`dispatchEvent`が残っていない。

## 対象外

- `MultiUserPicker`・`UserPicker`の変更。
- ほかのe2eの同じ種類の待ち(今回は失敗していない)。

## ドキュメントへの影響

変更なし(e2eの手順の修正のみ)。

## モック・アセット

なし

## 実装対象

- `frontend/e2e/scenario-16-paid-leave-grant-revocation.spec.ts`

## 検証方法

- backend・mock-oidc・frontendを起動し、`npx playwright test --config e2e/playwright.config.ts scenario-16`を2回。
- `npx tsc -p e2e/tsconfig.json --noEmit`

## レビュー履歴

- 初版。原因の調査(再現・位置の測定・待ちを入れた確認)を委譲元が行い、ユーザーの依頼(タスクの実行)をもって実装中にした。

## 実装結果

- `frontend/e2e/scenario-16-paid-leave-grant-revocation.spec.ts`:`/admin/paid-leave`を開いた後に`getByText('読み込み中...')`が0件になるのを待ち
  (付与ルール・対象社員の一覧・法定付与表の`LoadingState`が出す文言)、`pickUser`で付与対象を選ぶ形に戻した。`dispatchEvent('click')`とEscapeを削除。
- 検証:scenario-16を3回(委譲先で2回、委譲元で1回)流し、すべてPASS(約32〜34秒)。`npx tsc -p e2e/tsconfig.json --noEmit`エラー無し。
- 受け入れ条件:`pickUser`で選んで続けてPASS・`dispatchEvent`の残存無し(差分で確認)。
