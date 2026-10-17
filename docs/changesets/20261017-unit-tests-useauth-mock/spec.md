# unit-tests-useauth-mock

- ステータス: 完了

## 変更要望(原文)

> 実装をお願いします(`--project=unit` で既存の7件が `useAuth must be used within an AuthProvider` で失敗する件)

## 原因

`AttendanceReferencePage.tsx` の `MonthlyReferenceView` が `useAuth()` で権限
(`attendance.month_reopen`・`backoffice_task.execute`・`attendance.export`)を読む。
このビューを内部で描画する承認詳細パネル・承認待ち一覧・バックオフィスタスク詳細のテストは
AuthProviderを用意していないため例外になる。本番では全画面がAuthProviderの内側にあるため、
アプリ側の不具合ではなくテストの前提不足。

失敗しているテスト:
- `src/components/ApprovalDetailPanel/ApprovalDetailPanel.test.tsx`(4件)
- `src/pages/approvals/ApprovalsPage.test.tsx`(2件)
- `src/pages/backOffice/BackOfficeTaskDetailPage.test.tsx`(1件。原因は別。論点4)

## 仕様検討

### 論点1: どこで直すか
- 選択肢: A) `MonthlyReferenceView` がAuthProvider外でも動くようにする / B) テストで認証を用意する
- 決定: B
- 理由: 本番ではAuthProvider外で描画されることはなく、`useAuth` の「Provider外は例外」という契約を
  緩めると本当の配線漏れを隠す。

### 論点2: 認証の用意の仕方
- 選択肢: A) `AuthContext.Provider` で包む / B) `vi.mock('../../auth/useAuth')`
- 決定: B
- 理由: `AttendanceReferencePage.test.tsx` に同じ前例があり、AuthContextValueの他の項目(login等)を
  用意する必要がない。

### 論点3: テスト用ユーザーの権限
- 承認詳細パネル・承認待ち一覧: `effective_permissions: []`(承認者は締め・出力の権限を前提としない。
  検証対象は月次・週次・日次の切り替えと承認APIの呼び分けで、権限ボタンは関係しない)。
- バックオフィスタスク詳細: 変更しない。このテストには既に `vi.mock('../../auth/useAuth')`(`assignee`)があり、
  useAuth由来の失敗ではなかった(当初の調査で `AuthContext.Provider` だけを検索して見落とした)。

### 論点4: バックオフィスタスク詳細の残り1件(`downloads CSV and Excel for the attendance month from the task detail`)
- 原因: 変更セット`20261015-excel-export-submitted-month`(2026-10-16決定)で、月次勤怠の「出力」カード
  (`MonthlyReferenceView`。申請詳細・バックオフィスタスク詳細・勤怠参照で共通)はCSV・Excelとも
  `include_submitted: true` を付けて呼ぶことにした。このテストの期待値が更新されずに残っていた。
- 決定: テストの期待値(CSV・Excelの両方)に `include_submitted: true` を足す。アプリのコードは仕様どおりのため変えない。

## 対象外

アプリのコード(`AttendanceReferencePage.tsx`・`useAuth.ts`)は変更しない。

## テスト

- `npx vitest run --project=unit` が全件通る。
- `npx tsc -b --noEmit` が通る。

## 結果

- unit: Test Files 137 passed、Tests 962 passed | 2 skipped(修正前 7 failed)。
- `tsc -b --noEmit`: exit 0。
