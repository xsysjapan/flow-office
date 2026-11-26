# unsaved-changes-guard-pages(ページのフォームの離脱警告)

ステータス: 完了

## 変更要望(原文)
変更候補の判断 `docs/changesets/candidates-20261124.md` の #7:
> 離脱警告が4画面だけ(§2.9) → ページの作成・編集フォームに当てる(Dialog は対象外)

## 背景・目的
入力の途中で別の画面へ移ると、保存していない入力が黙って失われる。§2.9 は「Browser Back、他ページへの Navigation、Cancel、実装可能な範囲でのタブクローズ」で警告し、「変更が無い場合は警告しない」と定めるが、守られていない。

## 現状(As-Is)(調査 2026-11-26、investigator と設計レビューの検証)
- `hooks/useUnsavedChangesGuard.ts`(`active: boolean`): `beforeunload` だけを登録する。止まるのはタブを閉じる・再読込だけで、アプリ内の移動(リンク・`navigate`・ブラウザの戻る)は止まらない。
  - 理由(コードのコメント): `main.tsx` が `BrowserRouter`(データルーターではない)のため `useBlocker` が使えない。react-router-dom は 7.18。
- 使っている画面: 利用者詳細・ロール詳細・カレンダーの基本設定・カレンダーの日別編集・有給の計画表(棚卸し外)・勤怠の日(編集・作成・計算の補正)。
  - 勤怠の日の編集(`DayEditForm`)と計算の補正(`CalculationAdjustForm`)は `useUnsavedChangesGuard(true)` で、変更が無くても警告する(§2.9 に反する)。
- ページのフォームはすべて `useState`(フォームのライブラリは無い)。変更の有無(dirty)を持つのは上の画面だけ。
- 移動の形(設計レビューで確認):
  - 保存の後に別の画面へ移るフォームが多い(作成後に詳細へ、編集後に一覧・戻り先へ)。保存の処理の中で続けて `navigate` する(例: `GroupDetailPage.tsx:236-254` は作成の後に役割の割当を await してから移る)。
  - クエリだけの移動でフォームが入れ替わる画面がある: ロール詳細はロールの切り替えが `?roleId=` の置き換えで、入力を新しいロールの値で上書きする(`RoleDefinitionPage.tsx:57,85-93,386-395`)。経費の申請は手順を `?mode=`・`?category=` で持つ(`ExpenseClaimNewPage.tsx:540-565`)。
  - パスが変わっても同じ部品が使い回される: `/attendance/days/:date` の前日・翌日(`AttendanceDayPage.tsx:1326-1346`、`key` が無く編集中の state が次の日に残る)、`/admin/groups/new` → `/admin/groups/:id`(`GroupDetailPage`)。
  - ページの中で表示を切り替えるだけのキャンセルがある: 勤怠の日の編集・計算の補正のキャンセルは `setIsEditing(false)` 等で、`navigate` ではない(`AttendanceDayPage.tsx:1543,1441`)。
  - 外部へ移る: 初回設定の SSO の保存の後の `window.location.href`(`OnboardingPage.tsx:59-61`)。
- `useBlocker` は判定関数を `useEffect` で router に渡し直す。そのため、同じ処理の中で state を変えて続けて `navigate` しても、新しい判定は使われない(設計レビューの検証。scratchpad の rr.test.jsx)。
- `createBrowserRouter([{ path: '*', element: <Root /> }], { basename })` の下で、子孫の `<Routes>`・`Navigate`・`Link`・`useNavigate` は今と同じに動く(設計レビューの検証。src に相対パスの移動・`navigate(-1)`・`ScrollRestoration` は無い)。
- `ConfirmActionDialog`: キャンセルの文言は「キャンセル」で固定。ボタンの variant は `danger`(`destructive` は無い)。
- 単体テストは `MemoryRouter`(データルーターではない)を使う。80ファイル。
- E2E: spec 18本のうち12本が対象の画面の URL を含む。`page.goto`・`page.reload` は54か所。離脱警告・`dialog` の扱いは無い。

## 設計前提の検証
- 正データと派生データ: 画面の振る舞いだけ。データ・API に関わらない。
- 定義と実態: 規則(§2.9)はアプリ内の移動も対象にしているが、ルーターの制約で実装できていない。ルーターの変更を選択肢に含める。
- 「離れる」の定義: パスが変わることでも、部品が外れることでもない。「今のフォームの入力が失われる移動」は画面ごとに違う(クエリで入れ替わる画面、パスが変わっても部品が残る画面)。そのため、止める移動の判定を画面が指定できるようにする。
- 横展開: 対象はページの作成・編集・設定のフォーム。Dialog・Sheet は対象外(ユーザーの判断)。

## 仕様検討

### 論点1: アプリ内の移動を止める仕組み
- 選択肢:
  - A. 今のまま(`beforeunload` だけ)。
  - B. ルーターをデータルーターにして `useBlocker` を使う。ルートの定義(`App.tsx` の `<Routes>`)は書き換えず、`createBrowserRouter([{ path: '*', element: <Root />, errorElement: <RootErrorFallback /> }], { basename })` で今の `<App />` を包む。
  - C. リンクのクリックと `navigate` を独自に横取りする。
- 決定: B。
- 理由: A は要望を解決しない。C は `navigate` の呼び出し・ブラウザの戻るを漏れなく捕まえられない。B は react-router の正式な仕組みで、ルート定義を移さずに使える(検証済み)。
- `errorElement`: Root の外側で描画の例外が起きたときに react-router の既定の画面(開発者向けの英語)が出るのを避けるため、「画面の表示中にエラーが発生しました。」と再読込のボタンだけの `RootErrorFallback` を置く。
- 未確定・要確認事項: なし

### 論点2: hook と Provider の形
- 決定:
  - アプリの根(`Root`)に `UnsavedChangesProvider` を置き、ここで `useBlocker` を1回だけ呼ぶ。各ページは `useUnsavedChangesGuard(active, options?)` を呼んで登録する。
  - Provider は、登録(画面ごとの `active` と止める判定)と、許可のフラグを **ref** で持つ。`useBlocker` に渡す判定関数は ref を読む(描画の遅れの影響を受けないように)。
  - hook の返り値:
    - `navigateAfterSave(to, options?)`: 許可のフラグを立てて `navigate` し、`finally` でフラグを戻す。router は `navigate` の呼び出しの中で同期に判定するので、この呼び出しの範囲だけが許可になる。保存に成功した後の移動は必ずこれを使う(比べる元を更新するだけでは止まる。現状を参照)。
    - `leaveWithoutGuard(fn)`: 同じく許可のフラグを立てて `fn` を呼ぶ(外部への移動 `window.location.href = …` に使う)。フラグが立っている間は `beforeunload` も何もしない。
    - `confirmDiscard(): Promise<boolean>`: ページの中のキャンセル(表示の切り替え)の前に呼び、変更があれば確認の Dialog を出す。変更が無い・Provider が無いときはすぐ `true`。
  - 許可のフラグは、判定に1回使ったら戻す(止める判定より先に読んで消す)。
  - Provider が無い(単体テストの `MemoryRouter`)ときは、hook は `beforeunload` だけを行い、`navigateAfterSave` はふつうの `navigate`、`confirmDiscard` は `true` を返す。80ファイルのテストは変えない。
- 理由: `useBlocker` はデータルーターの外で呼ぶと例外になり、画面ごとに呼ぶと blocker が衝突する。state と `useEffect` で判定を渡すと、同じ処理の中の `navigate` に間に合わない(検証済み)。フラグを呼び出しの範囲に閉じれば、途中で失敗したときにフラグが残って次の移動を素通しすることがない。
- 未確定・要確認事項: なし

### 論点3: 止める移動の判定
- 決定:
  - 既定: パス(pathname)が変わる移動を止める。
  - 画面が `options.blockOn` で変えられる:
    - `'location'`: パスかクエリが変わる移動を止める(経費の申請 P20)。
    - 関数 `(current, next) => boolean`: ロール詳細 P2 は `roleId` が変わる移動を止める。
  - パスが変わっても同じ部品が使い回される画面は、ルートの要素(またはページの中のフォームの部品)に `key={パスの変数}` を付け、移動したら入力を初期化する。対象: 勤怠の日(`:date`)、グループ(`new`/`:id`)、ほか論点5の対象のうちパスの変数を持つ画面。これで「ページを離れると入力は失われる」が実際の動きと一致する(勤怠の日の、前の日の入力が次の日に残る今の不具合も直る)。
- 理由: タブ・絞り込みのようにクエリだけが変わってもフォームが残る画面が多いので、既定はパス。入力が失われるクエリの移動は画面ごとに指定する。
- 未確定・要確認事項: なし

### 論点4: 確認の画面と文言
- 決定:
  - アプリ内の移動とページの中のキャンセル: `ConfirmActionDialog`。タイトル「保存していない変更があります」、本文「このページを離れると、入力した内容は失われます。」(ページの中のキャンセルでは「編集をやめると、入力した内容は失われます。」)。確定のボタン「ページを離れる」/「編集をやめる」(`confirmVariant="danger"`)、取り消しのボタン「編集を続ける」。
  - `ConfirmActionDialog` に `cancelLabel?: string`(既定「キャンセル」)を足す。
  - タブを閉じる・再読込: 今と同じ `beforeunload`(ブラウザ標準の確認)。
- 未確定・要確認事項: なし

### 論点5: 対象の画面と変更の有無の判定
- 決定: 棚卸し(`20261123-form-layout-standard/inventory.md`)のページのうち、作成・編集・設定のフォーム。

| 棚卸し | 画面 | 区分 | 保存の後 | 止める判定 |
|---|---|---|---|---|
| P1 | グループの作成・編集 | 新規・編集 | 詳細へ移る | パス(`key`) |
| P2 | ロール詳細 | 既存 | 留まる | `roleId` が変わる |
| P3 | システム設定 | 設定 | 留まる | パス |
| P5 | 利用者詳細 | 既存 | 留まる | パス |
| P8・P9 | 備品の編集・登録 | 編集・作成 | 詳細へ移る | パス |
| P11・P12・P13 | 勤怠の日の編集・作成・計算の補正 | 既存(P11・P13 は dirty を足す) | 留まる(表示を戻す) | パス(`key`)、キャンセルは `confirmDiscard` |
| P17 | 初回設定 | 設定 | `/` または外部へ | パス、外部は `leaveWithoutGuard` |
| P20 | 経費の申請 | 作成 | 戻り先へ移る | `'location'` |
| P21・P22 | 経費区分・入力プリセット | 新規・編集 | 戻り先へ移る | パス |
| P23 | 有給の付与ルール | 編集 | 戻り先へ移る | パス |
| P24 | 勤務カレンダーの作成 | 作成 | 詳細へ移る | パス |
| P25・P27 | カレンダーの基本設定・日別編集 | 既存 | 留まる・戻り先 | パス |
| P29 | 働き方の設定(一般設定の部分) | 設定 | 留まる | パス |
| P30 | 申請種別 | 新規・編集 | 戻り先へ移る | パス |
| P31 | 申請の作成 | 作成 | 詳細へ移る | パス |

- 対象外: 実行・出力・状態変更・一括操作・インライン(P4 の人事データの取込・P6・P7・P10・P14〜P16・P18・P19・P26・P28)。入力が1〜2項目で、失っても入れ直しが容易なため。
- 有給の計画表(棚卸し外): 既に hook を使っているため、本変更でアプリ内の移動も止まるようになる(望ましい変化として受け入れる)。詳細パネルを閉じるときの `window.confirm` は今のまま(パネルの開閉はページの中の操作で、本変更の確認とは重ならない)。
- 変更の有無: 比べる元(読み込んだ値、新規なら初期値)と今の入力を比べる関数 `isXxxDirty(base, current)` を画面の外に出し、テストで直接呼べるようにする。
  - 保存して留まる画面(P2・P3・P5・P25・P27・P29)は、保存に成功したら比べる元を「送った値」にする(再取得を待つと、その間に誤って変更ありになるため)。
  - 保存して移る画面は `navigateAfterSave` で移る。
- 同じページで即時に保存される操作(行の追加・削除、Dialog の操作)は dirty に含めない。
- 未確定・要確認事項: なし

### 論点6: テスト
- 決定:
  - hook と Provider(`createMemoryRouter` で描画): 変更あり→リンクで止まり Dialog が出る/「ページを離れる」で移る/「編集を続ける」で留まる/変更なし→止まらない/`navigateAfterSave` で止まらない/`navigateAfterSave` の途中で例外→次のリンクは止まる/`blockOn` の既定・`'location'`・関数/`confirmDiscard`/`leaveWithoutGuard` の間は `beforeunload` が何もしない/Provider なし→例外にならない。
  - テスト用の補助 `renderWithGuard`(`createMemoryRouter`+Provider)を用意する。
  - 各画面: dirty の判定関数の単体テスト(変更なし・1項目の変更・元に戻すと変更なし)。保存して移る画面(P1・P8・P9・P20〜P24・P30・P31)は、`renderWithGuard` で「入力→保存→移った先が出て、確認が出ない」を1件ずつ書く(`navigateAfterSave` の付け忘れを単体テストで捕まえる)。P2・P11・P13 は止める判定・`confirmDiscard` を1件ずつ。
  - 新しい E2E は足さない。
- 未確定・要確認事項: なし

### 論点7: E2E への影響
- 決定:
  - ルーターを替えると全画面の移動に関わるため、対象の画面を通る spec(対象の画面の URL を含む12本。実装の前に grep で一覧にして `実装結果` に書く)を流す。全件ではない(原則16)。
  - 止まる経路は2つ: アプリの確認 Dialog と、`page.goto`・`page.reload` で起きる `beforeunload`。両方を確かめる。止まったところは、テストの意図に合わせて「保存する」か「ページを離れる」の操作を足す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `main.tsx`: `BrowserRouter` を `createBrowserRouter([{ path: '*', element: <Root />, errorElement: <RootErrorFallback /> }], { basename: import.meta.env.BASE_URL })`+`RouterProvider` にする。`Root` は今の Provider 群と `UnsavedChangesProvider` と `<App />`。`App.tsx` の `<Routes>` は、`key` を付けるルート以外は変えない。
- `hooks/useUnsavedChangesGuard.ts`(Provider・hook・テスト用の補助):
  - `UnsavedChangesProvider`: `useBlocker` を1回。登録・許可のフラグは ref。判定は「許可のフラグが立っていれば消して通す → 登録のうち active なものの `blockOn` のどれかが真なら止める」。止めたら確認の Dialog(論点4)。
  - `useUnsavedChangesGuard(active, { blockOn?: 'pathname' | 'location' | ((current: Location, next: Location) => boolean) }): { navigateAfterSave, leaveWithoutGuard, confirmDiscard }`。
  - `beforeunload` は今のまま(`active` のとき。許可のフラグの間は何もしない)。
- `ConfirmActionDialog` に `cancelLabel?: string`。
- 論点5の各画面: dirty の判定関数・hook・`navigateAfterSave`・`key`・`blockOn`・`confirmDiscard` を論点5の表のとおりに当てる。P11・P13 の `true` 固定をやめる。
- テスト: 論点6。
- スキル: `ui-interaction-patterns` §2.9 に仕組み(hook の返り値の使い分け、止める判定、`key`、比べる元は送った値、対象はページの作成・編集・設定、Dialog は対象外)を書く。`add-page` に1行足す。

## 受け入れ条件
- 対象の画面で、入力を変えた後にリンク・ブラウザの戻る・キャンセルで移ろうとすると、確認の Dialog が出る。「ページを離れる」で移り、「編集を続ける」で入力が残る。
- ロール詳細でロールを切り替える、経費の申請で手順を戻す、勤怠の日で前日・翌日へ移るときも、変更があれば確認が出る。勤怠の日で前日・翌日へ移った後に、前の日の入力が残らない。
- 変更が無いとき、保存に成功した後の移動(外部への移動を含む)、同じ画面のタブ・絞り込みでは出ない。
- タブを閉じる・再読込は、変更があるときだけブラウザの確認が出る。
- 勤怠の日の編集・計算の補正で、変更が無いのに警告が出ない。
- 論点6のテストがあり、単体テスト全件が通る。論点7の spec が通る。

## 対象外
- Dialog・Sheet の離脱警告
- 論点5の対象外の画面
- 有給の計画表の詳細パネルの `window.confirm`
- ルート定義をデータルーターの形(オブジェクトの配列・loader)へ移すこと

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.9、`.claude/skills/add-page/SKILL.md`
- docs/: 変更なし(画面の振る舞いのみ)

## モック・アセット
なし

## 実装対象
- `frontend/src/main.tsx`、`frontend/src/App.tsx`(`key` のみ)、`frontend/src/hooks/useUnsavedChangesGuard.ts`(+テスト・補助)、`RootErrorFallback`
- `frontend/src/components/ConfirmActionDialog`(`cancelLabel`)
- 論点5の画面(`frontend/src/pages/**`)と、dirty の判定関数のテスト

## 検証方法
- `npx tsc -p tsconfig.app.json --noEmit`、`npx vitest run --project unit`(全件)
- E2E: 論点7の spec。

## 実装の順序
変更セット`20261126-form-field-aria` → 本変更 → `20261126-field-errors-near-fields`。本変更と `field-errors-near-fields` は同じ画面のファイル(state と JSX)と `ConfirmActionDialog` に触れるので、並行させない。

## レビュー履歴
- 2026-11-26 初版。
- 2026-11-26 実装前の独立設計レビュー(general-purpose。react-router 7.18 の動きを検証テストで確認)の指摘で、「設計前提の検証」が覆ったため作り直した。覆った前提:
  - 「キャンセルは navigate」(勤怠の日の編集・補正は表示の切り替え)→ `confirmDiscard` を追加。
  - 「クエリだけの移動ではフォームが消えない」(ロール詳細・経費の申請)→ `blockOn` を追加。
  - 「パスが変われば部品が外れる」(勤怠の日・グループ)→ `key` を付ける。
  - 「保存の後に比べる元を更新すれば警告は出ない」(`useBlocker` の判定は描画の後に反映)→ ref と `navigateAfterSave`。一度きりのフラグが残る問題も、呼び出しの範囲に閉じて解消。
  - 「有給の計画表は今のまま」(hook の意味が変わる)→ 明記。
  - そのほか: 外部への移動の `beforeunload`(`leaveWithoutGuard`)、`ConfirmActionDialog` の文言と variant(`cancelLabel`・`danger`)、`errorElement`、保存して留まる画面の比べる元(送った値)、保存して移る画面のテスト、E2E の `beforeunload` の経路。

## 実装結果
### 土台(段階1)
- `main.tsx` を `createBrowserRouter`+`RouterProvider` に変更(`Root`=Auth/AppSettings/`UnsavedChangesProvider`/`App`、`errorElement`=`RootErrorFallback`)。
- `hooks/useUnsavedChangesGuard.tsx`(`.ts`から改名): Provider・hook(`navigateAfterSave`/`leaveWithoutGuard`/`confirmDiscard`、`blockOn`)。ルーター外(renderHook)でも`beforeunload`だけ動く。
- `ConfirmActionDialog` に `cancelLabel`、`components/RootErrorFallback/`、テスト補助 `src/test/renderWithGuard.tsx`。
- テスト: `useUnsavedChangesGuardRouter.test.tsx`・`useUnsavedChangesGuardNoProvider.test.tsx`ほか。各画面への適用は段階2。

### 画面への適用(段階2)
コミット: `3dab7ade`(土台)、`08c4a1b2`(管理・初回設定・申請・勤怠の日・カレンダー)、備品・経費・付与ルールのコミット。

| 画面 | dirty の判定 | 止める判定 | 保存の後 |
|---|---|---|---|
| P1 グループ | `isGroupFormDirty` | パス。`GroupDetailForm key={id}` | 作成は `navigateAfterSave`、編集は送った値を比べる元に |
| P2 ロール詳細 | `isRoleDefinitionDirty` | パスまたは `roleId` | 送った値 |
| P3 システム設定 | `isSystemSettingsDirty` | パス | 送った値 |
| P5 利用者詳細 | `isUserDetailsDirty` | パス | 送った値 |
| P8・P9 備品 | `isAssetEditDirty`・`isAssetRegisterDirty` | パス | `navigateAfterSave` |
| P11〜P13 勤怠の日 | `isDayFormDirty`・`isCalculationAdjustDirty` | パス。`AttendanceDayContent key={date}` | 表示を戻す。キャンセルは `confirmDiscard` |
| P17 初回設定 | `isOnboardingDirty` | パス | `navigateAfterSave`、SSO は `leaveWithoutGuard` |
| P20 経費の申請 | `isExpenseClaimNewDirty`(単票入力は `isSingleExpenseItemFormDirty` を `onDirtyChange` で) | `'location'`。手順を戻すボタンは `confirmDiscard` の後に state と URL を変える | `navigateAfterSave`(下書きの削除の後も) |
| P21・P22 経費区分・プリセット | `isExpenseCategoryDirty`・`isExpenseEntryPresetDirty` | パス | `navigateAfterSave` |
| P23 付与ルール | `isPaidLeaveGrantRuleDirty` | パス | `navigateAfterSave` |
| P24 カレンダーの作成 | `isWorkCalendarCreateDirty` | パス | `navigateAfterSave` |
| P25・P27 カレンダー | `isWorkCalendarSettingsDirty`・`isWorkCalendarDaysDirty` | パス | 送った値・`navigateAfterSave` |
| P29 働き方の一般設定 | `isWorkStyleOnboardingDirty` | パス | 送った値 |
| P30・P31 申請種別・申請 | `isRequestTypeFormDirty`・`isWorkflowRequestNewDirty` | パス | `navigateAfterSave` |

- 委譲元の判断で加えたこと:
  - 勤怠の日の作成(P12)は、提案値が入った後の値を比べる元にする(記録の無い日を開いて前日・翌日へ移るだけで確認が出ないように)。
  - 経費の申請の単票入力を変更に含め、手順を戻すボタンで画面と URL が食い違わないようにした。
- 勤怠の日で前日・翌日へ移ると前の日の編集中の状態が残る不具合は、`key={date}` で解消した(テストで `key` を外すと失敗することを確認)。
- 既存のテストの変更は `OnboardingPage.test.tsx` を `MemoryRouter` で包んだ1か所だけ(`navigateAfterSave` はルーターが要る)。
- 単体テスト: 全件 1703 件成功(委譲元で再実行)。tsc 成功。
- E2E(論点7の12本: scenario-00・02・04・05・06・08・09・10・11・12・14・99): 50件成功・1件失敗。失敗は scenario-00 のシフトの生成(`/admin/shifts`、本変更の対象外)の待ち時間切れで、単独の再実行で2回とも成功(不安定さ)。確認の Dialog・`beforeunload` で止まった spec は無く、spec の修正は無し。
