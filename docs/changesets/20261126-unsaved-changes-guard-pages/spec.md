# unsaved-changes-guard-pages(ページのフォームの離脱警告)

ステータス: 検討中

## 変更要望(原文)
変更候補の判断 `docs/changesets/candidates-20261124.md` の #7:
> 離脱警告が4画面だけ(§2.9) → ページの作成・編集フォームに当てる(Dialog は対象外)

## 背景・目的
入力の途中で別の画面へ移ると、保存していない入力が黙って失われる。§2.9 は「Browser Back、他ページへの Navigation、Cancel、実装可能な範囲でのタブクローズ」で警告し、「変更が無い場合は警告しない」と定めるが、守られていない。

## 現状(As-Is)(調査 2026-11-26、investigator)
- `hooks/useUnsavedChangesGuard.ts`(`active: boolean`): `beforeunload` だけを登録する。止まるのはタブを閉じる・再読込だけで、アプリ内の移動(リンク・`navigate`・ブラウザの戻る)は止まらない。
  - 理由(コードのコメント): `main.tsx` が `BrowserRouter`(データルーターではない)のため `useBlocker` が使えない。react-router-dom は 7.18。
- 使っている画面: 利用者詳細・ロール詳細・カレンダーの基本設定・カレンダーの日別編集・有給の計画表(棚卸し外)・勤怠の日(編集・作成・計算の補正)。
  - 勤怠の日の編集(`DayEditForm`)と計算の補正(`CalculationAdjustForm`)は `useUnsavedChangesGuard(true)` で、変更が無くても警告する(§2.9 に反する)。
- ページのフォームはすべて `useState`(フォームのライブラリは無い)。変更の有無(dirty)を持つのは上の画面だけ。
- 保存の後に別の画面へ移るフォームが多い(作成後に詳細へ、編集後に一覧・戻り先へ)。
- 単体テストは `MemoryRouter`(データルーターではない)を使う。80ファイル。
- E2E に離脱警告・`dialog` の扱いは無い。

## 設計前提の検証
- 正データと派生データ: 画面の振る舞いだけ。データ・API に関わらない。
- 定義と実態: 規則(§2.9)はアプリ内の移動も対象にしているが、ルーターの制約で実装できていない。ルーターの変更を選択肢に含める。
- 横展開: 対象はページの作成・編集・設定のフォーム。Dialog・Sheet は対象外(ユーザーの判断)。

## 仕様検討

### 論点1: アプリ内の移動を止める仕組み
- 選択肢:
  - A. 今のまま(`beforeunload` だけ)。アプリ内の移動は止めない。
  - B. ルーターをデータルーターにして `useBlocker` を使う。ルートの定義(`App.tsx` の `<Routes>`)は書き換えず、`createBrowserRouter([{ path: '*', element: <Root /> }])` で今の `<App />` を包む(子孫の `<Routes>` はそのまま動く)。
  - C. リンクのクリックと `navigate` を独自に横取りする。
- 決定: B。
- 理由: A は要望(アプリ内の移動で入力が失われる)を解決しない。C は `navigate` の呼び出し・ブラウザの戻るを漏れなく捕まえられない。B は react-router の正式な仕組みで、ルート定義の移行をせずに使える。
- 未確定・要確認事項: なし

### 論点2: hook の形と、単体テストへの影響
- 決定:
  - アプリの根に `UnsavedChangesProvider` を置き、ここで `useBlocker` を1回だけ呼ぶ。各ページは今と同じ `useUnsavedChangesGuard(active)` を呼び、Provider に「変更あり」を登録する。
  - Provider が無い(単体テストの `MemoryRouter`)ときは、hook は今と同じ `beforeunload` だけを行う。80ファイルのテストは変えない。
  - hook は `allowNavigation()` を返す。保存に成功して `navigate` する直前に呼ぶと、その1回の移動は止めない(保存後の移動で警告が出ないように)。
- 理由: `useBlocker` はデータルーターの外で呼ぶと例外になる。Provider に集めれば、ページ側の書き方を変えず、テストも今のまま動く。`useBlocker` を画面ごとに呼ぶと複数の blocker が衝突する。
- 未確定・要確認事項: なし

### 論点3: 警告を出す条件と確認の画面
- 決定:
  - 止める移動: パス(pathname)が変わる移動(リンク・`navigate`・ブラウザの戻る/進む)。同じパスの中でクエリだけが変わる移動(タブ・絞り込み)は止めない。
  - 確認はアプリの確認 Dialog(`ConfirmActionDialog`)。タイトル「保存していない変更があります」、本文「このページを離れると、入力した内容は失われます。」、ボタン「ページを離れる」(destructive)・「編集を続ける」。
  - タブを閉じる・再読込は今と同じ `beforeunload`(ブラウザ標準の確認)。
  - キャンセルのボタン: 編集中にキャンセルで戻る先へ移るときも同じ確認を出す(§2.9 の「Cancel」)。キャンセルは `navigate` なので、論点1の仕組みでそのまま止まる。
- 理由: クエリだけの移動まで止めると、タブの切り替えのたびに確認が出る(フォームが消えない場合も多い)。
- 未確定・要確認事項: なし

### 論点4: 対象の画面と変更の有無の判定
- 決定: 棚卸し(`20261123-form-layout-standard/inventory.md`)のページのうち、作成・編集・設定のフォーム。

| 棚卸し | 画面 | 区分 |
|---|---|---|
| P1 | グループの作成・編集(`GroupDetailPage`) | 新規・編集 |
| P2 | ロール詳細 | 既存(移動の警告が加わる) |
| P3 | システム設定 | 設定 |
| P5 | 利用者詳細 | 既存 |
| P8・P9 | 備品の編集・登録 | 編集・作成 |
| P11・P12・P13 | 勤怠の日の編集・作成・計算の補正 | 既存(P11・P13 は dirty の判定を足す) |
| P17 | 初回設定 | 設定 |
| P20 | 経費の申請 | 作成 |
| P21・P22 | 経費区分・入力プリセット | 新規・編集 |
| P23 | 有給の付与ルール | 編集 |
| P24 | 勤務カレンダーの作成 | 作成 |
| P25・P27 | カレンダーの基本設定・日別編集 | 既存 |
| P29 | 働き方の設定(一般設定の部分) | 設定 |
| P30 | 申請種別 | 新規・編集 |
| P31 | 申請の作成 | 作成 |

- 対象外: 実行・出力・状態変更・一括操作・インライン(P4 の人事データの取込・P6・P7・P10・P14〜P16・P18・P19・P26・P28)。入力が1〜2項目で、失っても入れ直しが容易なため。有給の計画表(棚卸し外)は今のまま。
- 変更の有無: 読み込んだ値(新規なら初期値)と今の入力を比べる。比べる関数(`isXxxDirty(initial, current)`)を画面の外に出し、テストで直接呼べるようにする。保存に成功したら比べる元を保存した値にする(または `allowNavigation()` を呼んでから移る)。
- 同じページで即時に保存される操作(行の追加・削除、Dialog の操作)は dirty に含めない。
- 未確定・要確認事項: なし

### 論点5: E2E への影響
- 決定: 入力の後に保存せずに別の画面へ移る spec があると、確認の Dialog で止まる。実装の後、対象の画面を通る spec を grep で特定してそれだけを流し、止まったところは「保存する」「ページを離れる」を押す操作をテストに足す(テストの意図が離脱なら「ページを離れる」)。
- 新しい E2E は足さない(確認の出る・出ない・保存後に出ないは単体テストで確かめる)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `main.tsx`: `BrowserRouter` を `createBrowserRouter([{ path: '*', element: <Root /> }], { basename })` + `RouterProvider` にする。`Root` は今の Provider 群と `<App />`。`App.tsx` の `<Routes>` は変えない。
- `hooks/useUnsavedChangesGuard.ts`:
  - `UnsavedChangesProvider`(`useBlocker` を1回。登録された「変更あり」が1つでもあり、パスが変わり、`allowNavigation` されていないときに止める。止めたら確認の Dialog を出し、「ページを離れる」で `proceed`、「編集を続ける」で `reset`)。
  - `useUnsavedChangesGuard(active): { allowNavigation(): void }`。`beforeunload` は今のまま。Provider が無ければ blocker は使わない。
- Provider を `Root` に置く(`AppLayout` の外でも効くように)。
- 論点4の各画面: dirty の判定関数を作り、hook を呼び、保存成功の後の `navigate` の前に `allowNavigation()` を呼ぶ。P11・P13 の `true` 固定をやめる。
- テスト:
  - hook と Provider: `createMemoryRouter` で、変更あり→リンクで止まり Dialog が出る/「ページを離れる」で移る/「編集を続ける」で留まる/変更なし→止まらない/`allowNavigation` の後→止まらない/クエリだけの移動→止まらない/Provider なし→例外にならない。
  - 各画面: dirty の判定関数の単体テスト(変更なし・1項目の変更・元に戻すと変更なし)。
- スキル: `ui-interaction-patterns` §2.9 に仕組み(`useUnsavedChangesGuard` と `allowNavigation`、対象はページの作成・編集・設定、Dialog は対象外、クエリだけの移動は止めない)を書く。`add-page` に1行足す。

## 受け入れ条件
- 対象の画面で、入力を変えた後にリンク・ブラウザの戻る・キャンセルで別の画面へ移ろうとすると、確認の Dialog が出る。「ページを離れる」で移り、「編集を続ける」で入力が残る。
- 変更が無いとき、保存に成功した後の移動、同じ画面のクエリだけの移動では出ない。
- タブを閉じる・再読込は、変更があるときだけブラウザの確認が出る(今と同じ)。
- 勤怠の日の編集・計算の補正で、変更が無いのに警告が出ない。
- hook・Provider・dirty の判定のテストがあり、単体テスト全件が通る。対象の画面を通る E2E の spec が通る。

## 対象外
- Dialog・Sheet の離脱警告
- 論点4の対象外の画面
- 有給の計画表の詳細パネルの `window.confirm`
- ルート定義をデータルーターの形(オブジェクトの配列・loader)へ移すこと

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.9、`.claude/skills/add-page/SKILL.md`
- docs/: 変更なし(画面の振る舞いのみ)

## モック・アセット
なし

## 実装対象
- `frontend/src/main.tsx`、`frontend/src/hooks/useUnsavedChangesGuard.ts`(+テスト)
- 論点4の画面(`frontend/src/pages/**`)と、dirty の判定関数のテスト

## 検証方法
- `npx tsc -p tsconfig.app.json --noEmit`、`npx vitest run --project unit`(全件)
- E2E: 対象の画面を通る spec を grep で特定して流す。

## 実装の順序
変更セット`20261126-form-field-aria` → 本変更(ルーターと hook は先行してよい)→ `20261126-field-errors-near-fields`。本変更と `field-errors-near-fields` は同じ画面のファイルに触れるので、並行させない。

## レビュー履歴
初版

## 実装結果
未着手
