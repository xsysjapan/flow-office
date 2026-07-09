---
name: add-api-hook
description: Use when wiring the flow-office frontend to a new or existing backend API endpoint (e.g. a new resource under backend/routes/api.php). Guides adding the TypeScript type, the api/ client function, and the React Query hook, following the pattern in src/api/attendance.ts and src/hooks/useAttendance.ts.
---

# 新しいAPIリソースをフロントエンドに接続する

flow-office のフロントエンドは `frontend/src/api/` (fetchラッパーを呼ぶ関数) と
`frontend/src/hooks/` (React Queryフック) の2層でバックエンドAPIと通信する。ページ
コンポーネントはhookだけを使い、`apiFetch`やURLを直接扱わない。

参考実装: `frontend/src/api/attendance.ts` + `frontend/src/hooks/useAttendance.ts`

## 手順

1. **型を`frontend/src/api/types.ts`に追加する**: バックエンドの `App\Http\Resources\*`
   (例: `backend/app/Http/Resources/WorkCalendarResource.php`)が返すJSONの形と正確に
   一致させる。バックエンド側のResourceクラスを実際に読んで確認すること(思い込みで
   フィールド名を書かない)。一覧系エンドポイントがページネーションされる場合は
   `Paginated<T>` を使う。

2. **`frontend/src/api/<resource>.ts` に関数を追加する**: `apiFetch<T>(path, options)`
   (`frontend/src/api/client.ts`)を使い、1関数 = 1エンドポイントの薄いラッパーにする。
   ビジネスロジックを持たせない。

   ```ts
   import { apiFetch } from './client'
   import type { Paginated, SomeResource } from './types'

   export function fetchSomeResources(): Promise<Paginated<SomeResource>> {
     return apiFetch('/some-resources')
   }
   ```

3. **`frontend/src/hooks/use<Resource>.ts` にReact Queryフックを追加する**:
   - 参照系は `useQuery({ queryKey: [...], queryFn: ... })`
   - 更新系は `useMutation` + `onSuccess`で関連する`queryKey`を`invalidateQueries`する
     (どのキーを無効化すべきかは、更新後にどの画面が古いデータを表示しうるかで決める)
   - `queryKey`の先頭要素はリソース名で統一する(例: `['attendance', 'today']`,
     `['workflow-requests', 'mine']`)。複数箇所から同じキーを組み立てる場合は
     ファイル内でキー定数を1箇所に定義する。

4. **エラーは`ApiError`(`frontend/src/api/client.ts`)としてそのまま伝播させる**:
   hook層でtry/catchして握り込まない。ページ側で
   `<ErrorMessage error={...} />` (`frontend/src/components/ErrorMessage/`) に渡すことを
   前提にする。

## テスト方針

- api層の関数自体は薄いので個別テストは不要(`apiFetch`自体のテストは
  `frontend/src/api/client.test.ts` でカバー済み)。
- hookやそれを使うページのテストでは、`vi.spyOn(resourceApi, 'fetchSomeResources')`で
  api関数をモックし、実際のネットワーク呼び出しは発生させない
  (`frontend/src/pages/TodayAttendancePage.test.tsx` 参照)。

## チェックリスト (実装後)

- [ ] `api/types.ts` の型がバックエンドResourceの実際のフィールドと一致している
- [ ] api関数はエンドポイント1つにつき1関数、ロジックを持たない
- [ ] mutationの`onSuccess`で影響するqueryKeyを`invalidateQueries`している
- [ ] hookを使うテストは`vi.spyOn`でapi関数をモックしている(実ネットワーク呼び出しなし)
