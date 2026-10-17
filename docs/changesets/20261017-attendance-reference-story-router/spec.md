# attendance-reference-story-router

- ステータス: 完了

## 変更要望(原文)

> Storybookのvitestプロジェクトで `AttendanceReferencePage.stories.tsx > No Employee Selected` が
> `useLocation() may be used only in the context of a <Router> component.` で失敗する。
> 他のページのstoryと同様にMemoryRouterで包んで直す。

## 原因

`AttendanceReferencePage`(トップレベルのページコンポーネント)が `useSearchParams` を呼ぶが、
storyの `withSeeded()` の描画関数は `QueryClientProvider` だけで包んでおり、Routerが無い。

## 修正方針

- `withSeeded()` の描画関数で `<AttendanceReferencePage />` を `react-router-dom` の `MemoryRouter` で包む。
- 既存の前例: `src/pages/attendance/WeekAttendancePage.stories.tsx`(Providerの内側で `<MemoryRouter>` を使う)。
- 初期URLは指定しない(社員未選択=クエリ無しの状態がこのstoryの意図)。

## 影響範囲

storyファイル1つのみ。ページ本体・本番コードは変更しない。

## テスト

- `npx vitest run --project=storybook src/pages/attendance/AttendanceReferencePage` が通る
  (クラウド環境では `VITEST_CHROMIUM_EXECUTABLE_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome` を指定)。
- `npx vitest run --project=unit` が引き続き通る。

## 結果

- storybook: 1件PASS(修正前はRouterエラーで失敗)。
- unit: 7件失敗・955件PASS。失敗(ApprovalDetailPanel 4件・ApprovalsPage 2件・BackOfficeTaskDetailPage 1件、
  `useAuth must be used within an AuthProvider`)は本変更を外しても同じ7件が失敗する既存の不具合で、本変更の対象外。
