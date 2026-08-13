# 既存実装とInteraction Patternの差異(2026-08時点)

`SKILL.md`を策定した時点で、既存画面に残っている不統一の記録。新規画面は最初から`SKILL.md`
に従う。

**2026-08 追記**: カレンダー(`pages/workCalendar/`)と経費入力プリセット編集
(`pages/expense/ExpenseEntryPreset*`)を除く全画面にSKILL.mdを適用済み。以下の項目は
その対応状況を反映して更新した。カレンダー・プリセット編集画面は対象外のまま残っている
(別作業で編集中)。

## 1. 確認ダイアログが2種類ある(統合候補)

- `frontend/src/components/ConfirmDialog/` — trigger要素を渡す形(`DialogTrigger`)
- `frontend/src/components/ConfirmActionDialog/` — `triggerLabel`文字列 + variant、`children`で
  フォームを足せる

2026-08時点で `pages/expense/ExpenseClaimListPage.tsx` / `pages/expense/ExpenseClaimNewPage.tsx` は
`ConfirmActionDialog`へ移行済み。残る利用者は`pages/expense/ExpenseEntryPresetListPage.tsx`
(プリセット編集画面。今回のスコープ外・別作業で編集中)のみ。この画面の移行が完了したら
`ConfirmDialog`コンポーネント自体を削除できる。

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

`frontend/src/components/EmptyState/`を新規追加し、カレンダー・プリセット編集を除く全画面の
空状態をこのコンポーネントへ統一した。検索/フィルターが効く一覧ではInitial EmptyとFiltered
Empty(+条件クリアの導線)を区別している。共有コンポーネント`LeaveHistoryList`内部の空状態
文言自体は変更していない(呼び出し側でラップして対応)。

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

## 残っている既知の未対応項目(次のスコープの候補)

- `pages/admin/UserListPage.tsx`のページング(API側に`page`パラメータが無いため未導入)
- `pages/expense/ExpenseClaimNewPage.tsx`のウィザードのURL状態化
- `ConfirmDialog`コンポーネント自体の削除(`ExpenseEntryPresetListPage.tsx`の移行待ち。§1)
- Toast基盤の導入判断(§7)
- Modal系コンポーネントの命名統一(§10。カレンダー領域の`CreateCompanyCalendarModal`を
  含むため、カレンダー作業と合わせて判断する)
- セル内`Link`方式と行全体クリック方式、両方の行クリック実装の1方式への統一(§2)
