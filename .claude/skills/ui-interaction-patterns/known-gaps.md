# 既存実装とInteraction Patternの差異(2026-08時点)

`SKILL.md`を策定した時点で、既存画面に残っている不統一の記録。新規画面は最初から`SKILL.md`
に従う。

**2026-08 追記**: 経費入力プリセット編集(`pages/expense/ExpenseEntryPreset*`)・カレンダー
(`pages/workCalendar/`・`components/CreateCompanyCalendarModal/`)とも、別作業でのmain統合後に
追って対応し、リポジトリ内の全画面がSKILL.mdの対象になった。以下の項目はその対応状況を
反映して更新した。

## 1. 確認ダイアログが2種類あった(解消)

- `frontend/src/components/ConfirmDialog/` — trigger要素を渡す形(`DialogTrigger`)
- `frontend/src/components/ConfirmActionDialog/` — `triggerLabel`文字列 + variant、`children`で
  フォームを足せる

`pages/expense/ExpenseClaimListPage.tsx` / `pages/expense/ExpenseClaimNewPage.tsx` /
`pages/expense/ExpenseEntryPresetListPage.tsx`をすべて`ConfirmActionDialog`へ移行し、
利用者が0件になった`ConfirmDialog`コンポーネント自体(`.tsx`/`.test.tsx`/`.stories.tsx`)を
削除した。確認ダイアログは今後`ConfirmActionDialog`のみを使う。

## 2. 行クリックの実装が3種類(部分解消)

- セル内`Link`のみ(`pages/expense/ExpenseClaimListPage.tsx`, `pages/admin/UserListPage.tsx`)
- 行全体`onClick`+`cursor-pointer`で詳細ダイアログ、キーボード到達性あり
  (`pages/admin/DeviceListPage.tsx`: `role="button"`/`tabIndex`/Enter・Space対応、
  `pages/approvals/ApprovalsPage.tsx`: 行クリック+タイトルは独立した`<button>`)

セル内`Link`方式と行全体クリック方式の2種は残る(どちらも§2.2の「行クリック→詳細」原則
自体には沿っているため、キーボード到達性がある前提で許容)。全画面を1方式に統一する場合は
別タスクとして扱う。

## 3. URL状態(概ね解消)

- `pages/admin/UserListPage.tsx` — 検索・フィルターをURLに反映済み。ページングは
  `api/users.ts`の`fetchUsers`が`page`パラメータを持たないため未導入(API変更が必要なため
  今回のスコープ外)
- `pages/approvals/ApprovalsPage.tsx` — `status`/`yearMonth`/`page`/`requestId`すべてURL
  同期済み
- `pages/admin/AuditLogPage.tsx`, `pages/attendance/AttendanceMonthsPage.tsx`,
  `pages/attendance/AttendanceReferencePage.tsx`, `pages/paidLeave/PaidLeaveHistoryAdminPage.tsx`,
  `pages/specialLeave/SpecialLeaveHistoryAdminPage.tsx`,
  `pages/backOffice/BackOfficeTaskListPage.tsx` もURL同期済み
- `pages/expense/ExpenseClaimNewPage.tsx` — `?category=`を読むが書かない(未対応、
  ウィザード構造の変更を伴うため見送り)
- `pages/expense/ExpenseEntryPresetListPage.tsx` — 検索語(`q`)・経費区分での絞り込み
  (`category_id`)・ページをすべてURL同期済み
- ソートのURL反映例は無し(該当画面が現状無い)

## 4. 保存後の遷移が非対称(解消)

- `pages/expense/ExpenseCategoryEditPage.tsx` — 作成/更新とも一覧へ`navigate`
- `pages/admin/GroupDetailPage.tsx` — 作成後も詳細画面(自身)に留まるよう修正し、更新時と
  対称にした

## 5. Create/Editの画面分割方針が不統一

`/new`と`/:id`を1ページで共用(`isCreate`/`isNew`分岐)する画面と、`pages/admin/UserListPage.tsx`の
ようにカード内にインラインフォームをトグル展開する画面、`pages/expense/ExpenseClaimNewPage.tsx`の
ようにpage内ローカルコンポーネントでウィザードを組む画面が混在。

## 6. Bulk Actionsの配置(解消)

`pages/workflow/WorkflowRequestListPage.tsx`の理由入力を伴う一括取消は、確認なしの
インライン実行から`ConfirmActionDialog`での確認に変更し、`pages/approvals/ApprovalsPage.tsx`
と同様に「トリガーのみをactionsに置き、詳細はダイアログ内」という配置に揃えた。

## 7. Toast基盤が存在しない

`toast`/`sonner`相当の実装がリポジトリに無い。成功フィードバックは画面遷移・一覧更新・
`ErrorMessage`のみ。導入するかどうかは別タスクで判断する。導入する場合も、ユーザーが対応を
要するErrorをToastだけにしない。

## 8. Empty Stateの共通化(解消)

`frontend/src/components/EmptyState/`を新規追加し、カレンダーを除く全画面(経費入力プリセット
一覧`ExpenseEntryPresetListPage.tsx`を含む)の空状態をこのコンポーネントへ統一した。検索/
フィルターが効く一覧ではInitial EmptyとFiltered Empty(+条件クリアの導線)を区別している。
共有コンポーネント`LeaveHistoryList`内部の空状態文言自体は変更していない(呼び出し側で
ラップして対応)。

## 9. Permission Denied状態(部分解消)

`frontend/src/components/PermissionDenied/`を新規追加した。ページ全体がアクセス不能になる
ケース(`AdminDashboardPage`でアクセス可能な管理セクションが0件、`BackOfficeTaskDetailPage`/
`BackOfficeTaskListPage`の403応答)に導入済み。個別ボタンの非表示方式
(`isApplicant`/`isApprover`等)は今回維持している(ページ単位でアクセス不能になるケースと
ボタン単位の権限分岐は区別して扱う)。

## 10. Modal系コンポーネントの命名・構成が不統一

`CreateCompanyCalendarModal` / `DeviceDetailModal` / `WorkStyleFormModal` /
`MonthlyAttendanceBulkEntryModal` / `WeeklyAttendanceBulkEntryModal` が個別に存在し、いずれも
`ui/dialog`をラップしている。`*Modal`と`*Dialog`の命名が混在。`Sheet`は`AppLayout`/`AdminLayout`の
モバイルナビ専用で、Filter用途には未使用。

## 11. 「編集可能/削除可能」判定の置き場所が画面ごと

`pages/expense/ExpenseClaimListPage.tsx`はコンポーネント内でstatus文字列比較、
`pages/workflow/WorkflowRequestListPage.tsx`はモジュールトップレベルの定数配列
(`CANCELLABLE_STATUSES`)。共通化されていない。

## 12. Detail Pageのアクション配置(Pattern exceptionとして明記)

`pages/expense/ExpenseClaimDetailPage.tsx` / `pages/workflow/WorkflowRequestDetailPage.tsx`は
承認/差戻し/取消/提出を画面下部に直置きし、理由・コメントの`Input`をボタンの隣にインライン
配置している。`SKILL.md` §2.4はPrimary CTAを右上、低頻度操作をOverflowとしているが、これらの
操作はその画面の主目的そのものであるため、`WorkflowRequestDetailPage.tsx`のコード上に
`Pattern exception:`/`Reason:`コメントを明記し、意図した逸脱として扱うことにした
(SKILL.md §2.3の「一覧の主目的そのものである操作は行内に直接置いてよい」という例外と同種)。
取消操作自体は`ConfirmActionDialog`での確認に統一済み(両画面)。

## 13. 個別のボタン単位のDisabled理由・削除・確認の統一(解消)

各画面のDisabledな操作に理由テキストを追加し、確認ダイアログは新規に発明せず
`ConfirmActionDialog`に統一した(§1-6, §1-7, §2.14)。

## 14. 経費入力プリセット編集(解消)

`pages/expense/ExpenseEntryPresetEditPage.tsx`に欠けていた`[キャンセル]`を追加し(§2.6)、
保存ボタンの用語を新規作成時は「作成する」・編集時は「保存する」に分けた(§2.7)。Disabled
理由(名称未入力/経費区分未選択)を明示した(§2.14)。`pages/expense/
ExpenseEntryPresetListPage.tsx`は§1・§3・§8で述べた通り対応済み。

## 15. カレンダー画面の`window.confirm()`(解消・最重要)

`pages/workCalendar/WorkCalendarListPage.tsx`(カレンダー削除)・
`pages/workCalendar/WorkCalendarDetailPage.tsx`(祝日iCalendarソース削除)・
`pages/workCalendar/WorkCalendarDaysPage.tsx`(年度削除・年度再作成)の4箇所が、
Mandatory Rule 7(確認UIを新規に発明しない)に反してブラウザ標準の`window.confirm()`を
使っていた。すべて`ConfirmActionDialog`に置き換え、対象名と不可逆であることを明示する
確認文言にした。`pages/workCalendar/CalendarBulkOperationsPage.tsx`の一括操作取消
(「取消す」)は確認自体が無かったため、同様に`ConfirmActionDialog`を新規に追加した。

## 16. カレンダー画面のEmpty State・用語(解消)

`pages/workCalendar/WorkCalendarListPage.tsx`・`WorkCalendarDetailPage.tsx`(年度一覧)・
`ShiftsPage.tsx`(シフト一覧・シフトパターン・ローテーションパターン)・
`WorkStylesPage.tsx`(勤務形態一覧・割当履歴)・`CalendarBulkOperationsPage.tsx`(操作履歴)の
空状態を`EmptyState`へ統一した。`WorkStylesPage.tsx`の「新規登録」は「新規作成」に変更した
(§2.7、対応するe2e `scenario-00-master-data.spec.ts`/`scenario-12-calendar-lifecycle.spec.ts`
を更新)。祝日iCalendarソースの「登録する」は業務用語としての例外のまま維持し、
`CreateCompanyCalendarModal.tsx`/`WorkCalendarDetailPage.tsx`のdocblockに
`Pattern exception:`を明記した。

## 残っている既知の未対応項目(次のスコープの候補)

- `pages/admin/UserListPage.tsx`のページング(API側に`page`パラメータが無いため未導入)
- `pages/expense/ExpenseClaimNewPage.tsx`のウィザードのURL状態化
- Toast基盤の導入判断(§7)
- Modal系コンポーネントの命名統一(§10。`CreateCompanyCalendarModal`は入力項目数が多く
  §2.11の「入力項目が増え始めたDialogをそのまま巨大化させない」に照らすとPage化も
  検討候補だが、今回は大規模な構造変更を避けるため見送った)
- セル内`Link`方式と行全体クリック方式、両方の行クリック実装の1方式への統一(§2)
- `pages/workCalendar/ShiftsPage.tsx`・`CalendarBulkOperationsPage.tsx`は多数のフォームが
  1画面に並ぶバッチ操作系ツールで、個別のDisabledボタンすべてに理由テキストを付ける
  網羅対応は今回見送った(該当ボタンは`required`マーク付きFormFieldの未入力に連動する
  自明な理由のみで、個別画面のUserManagementAccessPageと同様の理由による)
