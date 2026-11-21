# 画面階層と戻る導線のガイドライン策定と全画面への適用

ステータス: レビュー中

## 変更要望(原文)
> flow-officeでは多段的な画面階層がたくさんあるのですが、有給画面から履歴に飛んだ場合などに戻れないなどのナビゲーション課題が多々あります。
> 一貫性のある操作感を提供したいのですがUXのガイドラインを作成してください。また、そのガイドラインを各画面に適用してください。

## 背景・目的
第2階層以下の画面(履歴・詳細・編集・日次など)で、戻る導線が無い・戻り先が遷移元と違う・戻ると絞り込みが消える、
という不統一がある。画面階層と戻る導線のガイドラインを`ui-interaction-patterns`に定め、全画面を同じ規則に揃える。

## 現状(As-Is)
investigatorによる全画面調査の要約(根拠はファイル:行)。

- 共通部品: `PageHeader`・`BackLink`・パンくずは無い。各ページが`<Link to="固定パス">`で個別に書いている。
  `location.state`による遷移元の受け渡しは0件。`document.title`の設定は0件。
- 戻る導線が無い画面:
  - `pages/paidLeave/MyPaidLeaveHistoryPage.tsx`(有給履歴)・`pages/specialLeave/MySpecialLeaveHistoryPage.tsx`(特別休暇履歴)
  - `pages/attendance/AttendanceDayPage.tsx`(日次。前日/翌日/今日/週次のみ、親へ戻れない)
  - `pages/expense/ExpenseClaimNewPage.tsx`の編集モード(`/expenses/:id/edit`)
  - `pages/admin/UserRoleEditPage.tsx`(`/admin/users/:id`)・`pages/admin/GroupDetailPage.tsx`(`/admin/groups/:id`)
- 戻り先が固定で遷移元・一覧状態を失う画面:
  - `WorkflowRequestDetailPage.tsx:122`(常に`/requests`。有給・特別休暇・代休の差戻し通知`LeaveReturnedNotice.tsx:18`から来ても申請一覧へ行く)
  - `AssetDetailPage.tsx:475`・`BackOfficeTaskDetailPage.tsx:171`・`ExpenseClaimDetailPage.tsx:101`・
    `AttendanceMonthDetailPage.tsx:79`(「一覧」。`?year=&page=`が消える)・`WorkflowRequestNewPage.tsx:92`・
    `PaidLeaveSchedulePage.tsx:274`・`RequestTypeEditPage.tsx:121`・`WorkCalendarCreatePage.tsx:141`・
    `WorkCalendarDetailPage.tsx:350`・`WorkCalendarDaysPage.tsx:444`
  - キャンセルが固定パス: `AssetEditPage.tsx:84`・`AssetRegisterPage.tsx:163`・`ExpenseEntryPresetEditPage.tsx:153,160`
    (`?category_id=`が消える)・`ExpenseCategoryEditPage.tsx:257`・`RequestTypeEditPage.tsx:289`・`PaidLeaveGrantRuleEditPage.tsx:146`
  - 備品一括5画面(`pages/asset/bulk/*`)は「戻る」ボタンで`navigate('/assets')`(実態はキャンセル。§1-5違反)
  - `AttendanceDayPage.tsx:1498`削除後の`navigate(-1)`(直接開くとアプリ外へ出得る)
- 文言・記号の不統一: 「← 一覧へ戻る」「← 一覧に戻る」「← タスク一覧に戻る」「← 有給休暇」「一覧」「戻る」、
  矢印は「←」・`ChevronLeft`・`ArrowLeft`が混在。
- サイドバーの点灯:
  - `routes/routeManifest.ts:250` 勤怠の「日次勤怠」「月次勤怠」は今日・今月の固定URLで完全一致判定のため、
    過去月・月次一覧・他の日では点灯しない。
  - `components/AdminLayout/AdminLayout.tsx:40-44` `end={!item.matchPrefix}`で、`matchPrefix`は有給休暇のみ。
    利用者詳細・グループ詳細・申請種別編集・経費区分編集・会社カレンダー詳細などで親項目が点灯しない。
- ガイドライン: `ui-interaction-patterns` §2.4(詳細の左上に戻る導線)・§2.9(Back/Cancel)・§2.10(一覧状態の維持)はあるが、
  戻り先の決め方・遷移元の扱い・階層の定義・文言は定めていない。SKILL.mdが参照する`known-gaps.md`は実在しない。
- e2e: `scenario-06-attendance-corrections.spec.ts:163-166`が日次削除後に`/attendance/week`へ戻ることを期待。
  戻る文言に依存するe2eは無い。単体テストでは`AttendanceMonthDetailPage.test.tsx:584`(「一覧」)、
  `AdminLayout.test.tsx:113-122`(`aria-current`)、`GroupDetailPage.test.tsx:233`(戻る導線の不在を確認)が影響を受ける。

## 設計前提の検証
- 正データと派生データ: フロントエンドのナビゲーションのみ。API・`stored_events`・Projectionへの影響なし。
- 定義と実態: §2.4の「左上に戻る導線」は定義済みだが部品が無く、各画面が独自実装→ずれた。
  戻り先の決め方(遷移元か親か)はどこにも定義が無い。
- 原因の分類: 設計の欠落(画面階層の定義と戻る導線の共通部品が無い)。個別画面へのリンク追加(対症療法)ではなく、
  階層定義+共通部品+規則で直す選択肢を論点1〜3に置く。
- 横展開: 全ルート(`App.tsx`の約70ルート)を対象。休暇(§2.25): 有給・特別休暇は履歴画面に戻る導線を付ける。
  代休には履歴画面が無い(本人画面1枚)ため、追加の画面は作らない。3種とも差戻し通知→申請詳細の戻り先を元の休暇画面にする。
- 一覧のページング: 変更なし(既存のURLクエリを戻りで復元するだけ)。

## 仕様検討

### 論点1: 戻り先の決め方
- 選択肢:
  - A. 親画面への固定リンク(現状の多数派)。遷移元・一覧の絞り込みが失われる。
  - B. ブラウザ履歴で1つ戻る(`navigate(-1)`)。直接開いたときアプリ外へ出る/前後移動のたびに戻り先が変わり予測できない。
  - C. 遷移元をReact Routerの`location.state`に記録し、あればそこ(パス+クエリ)へ、無ければ階層上の親へ戻る。
- 決定: C。
- 理由: 遷移元と一覧状態を復元でき(有給→申請詳細→有給)、直接開いても親へ戻れる。`location.state`は
  ブラウザ履歴に保存されリロード後も残る。共有URLには載らないので、URLは今までどおり状態だけを表す(§2.10と両立)。
- 未確定・要確認事項: なし

### 論点2: 遷移元の記録の形(入れ子か1段か)
- 選択肢:
  - A. 直前の1画面だけ記録する。「有給→申請詳細→備品詳細→戻る→申請詳細」で申請詳細の戻り先(有給)が失われる。
  - B. 遷移元自身の`state`も入れ子で保持する(スタック)。深さ上限あり。
- 決定: B。上限5段。超えた古い段は捨て、そこから先は親画面へ戻る。記録するのは`{ pathname, search, hash, backTo }`だけで、
  遷移元の他の`state`は複製しない。
- 理由: 戻るを繰り返しても来た道をたどれる。同じドメイン内の深さは最大3(一覧→詳細→編集)だが、他ドメインを往復する
  (有給→申請詳細→備品詳細)場合に備え5段とする。型を限定して`history.state`の肥大を防ぐ。
- `state`が失われる経路(中クリック・新規タブ、`RequireAuth`のログインリダイレクト、`AssetQrRedirectPage`の`<Navigate>`、
  ページ全体の再読み込みを伴う`<a href>`)では親画面へ戻る。壊れはしない(仕様として許容)。
- 未確定・要確認事項: なし

### 論点3: 画面階層・画面名の置き場所
- 選択肢:
  - A. 各ページが親と画面名を自分で持つ。点灯判定・タブ名で使い回せない。
  - B. `routes/pageHierarchy.ts`にルートパターン→{画面名, 親}を1か所で定義し、BackLink・サイドバー点灯・`document.title`が参照する。
  - C. `routeManifest.ts`に統合。routeManifestはナビ項目とfeatureガード用で一般ナビのみ(管理画面は別ファイル)のため責務が混ざる。
- 決定: B。画面名と親はパラメータから作れる関数も許す(例: `/attendance/months/:yearMonth` → 「2026年11月の勤怠」、親は`/attendance/months`。
  `/attendance/days/:date` → 「11月21日の勤怠」、親は`/attendance/months/<その日の年月>`)。
  動的な名前(会社カレンダー名等)が要る場合はBackLinkに`label`を渡して上書きできる。
- 理由: 1か所に集めると登録漏れをテストで検出できる。
- 未確定・要確認事項: なし

### 論点4: 戻る導線の見た目と文言
- 選択肢:
  - A. パンくずリスト。階層は最大3〜4段で、モバイルでは幅を取る。
  - B. 左上の戻るリンク1つ。`ChevronLeft`+「{戻り先の画面名}に戻る」。
  - C. B+共通ページヘッダ(h1・説明・Primary Action)の全面導入。
- 決定: B。共通ページヘッダ(h1統一)は対象外。
- 理由: 戻り先が遷移元によって変わるため、固定のパンくずとは相性が悪い。戻り先の名前を出すと押す前に行き先が分かる。
  h1統一は見た目の大きな変更で、ナビゲーション課題と独立に扱える。
- 未確定・要確認事項: なし

### 論点5: キャンセル・削除後・「戻る」ボタンの扱い
- 選択肢:
  - A. 現状維持(固定パス)。
  - B. キャンセルと削除後の遷移も`useBackTarget`の戻り先にする。備品一括5画面の「戻る」ボタンは「キャンセル」に改名し、上部にBackLinkを置く。
- 決定: B。キャンセル・削除後の遷移は`replace: true`(Browser Backで破棄した編集画面・削除済みの詳細に戻らない)。
  保存後の遷移先**パス**(§2.5・§2.6、一覧へ戻る画面が残っている件)は本変更では変えないが、遷移先のパスが戻り先のパスと同じ
  場合は戻り先の`state`を引き継ぐ(例: 備品一覧(絞り込み)→詳細→編集→保存→詳細の「戻る」で絞り込み付きの一覧へ戻る)。
  作成後に詳細へ`replace`する画面(GroupDetailPageの作成)も、現在の`state`を引き継ぐ。
- 理由: キャンセルは「来た場所へ戻る」が期待値で、絞り込みも残すべき。保存後に`state`が落ちると、詳細の戻り先が親(絞り込みなし)に
  なってしまう。保存後の遷移先そのものの統一は別規則(§2.6)の問題で範囲が広がる。
- 未確定・要確認事項: なし

### 論点6: 現在地の表示(サイドバー点灯・タブ名)
- 選択肢:
  - A. 現状維持。
  - B. サイドバーは表示中の画面の祖先(階層)にあたる項目を点灯させる。`document.title`を`{画面名} | flow-office`にする。
- 決定: B。点灯は「`pageHierarchy`の祖先チェーン(自身から近い順)を1つずつたどり、そのパスに一致(完全一致または`to/`始まり)する
  ナビ項目が見つかった最初の段の項目を点灯させる。同じ段で複数一致した場合は`to`が最も長いものを採る」。
  (例: 日次`/attendance/days/2026-10-05`は自身の段で「日次勤怠」(`/attendance/days`)に一致するため、祖先の月次勤怠は点灯しない。)
  勤怠の日次・月次は`routeManifest`の正規化パス(`to`)で判定し、リンク先(`buildTo`)は今日・今月のまま
  (`buildNavGroups`の`ResolvedNavItem`に点灯判定用の`matchPath`を持たせる)。管理画面も同じ判定(`matchPrefix`は廃止)。
  `/admin`(管理メニュー)は自身の段でのみ一致させる。同じURLの項目が2つある場合(備品貸出/備品管理`/assets`)は両方点灯のまま(現状どおり)。
- 理由: どの階層にいても所属がサイドバーで分かる。タブ名はブラウザ履歴の一覧でも場所を示す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)

### 共通部品(`frontend/src`)
1. `routes/pageHierarchy.ts`
   - `PageNode { pattern: string; title: string | ((params) => string); parent: null | string | ((params) => string) }`の配列。
     `pattern`は`App.tsx`のルートと同じパス形式(`matchPath`で照合)。`parent`は親の**実パス**を返す(パラメータを含む親は関数で
     組み立てる。例: `/expenses/:id/edit`の親は`({id}) => '/expenses/' + id`)。`null`は第1階層。
   - `resolvePage(pathname)`: 最も具体的に一致するノードから`{ title, parentPath }`を返す。一致なしは`null`。
   - `ancestorsOf(pathname)`: 自身から親をたどったパスの配列(循環防止で最大10)。
   - `findActiveNavPath(pathname, navPaths)`: 論点6の規則で点灯させる`navPaths`の要素(複数可)を返す。
   - 全エントリは下表「画面階層」のとおり。`App.tsx`の全ルートパターン(除外: 公開ルート・`*`・要素が`<Navigate>`だけのルート・
     `/assets/qr/:token`(コンポーネント内でリダイレクトするだけの画面))が登録されていることを単体テストで確認する
     (テストはApp.tsxからパターン一覧を取り出せる形にするか、ルート定義の配列を`App.tsx`と共有する。方法は実装時に既存構造に合わせる)。
2. `navigation/backState.ts`(フック群)
   - 型 `BackTo = { pathname: string; search: string; hash: string; backTo?: BackTo }`。`location.state`は`{ backTo?: BackTo }`として読む。
   - `useForwardState()`: `{ backTo: { pathname, search, hash, backTo: 現在のstate.backTo } }`を返す。入れ子が5段を超える分は切り捨てる。
     遷移元の他の`state`は含めない。
   - `useBackTarget(override?: { to?: string; label?: string })`: 戻り先`{ to: string(pathname+search+hash), state: { backTo } | undefined, label }`を返す。
     優先順: `override.to` → `location.state.backTo`(`pathname+search`を既存の`auth/redirectTarget.ts`の`getSafeRedirectTarget`で検証し、
     不合格なら無視) → `resolvePage(pathname).parentPath`。いずれも無ければ`null`。`label`は`override.label` → 戻り先パスの
     `resolvePage().title`。`state`は記録済みの戻り先が持つ`backTo`を復元したもの(親へ戻るときは`undefined`)。
   - `useKeepBackState()`: 同じ階層内の移動・保存後の遷移に渡す現在の`{ backTo }`を返す。
   - `useSearchParamsKeepingBackState()`: `useSearchParams`と同じ形で、`setSearchParams`の第2引数に現在の`state`を自動で足すラッパー
     (`replace`の有無は呼び出し側の指定をそのまま使う)。第2階層以下の画面の`useSearchParams`はこれに置き換える。
3. `components/BackLink/`(component + stories + test。`add-frontend-component`スキル)
   - `useBackTarget`の結果を`<Link to state>`で描画。表示「`<ChevronLeft/>` {label}に戻る」。戻り先が`null`なら何も描画しない。
   - props: `to?`・`label?`(上書き)。見た目は`ui-design-system`の既存のテキストリンク(muted, hover underline)。
4. `AppLayout`(または`useDocumentTitle`フック)で`document.title`を`{resolvePage().title} | flow-office`に、未登録は`flow-office`。
5. サイドバー点灯: `AppLayout`の`isPathActive`呼び出しと`AdminLayout`の`NavLink`の`end`判定を、`findActiveNavPath`に置き換える。
   `routeManifest.ts`の`ResolvedNavItem`に`matchPath`(=正規化された`to`)を追加し、点灯はこれで判定する。
   `adminNavGroups.ts`の`matchPrefix`は削除する。

### 画面階層(`pageHierarchy`の内容)
親が`null`のものは第1階層(BackLinkを出さない)。

| パターン | 画面名 | 親 |
|---|---|---|
| `/` | ホーム | null |
| `/attendance/week` | 週次勤怠 | null |
| `/attendance/months` | 月次勤怠一覧 | null |
| `/attendance/months/:yearMonth` | {Y}年{M}月の勤怠 | `/attendance/months` |
| `/attendance/days/:date` | {M}月{D}日の勤怠 | `/attendance/months/{date の YYYY-MM}` |
| `/requests` | 申請一覧 | null |
| `/requests/new` | 申請を作成 | `/requests` |
| `/requests/:id` | 申請詳細 | `/requests` |
| `/approvals` | 承認待ち | null |
| `/expenses` | 経費精算一覧 | null |
| `/expenses/new` | 経費精算を作成 | `/expenses` |
| `/expenses/:id` | 経費精算詳細 | `/expenses` |
| `/expenses/:id/edit` | 経費精算を編集 | `/expenses/{id}` |
| `/expenses/presets` | 明細プリセット一覧 | `/expenses` |
| `/expenses/presets/new` | 明細プリセットを作成 | `/expenses/presets` |
| `/expenses/presets/:id` | 明細プリセットを編集 | `/expenses/presets` |
| `/paid-leave` | 有給 | null |
| `/paid-leave/history` | 有給履歴 | `/paid-leave` |
| `/compensatory-leave` | 代休 | null |
| `/special-leave` | 特別休暇 | null |
| `/special-leave/history` | 特別休暇履歴 | `/special-leave` |
| `/backoffice-tasks` | タスク一覧 | null |
| `/backoffice-tasks/:id` | タスク詳細 | `/backoffice-tasks` |
| `/assets` | 備品一覧 | null |
| `/assets/new` | 備品を作成 | `/assets` |
| `/assets/bulk/*`(5画面) | 各画面の既存見出し | `/assets` |
| `/assets/:id` | 備品詳細 | `/assets` |
| `/assets/:id/edit` | 備品を編集 | `/assets/{id}` |
| `/account` | アカウント設定 | null |
| `/integrations` | API・MCP連携 | null |
| `/notifications` | 通知 | null |
| `/admin` | 管理メニュー | null |
| `/admin/<各一覧>` | `adminNavGroups.ts`の表示名 | null(管理メニュー配下の第1階層) |
| `/admin/users/:id` | 利用者詳細 | `/admin/users` |
| `/admin/groups/:id` | `id==='new'`ならグループを作成、それ以外はグループ詳細 | `/admin/groups` |
| `/admin/request-types/:id` | `id==='new'`なら申請種別を作成、それ以外は申請種別を編集 | `/admin/request-types` |
| `/admin/expense-categories/:id` | `id==='new'`なら経費区分を作成、それ以外は経費区分を編集 | `/admin/expense-categories` |
| `/admin/work-calendars/new` | 会社カレンダーを作成 | `/admin/work-calendars` |
| `/admin/work-calendars/:id` | 会社カレンダー詳細 | `/admin/work-calendars` |
| `/admin/work-calendars/:calendarId/years/:fiscalYear/days` | {FY}年度の日別設定 | `/admin/work-calendars/{calendarId}` |
| `/admin/paid-leave/schedule` | 有給取得予定 | `/admin/paid-leave` |
| `/admin/paid-leave/rules/:ruleId/edit` | 付与ルールを編集 | `/admin/paid-leave` |

画面名の文言は実装時に既存の見出し(Card title)・ナビ表示名と一致させる(上表と異なる既存名があれば既存名を優先し、実装結果に記録)。

### 各画面への適用
- **BackLinkを置く(新規)**: 有給履歴・特別休暇履歴・日次勤怠・経費精算の編集(`/expenses/:id/edit`。新規作成時も`/expenses`へ)・
  利用者詳細・グループ詳細・明細プリセット編集・備品作成・備品編集・備品一括5画面・付与ルール編集・経費区分編集。
- **既存の戻るリンクをBackLinkに置き換える**: 申請詳細・申請作成・備品詳細・タスク詳細・経費精算詳細・月次勤怠詳細(「一覧」)・
  有給取得予定・申請種別編集・会社カレンダー作成・会社カレンダー詳細・日別設定(`label`に`{カレンダー名}`を渡す)。
- **遷移元を記録する(`useForwardState`の`state`を渡す)**: 画面内から別の画面へ進むすべてのリンク・行クリック・ボタン
  (進む先が第1階層でも渡す。判定を単純にするため。第1階層の画面はBackLinkを出さないので無害)。渡さないのは、上へ戻る操作
  (BackLink・キャンセル・削除後・保存後)と、サイドバー・ヘッダ・管理画面のナビのリンクだけ。最低限:
  - 一覧の行・名称リンク: 申請一覧・経費精算一覧・明細プリセット一覧・備品一覧・タスク一覧・月次勤怠一覧・利用者一覧・グループ一覧・
    申請種別一覧・経費区分一覧・会社カレンダー一覧・付与ルール一覧(編集)
  - 「履歴を見る」(有給・特別休暇)、`LeaveReturnedNotice`の「申請詳細を開く」(有給・特別休暇・代休)
  - `AttendanceDayRow`の日付リンク(週次・月次詳細)、`TodayAttendancePanel`の日次リンク、週次の「月次」リンク、
    月次詳細から申請作成へのリンク、申請一覧の「その他申請」(申請作成)
  - 申請詳細内の備品リンク(`WorkflowRequestDetailPage.tsx:51`)、経費精算詳細の「明細を編集する」、経費精算作成の明細プリセットへのリンク、
    備品一覧の作成・一括操作ボタン、備品詳細の編集、会社カレンダー詳細→日別設定、有給管理→取得予定、
    グループ詳細のメンバー→利用者詳細、経費精算一覧の「編集を続ける」
  - 通知一覧(`NotificationsPage.tsx:107`)の「詳細を確認する」: `detail_url`が`getSafeRedirectTarget`を通る自サイト内の相対パスなら
    `<Link state>`で描画する(ページ再読み込みをやめる)。通らない値は従来どおり`<a href>`。
  - 該当箇所の洗い出しは実装時に`Link`/`navigate`を全件確認し、上の規則(別画面へ進む=渡す、上へ戻る・サイドバー=渡さない)で判定する。
- **同じ階層内の移動で戻り先を引き継ぐ**(`useKeepBackState`/`useSearchParamsKeepingBackState`): 月次詳細の前月/次月、
  日次の前日/翌日/今日、第2階層以下の画面で`setSearchParams`を使う箇所すべて。履歴の積み方(`replace`の有無)は変えない。
- **キャンセル・削除後**: 備品作成・編集、明細プリセット編集、経費区分編集、申請種別編集、付与ルール編集、会社カレンダー作成、
  備品一括5画面のキャンセル、日次削除後(`navigate(-1)`を廃止)、経費精算削除後は`useBackTarget()`の`to`へ`state`付き・`replace: true`で遷移。
  備品一括5画面の「戻る」ボタンは「キャンセル」に改名。
- **保存後**: 遷移先パスは変えない。遷移先のパス(pathname)が`useBackTarget()`の戻り先と同じなら戻り先の`to`と`state`を使い、
  異なる場合(詳細へ進む等)は`useKeepBackState()`の`state`を渡す。GroupDetailPageの作成後の`replace`も`state`を引き継ぐ。

### ガイドライン
- `assets/navigation-guideline.md`の本文を`.claude/skills/ui-interaction-patterns/SKILL.md`の§2.26として追加し、§1 Mandatory Rulesに
  「13. 第2階層以下の画面は`BackLink`で戻り先を示し、戻り先は遷移元→親画面の順に決める(§2.26)」を追加、§4.1参照実装表・§5チェックリストに反映。
- §2.4の図の「← 一覧へ戻る」を「〈 {戻り先}に戻る(BackLink、§2.26)」に直す。存在しない`known-gaps.md`への参照(SKILL.md内4か所:
  §1-7・§2.18・§4.1の前文・§4.1の表)は、参照を外して内容を本文に残す形に直す。
- `.claude/skills/add-page/SKILL.md`の手順に「`pageHierarchy`への登録」「第2階層ならBackLink」を追加する。

### テスト(UI非依存の業務ルールは無いため`domain-test`は対象外。frontend単体テストで網羅)
- `pageHierarchy.test.ts`: 全ルート登録の網羅、`resolvePage`(動的な画面名・親)、`ancestorsOf`、`findActiveNavPath`
  (有給履歴→有給、過去月の月次→月次勤怠、日次の任意日→日次勤怠、`/admin/users/:id`→利用者、`/admin/paid-leave/schedule`→有給休暇)。
- `backState.test.tsx`: 遷移元ありならそのパス+クエリ+ハッシュ+入れ子state、無ければ親、不正なパス(`//evil`・`/\evil`)は無視、
  5段超の切り捨て、遷移元の他の`state`を含めない、`useSearchParamsKeepingBackState`で`state`が残る。
- `document.title`: `AppLayout`のテストで、有給履歴を表示すると`有給履歴 | flow-office`になること。
- `BackLink.test.tsx`: 文言「{label}に戻る」、戻り先null時は非表示。
- 画面テスト(代表): 有給履歴に「有給に戻る」/`/paid-leave`。有給の差戻し通知→申請詳細で「有給に戻る」。申請一覧`?status=..&page=2`→詳細→
  戻るでクエリ復元。月次詳細で前月へ移動しても戻り先が維持。日次を直接開くと「{Y}年{M}月の勤怠に戻る」。
- 既存テストの更新: `AttendanceMonthDetailPage.test.tsx:584`、`AdminLayout.test.tsx`の点灯、`GroupDetailPage.test.tsx:233`(戻る導線がある前提に変更)、
  戻る文言を参照する各ページテスト。
- e2e: `scenario-06`の日次削除後の戻り先(週次から来た場合は週次へ戻る)が通ること。

## 受け入れ条件
- 有給→「履歴を見る」→有給履歴の左上に「有給に戻る」があり、押すと有給へ戻る。特別休暇も同様。
- 有給・特別休暇・代休の差戻し通知から申請詳細へ行くと、戻る導線は元の休暇画面へ戻る。
- 申請一覧・備品一覧・タスク一覧・月次勤怠一覧で絞り込み・ページを変えて詳細へ進み、戻る導線で戻ると同じ絞り込み・ページが表示される。
- 第2階層以下のすべての画面(上の画面階層表で親が`null`でないもの)に、同じ見た目・文言形式の戻る導線が1つある。URLを直接開いた場合は親画面へ戻る。
- キャンセル・削除後の遷移先が戻る導線と同じ。備品一括画面に「戻る」ボタンが無く「キャンセル」になっている。
- 有給履歴・申請詳細・過去月の月次勤怠・管理画面の詳細画面でサイドバーの親項目が点灯する。
- ブラウザのタブ名が`{画面名} | flow-office`になる。
- `ui-interaction-patterns`に§2.26があり、Mandatory Rules・チェックリスト・`add-page`に反映されている。
- frontendの`npm run lint`・`npm run typecheck`(相当)・`npm test`がPASS。e2e `scenario-06`がPASS(実行環境があれば)。

## 対象外
- 共通ページヘッダ(h1・説明・Primary Actionの統一)。
- 保存後の遷移先の統一(§2.6未準拠の一覧へ戻る画面: 経費区分・申請種別・付与ルール編集)。
- 代休の履歴画面の新設。
- 管理画面の「アプリに戻る」(ホームへの固定リンクのまま)。
- 承認待ちのインライン詳細パネル(ルートを持たないため)。
- backend・mcp。

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md`: §2.26追加、§1・§2.4・§4.1・§5更新、`known-gaps.md`参照の削除。
- `.claude/skills/add-page/SKILL.md`: `pageHierarchy`登録とBackLinkの手順を追加。
- `docs/18-ui-guidelines.md`: 「共通(ナビゲーション)」の小節を追加し§2.26を参照(本文は重複させない)。
- `docs/changesets/README.md`: 一覧に追加。

## モック・アセット
- [assets/navigation-guideline.md](./assets/navigation-guideline.md): §2.26として取り込むガイドライン本文の案。

## 実装対象
- 新規: `frontend/src/routes/pageHierarchy.ts`(+test)、`frontend/src/navigation/backState.ts`(+test)、`frontend/src/components/BackLink/`(component・stories・test)
- 変更: `frontend/src/components/AppLayout/`、`frontend/src/components/AdminLayout/`(`adminNavGroups.ts`の`matchPrefix`廃止)、
  `frontend/src/routes/routeManifest.ts`(点灯判定)、`LeaveReturnedNotice`・`AttendanceDayRow`・`TodayAttendancePanel`、
  `frontend/src/pages/`配下の上記「各画面への適用」の画面と、そのテスト・stories
- ドキュメント: 上記「ドキュメントへの影響」

## 検証方法
- `cd frontend && npm run lint && npx tsc -b && npm test`
- 実機確認: Playwrightで有給→履歴→戻る、申請一覧(絞り込み)→詳細→戻る、月次詳細→日次→戻る、備品一覧(絞り込み)→詳細→編集→保存→戻るを操作し確認。
- e2e `npm run test:e2e -- scenario-06`(backendを起動できる場合)。

## レビュー履歴
- 2026-11-21 初版。
- 2026-11-21 提示前の独立設計レビュー(general-purpose)の指摘を反映:
  - 点灯規則を「最長の`to`」から「祖先チェーンを近い順にたどり最初に一致した段」に変更(日次で月次勤怠が点灯する矛盾の解消)。
  - BackLink・キャンセルでは記録済みの戻り先の`state`を復元する、とガイドラインと仕様を一致させた。
  - `useForwardState`の記録内容を`{pathname, search, hash, backTo}`に限定し、検証は既存の`getSafeRedirectTarget`を使う(`/\evil`対策)。
  - 保存後の遷移で`state`を引き継ぐ規則と、キャンセル・削除後の`replace: true`を追加(保存後に絞り込みが消える経路の解消)。
  - 画面階層表の誤り(`/admin/groups/new`ルートは存在しない、親パスの表記)を修正、`/assets/qr/:token`の除外を明記。
  - 遷移元を渡すリンクを追加(グループ詳細のメンバー、経費精算一覧の「編集を続ける」、通知の「詳細を確認する」を`Link`化)。
    進む先が第1階層でも渡す、に統一。
  - `state`が失われる経路を明記、`setSearchParams`のラッパーを追加、`replace`必須の記述を削除(履歴の積み方は変えない)。
  - `ResolvedNavItem.matchPath`の追加、検証コマンドをpackage.jsonに合わせた、`known-gaps.md`参照4か所を明記。

## 実装結果
未着手
