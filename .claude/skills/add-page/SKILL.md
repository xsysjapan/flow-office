---
name: add-page
description: Use when adding a new routed screen to the flow-office frontend (e.g. a new page under frontend/src/pages/). Guides composing existing components/hooks into a page, registering the route in App.tsx, adding a nav entry in AppLayout, and writing the story + test, following the pattern in TodayAttendancePage and WorkflowRequestDetailPage.
---

# 新しいページ(画面)を追加する

flow-office のページは `frontend/src/pages/` に置き、`frontend/src/components/` の
コンポーネントと `frontend/src/hooks/` のAPIフックを組み合わせて作る。ページ自体は
`apiFetch`を直接呼ばない。

参考実装: `frontend/src/pages/TodayAttendancePage.tsx` (一覧+アクション),
`frontend/src/pages/WorkflowRequestDetailPage.tsx` (権限に応じてUIを分岐する例)

## 手順

1. **ページに必要なdocsのユースケースを確認する**: `docs/07`〜`docs/15` の該当UCを読み、
   誰が(申請者/承認者/管理者)何をできる画面かを確認する。

2. **`frontend/src/pages/<PageName>.tsx` を作る**:
   - データ取得は `.claude/skills/add-api-hook` で用意したhookを使う
   - ローディング/エラーは `LoadingState` / `ErrorMessage` コンポーネントで統一する
     (`if (isLoading) return <LoadingState />` / `if (error) return <ErrorMessage error={error} />`)
   - 見た目は `Card` / `Badge` / `Button` / `FormField` を組み合わせる。新しい見た目の
     部品が必要になったら先に `.claude/skills/add-frontend-component` でコンポーネントを
     切り出す(ページの中に直接複雑なJSXを書き続けない)。
   - ユーザーの役割によって表示するアクションが変わる場合、`useAuth()`の`user`と
     対象データの`applicant`/`approver`等を比較して分岐する
     (`WorkflowRequestDetailPage`の`isApplicant`/`isApprover`のように)。

3. **`frontend/src/App.tsx` にルートを追加する**:
   - 認証必須画面は `RequireAuth` (`frontend/src/auth/RequireAuth.tsx`) でラップされた
     `AppLayout` の子ルートとして追加する。
   - 一覧→詳細の画面がある場合、詳細は `:id` パラメータのルートにする
     (`react-router-dom`の`useParams`で読む)。

4. **ナビゲーションが必要なら`frontend/src/components/AppLayout/AppLayout.tsx`の
   `navItems`に追加する**。承認者専用など全員に見せたくないリンクは、`useAuth()`の
   `user.roles`を見て条件表示する。

5. **Storyを書く**: ページがhookでデータ取得する場合、`QueryClient`を作って
   `queryClient.setQueryData(queryKey, データ)`で該当queryKeyに値を仕込んでから
   `QueryClientProvider`でラップする(実ネットワーク呼び出しをStorybook上で発生させない)。
   `useAuth()`や`useParams()`に依存するページは`MemoryRouter`や
   `AuthContext.Provider`も一緒にラップする
   (`frontend/src/pages/WorkflowRequestDetailPage.stories.tsx`参照)。

6. **テストを書く**: ページが呼ぶapi関数を`vi.spyOn(resourceApi, 'fetchXxx')`でモックし、
   「ローディング→表示」「役割ごとに出るアクションが違う」「アクション実行でAPIが正しい
   引数で呼ばれる」を確認する。ルーティングを跨ぐ遷移がある場合は`MemoryRouter`+`Routes`
   で遷移先に目印となる要素を置いて確認する
   (`frontend/src/pages/WorkflowRequestNewPage.test.tsx`の遷移確認を参照)。

## チェックリスト (実装後)

- [ ] ページは`apiFetch`を直接呼ばず、hooksだけを使っている
- [ ] ローディング/エラー表示が`LoadingState`/`ErrorMessage`で統一されている
- [ ] `App.tsx`にルートを追加し、認証が必要なら`RequireAuth`配下に置いた
- [ ] 必要なら`AppLayout`のナビゲーションに追加した
- [ ] `.stories.tsx`でReact Queryのキャッシュを事前に仕込み、実APIを叩かずに描画できる
- [ ] `.test.tsx`でapi層をモックし、`npm run test`が通る
- [ ] `npm run test:storybook` と `npm run build` が通る
