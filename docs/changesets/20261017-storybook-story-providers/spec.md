# storybook-story-providers

- ステータス: 完了(2026-10-17 ユーザー指示「全て修正してください」)

## 変更要望(原文)

> (Storybookのvitestで失敗している既存の10件を)全て修正してください。

## 原因(`npx vitest run --project=storybook`、ブランチで未変更のstory)

| story | 件数 | エラー | 原因 |
|---|---|---|---|
| `pages/auth/OnboardingPage` > Default | 1 | `useNavigate() ... <Router>` | Routerが無い |
| `pages/compensatoryLeave/CompensatoryLeaveAdminPage` > Default | 1 | `useLocation() ... <Router>` | Routerが無い |
| `pages/notifications/NotificationsPage` > Default・Empty | 2 | `useLocation() ... <Router>` | Routerが無い |
| `pages/specialLeave/SpecialLeaveAdminPage` > With Types And Rules・No Types | 2 | `useLocation() ... <Router>` | Routerが無い |
| `components/ApprovalDetailPanel` > Attendance Month | 1 | `useAuth must be used within an AuthProvider` | 月次の表示(`MonthlyReferenceView`)が`useAuth`を呼ぶのにAuthContextが無い |
| `pages/workflow/WorkflowRequestListPage` > With Requests・Empty | 2 | `useAuth must be used within an AuthProvider` | AuthContextが無い |
| `components/UserPicker` > Open | 1 | `Unable to find role="option" ... 承認者花子` | 種まきのqueryKeyが古い |

UserPickerの原因: `useUserSearch`(`hooks/useUsers.ts:42-54`)のqueryKeyは
`['users', 'search', query, perPage, permission ?? '']`の5要素になったが、storyは4要素`['users', 'search', '', 100]`で
`setQueryData`しているため種まきが効かず、候補が出ない。同じ4要素の種まきが他のstory 13ファイルにも残っている
(失敗はしていないが種まきが効いていない)。

## 修正方針

- Routerが無いstory: 描画を`react-router-dom`の`MemoryRouter`で包む(前例: `pages/attendance/WeekAttendancePage.stories.tsx`)。
- AuthContextが無いstory: `AuthContext.Provider`で包む(前例: 同ファイル5-27行目の`authValue`)。
- `users`の種まき: `['users', 'search', <query>, 100]`を`['users', 'search', <query>, 100, '']`に直す(story 14ファイル・15行)。
- ページ・コンポーネント本体は変えない。

## テスト

- `npx vitest run --project=storybook`が全件通る。
- `npx vitest run --project=unit`・`npx tsc -b --noEmit`が通る。

## 結果

- `--project=storybook`: 140ファイル・298件すべてPASS(変更前は10件失敗)。unit 964件PASS・tsc OK。
- 種まきの対象は当初10ファイルと書いたが、委譲元(私)の調査で`grep | head`の出力を全件と取り違えていた。実際は14ファイル・15行で、全て修正した。
