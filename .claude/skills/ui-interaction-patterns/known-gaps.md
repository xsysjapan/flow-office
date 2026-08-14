# 既存実装とInteraction Patternの差異(2026-08時点)

`SKILL.md`を策定した時点で、既存画面に残っている不統一の記録。新規画面は最初から`SKILL.md`
に従う。リポジトリ内の全画面(経費入力プリセット編集・カレンダーを含む)が対応済みで、
以下の項目はすべて解消済み。ユーザー承認を得たAPI変更・Modal→Page変換・行クリック方式の
全画面統一も含めて完了している。

## 1. 確認ダイアログが2種類あった(解消)

- `frontend/src/components/ConfirmDialog/` — trigger要素を渡す形(`DialogTrigger`)
- `frontend/src/components/ConfirmActionDialog/` — `triggerLabel`文字列 + variant、`children`で
  フォームを足せる

全画面を`ConfirmActionDialog`へ移行し、利用者が0件になった`ConfirmDialog`コンポーネント
自体(`.tsx`/`.test.tsx`/`.stories.tsx`)を削除した。カレンダー画面が使っていた
`window.confirm()`(ブラウザ標準ダイアログ、Mandatory Rule 7違反)も含めてすべて
`ConfirmActionDialog`に統一済み。確認ダイアログは今後`ConfirmActionDialog`のみを使う。

## 2. 行クリックの実装が3種類あった(解消)

- セル内`Link`のみ
- 行全体`onClick`+`cursor-pointer`(手書きの`role="button"`/`tabIndex`/Enter・Space対応)
- 行クリック+タイトルだけ独立した`<button>`

共通実装`frontend/src/components/ClickableTableRow/`へ全一覧画面を統一した
(`onRowClick`/`rowLabel`/`disabled`props)。主要フィールドのセルは既存の`<Link>`/`<button>`
を残しつつ`stopPropagation`で行クリックとの二重発火を防ぐ。

**統一の過程で見つけた設計上の問題**: 当初`ClickableTableRow`は`role="button"`を`<tr>`に
付与していたが、これは`<tr>`本来の暗黙の`role="row"`を上書きしてしまい、
`getByRole('row')`に依存するe2e(`scenario-04-expense-claim.spec.ts`,
`scenario-05-business-card.spec.ts`, `scenario-09-cross-domain.spec.ts`)を壊す組み合わせ
だった。`role`指定自体を削除し、`tabIndex`/`onKeyDown`(Enter・Space)/`aria-label`のみで
行クリックを実装するよう修正し、行としての意味論(`role="row"`)を壊さないようにした。

## 3. URL状態(解消)

検索・フィルター・ページ・選択中の詳細対象IDを、URL状態を持つべき一覧・詳細画面すべてで
`useSearchParams`によりURLへ反映した。`pages/admin/UserListPage.tsx`は`api/users.ts`の
`fetchUsers`に`page`引数を追加し(バックエンドは`paginate()`で`page`クエリに元々対応済み
だったため、フロントエンドの引数追加のみで対応できた)、フィルターの選択肢を現在の
ページのユーザーからではなく`useManagedGroups`/`useGroupTypes`の全件一覧から組み立てる
よう修正した(ページングにより選択肢が現在ページの内容に限定される問題を避けるため)。
`pages/expense/ExpenseClaimNewPage.tsx`は選択中の経費区分(`?category=`)をURLへ書き込む
ようにした(ウィザードの各ステップ自体のURL化は、状態構造上の理由で見送っている。
下記「残っている既知の項目」参照)。ソートのURL反映例は無し(該当画面が現状無い)。

## 4. 保存後の遷移が非対称(解消)

- `pages/expense/ExpenseCategoryEditPage.tsx` — 作成/更新とも一覧へ`navigate`
- `pages/admin/GroupDetailPage.tsx` — 作成後も詳細画面(自身)に留まるよう修正し、更新時と
  対称にした
- `pages/workCalendar/WorkCalendarCreatePage.tsx`(旧`CreateCompanyCalendarModal`)—
  作成後は作成されたカレンダーの詳細画面へ遷移する

## 5. Create/Editの画面分割方針(部分解消)

`/new`と`/:id`を1ページで共用(`isCreate`/`isNew`分岐)する画面と、
`pages/expense/ExpenseClaimNewPage.tsx`のようにpage内ローカルコンポーネントでウィザードを
組む画面が残る。`CreateCompanyCalendarModal`(Dialog)は入力項目が多かったため
`WorkCalendarCreatePage`としてPage化した(§10参照)。残る差異はドメインの複雑さに応じた
妥当な使い分けと判断し、無理な統一はしていない。

## 6. Bulk Actionsの配置(解消)

`pages/workflow/WorkflowRequestListPage.tsx`の理由入力を伴う一括取消は、確認なしの
インライン実行から`ConfirmActionDialog`での確認に変更し、`pages/approvals/ApprovalsPage.tsx`
と同様に「トリガーのみをactionsに置き、詳細はダイアログ内」という配置に揃えた。

## 7. Toast基盤(判断確定: 導入しない)

`toast`/`sonner`相当の実装はリポジトリに無い。成功フィードバックは画面遷移・一覧更新・
インラインの成功メッセージ・`ErrorMessage`で表現しており、SKILL.md §2.18が要求する
`Idle → Submitting → Success / Error`は既存パターンで満たせている。Toastを新規導入すると
一貫性を保つために既存の大多数のミューテーション呼び出し箇所(数十ファイル)を合わせて
書き換える必要があり、「大規模な構造変更を避ける」という制約と衝突するため、導入しないと
判断した。将来的に画面遷移・インライン表示だけでは表現できない要件が出た時点で改めて
この判断を見直す。

## 8. Empty Stateの共通化(解消)

`frontend/src/components/EmptyState/`を新規追加し、リポジトリ内の全画面の空状態をこの
コンポーネントへ統一した。検索/フィルターが効く一覧ではInitial EmptyとFiltered Empty
(+条件クリアの導線)を区別している。共有コンポーネント`LeaveHistoryList`内部の空状態
文言自体は変更していない(呼び出し側でラップして対応)。

## 9. Permission Denied状態(部分解消)

`frontend/src/components/PermissionDenied/`を新規追加した。ページ全体がアクセス不能になる
ケース(`AdminDashboardPage`でアクセス可能な管理セクションが0件、`BackOfficeTaskDetailPage`/
`BackOfficeTaskListPage`の403応答)に導入済み。個別ボタンの非表示方式
(`isApplicant`/`isApprover`等)は今回維持している(ページ単位でアクセス不能になるケースと
ボタン単位の権限分岐は区別して扱う)。

## 10. Modal系コンポーネントの命名・構成(部分解消)

`CreateCompanyCalendarModal`(入力項目が多く§2.11に照らしDialogとして不適切だった)は
`WorkCalendarCreatePage`としてPage化し、削除した。`DeviceDetailModal` /
`WorkStyleFormModal` / `MonthlyAttendanceBulkEntryModal` / `WeeklyAttendanceBulkEntryModal`
は入力項目数が少なく妥当な規模のため、`*Modal`のまま残している(`*Dialog`との命名統一は
見送り。両者は「フォームモーダル」と「確認ダイアログ」という異なる役割を持つため、
命名の違いは責務の違いを表しているとも言える)。`Sheet`は`AppLayout`/`AdminLayout`の
モバイルナビ専用で、Filter用途には未使用。

## 11. 「編集可能/削除可能」判定の置き場所が画面ごと

`pages/expense/ExpenseClaimListPage.tsx`はコンポーネント内でstatus文字列比較、
`pages/workflow/WorkflowRequestListPage.tsx`はモジュールトップレベルの定数配列
(`CANCELLABLE_STATUSES`)。共通化されていない(各画面内で一貫していれば実害は小さいため
優先度は低い)。

## 12. Detail Pageのアクション配置(Pattern exceptionとして明記)

`pages/expense/ExpenseClaimDetailPage.tsx` / `pages/workflow/WorkflowRequestDetailPage.tsx`は
承認/差戻し/取消/提出を画面下部に直置きし、理由・コメントの`Input`をボタンの隣にインライン
配置している。`SKILL.md` §2.4はPrimary CTAを右上、低頻度操作をOverflowとしているが、これらの
操作はその画面の主目的そのものであるため、`WorkflowRequestDetailPage.tsx`のコード上に
`Pattern exception:`/`Reason:`コメントを明記し、意図した逸脱として扱うことにした
(SKILL.md §2.3の「一覧の主目的そのものである操作は行内に直接置いてよい」という例外と同種)。
取消操作自体は`ConfirmActionDialog`での確認に統一済み(両画面)。

## 13. 個別のボタン単位のDisabled理由(解消)

リポジトリ内の全画面のDisabledな操作に理由テキストを追加した(§2.14)。
`pages/workCalendar/ShiftsPage.tsx`・`CalendarBulkOperationsPage.tsx`のような多数の
フォームが1画面に並ぶバッチ操作系ツールも含めて網羅対応済み。

## 残っている既知の項目(意図的に見送ったもの)

- `pages/expense/ExpenseClaimNewPage.tsx`のウィザードの各ステップ自体のURL化 —
  ステップが`claimId`/`entryMode`/保存済み明細の有無が絡み合った分岐で決まっており、
  安全にURL化するには状態構造自体の見直しが必要なため見送った(選択中の経費区分
  `?category=`のURL化は対応済み。§3参照)
- `DeviceDetailModal`等の`*Modal`系コンポーネントの命名統一(§10。入力項目数が少なく
  Page化の必要性は無いと判断し、`*Modal`のまま維持することを決定として明記した)
- `pages/expense/ExpenseClaimListPage.tsx`の「編集可能/削除可能」判定ロジックの共通化(§11)
