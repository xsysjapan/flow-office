# attendance-months-story-auth

- ステータス: 完了(2026-10-17 ユーザー指示「このタスクをここで実行」)

## 変更要望(原文)

> 「Fix AttendanceMonthsPage story useAuth failures」をここで実行する。

## 原因

`AttendanceMonthsPage.tsx:24`が`useAuth()`を呼ぶ(入社日・退職日から表示する年月の範囲を決める)が、
`AttendanceMonthsPage.stories.tsx`の`withSeeded()`は`QueryClientProvider`と`MemoryRouter`だけで包み、AuthContextが無い。
Storybookのvitest(`--project=storybook`)で`With Months`・`Empty`の2件が`useAuth must be used within an AuthProvider`で失敗する。

## 修正方針

- `withSeeded()`の描画を`AuthContext.Provider`で包む。前例: `src/pages/attendance/WeekAttendancePage.stories.tsx:5-27`
  (`AuthContextValue`に`user`・`status: 'authenticated'`・`fn()`の各関数)。
- 利用者には`hire_date: '2025-04-01'`を持たせる(storyのデータ`2026-05`・`2026-06`が入社後の表示範囲に入るように)。

## 影響範囲

storyファイル1つのみ。ページ本体は変えない。

## テスト

- `npx vitest run --project=storybook src/pages/attendance`がすべて通る。
- `npx vitest run --project=unit`が引き続き通る。

## 結果

- `--project=storybook src/pages/attendance`: 6ファイル・11件PASS。unit 964件PASS・tsc OK。
- 参考: storybook全体では他に10件が失敗する(ApprovalDetailPanel・UserPicker・OnboardingPage・CompensatoryLeaveAdminPage・
  NotificationsPage・SpecialLeaveAdminPage・WorkflowRequestListPageのstory)。いずれもこのブランチでstoryを変更していない既存の失敗で、本変更セットの対象外。
