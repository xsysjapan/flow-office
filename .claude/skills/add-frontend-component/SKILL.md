---
name: add-frontend-component
description: Use when creating a new reusable UI component in frontend/src/components/ for flow-office (e.g. a new Button variant, a form input, a layout element). Guides the file layout (component + stories + test, styled with Tailwind CSS + the shadcn/ui-style primitives in components/ui/ per the ui-design-system skill) and flags the accessible-label pitfall found in FormField, so every component ships with a working Storybook story and a passing Vitest test.
---

# 新しいUIコンポーネントを追加する

flow-office のフロントエンドでは、`frontend/src/components/<Name>/` 配下のドメイン
コンポーネントは必ず「実装 + Storybook story + テスト」の3点セットで作る。story と
テストを後回しにしない(ユーザー指示: 「コンポーネントを作成した際はstoriesファイルを
作ってください」)。**`frontend/src/components/ui/` 配下のshadcn/ui風プリミティブは対象外**
(軽量storyのみでよく、フルテストは求めない。`.claude/skills/ui-design-system` §2.2)。

スタイリングは Tailwind CSS + shadcn/ui相当のプリミティブ(`frontend/src/components/ui/`)
を使う(見た目のルールは`.claude/skills/ui-design-system`)。コンポーネントが操作を伴う場合
(確認ダイアログ・フォーム・一覧行のアクション・状態表示など)は、その挙動を
`.claude/skills/ui-interaction-patterns`に合わせる(既存の`ConfirmActionDialog`のような
共通の操作コンポーネントがあれば、似たものを新規に作らない)。デザイン刷新前の一部コンポーネントは
まだ `fo-` 接頭辞の個別 `.css` ファイルを使っているが、新規追加や刷新時は個別CSSファイルを
増やさず、`ui/`プリミティブ + Tailwindユーティリティクラスで表現する。

参考実装: `frontend/src/components/Button/`(`ui/button`をラップし既存props契約を維持する例),
`frontend/src/components/Badge/`, `frontend/src/components/FormField/`

## ファイル構成

```
frontend/src/components/<ComponentName>/
  <ComponentName>.tsx           コンポーネント本体(スタイルはTailwind + ui/プリミティブ)
  <ComponentName>.stories.tsx   Storybook story (必須)
  <ComponentName>.test.tsx      Vitestテスト (必須)
```

## 手順

1. **Propsをシンプルに保つ**: 既存コンポーネント(`Button`, `Badge`, `Card`)のように、
   HTML標準属性を`extends`しつつ最小限の独自propsだけ追加する。ドメイン知識(勤怠/申請の
   状態など)をコンポーネント内に埋め込まない — 色分けなどのマッピングは
   `frontend/src/utils/statusLabels.ts` のような外部ユーティリティに置き、コンポーネントは
   汎用のまま(`Badge`がtoneを受け取るだけで、statusを知らないのと同じ)。

2. **スタイルはTailwindユーティリティ + `ui/`プリミティブで表現する**: 新しい見た目のバリエーション
   が必要なら `class-variance-authority`(`cva`)でvariantを定義する(`frontend/src/components/ui/badge.tsx`
   参照)。色・間隔・角丸のトークン規約は `.claude/skills/ui-design-system` に従う
   (ここでは繰り返さない)。

3. **Storybook story を書く**: `Meta<typeof Component>` + `satisfies` パターンで、
   props の代表的なバリエーションを1 storyずつ用意する(例: Button の Primary/Secondary/
   Danger/Loading/Disabled)。イベントハンドラは `import { fn } from 'storybook/test'` を
   `args` に渡す。コンポーネントが `useAuth()` や `useParams()` などcontext/routerに依存する
   場合は `decorators` で `MemoryRouter` や `AuthContext.Provider` をラップする
   (`frontend/src/components/AppLayout/AppLayout.stories.tsx` 参照)。

4. **テストを書く**: `@testing-library/react` + `@testing-library/user-event` で
   「表示される」「propsに応じて見た目/振る舞いが変わる」「イベントが発火する」を確認する。
   `render()`後の自動クリーンアップは `vitest.setup.ts` のグローバル `afterEach(cleanup)`
   が担うので個別に書かなくてよい。

5. **必須マーク等の装飾要素はaria-hiddenにし、ラベルと分離する**: `FormField`で実際に
   踏んだ罠 — `<label>`の中に`*`のようなテキストを直接入れると`label.textContent`が
   `"タイトル*"`になり、`getByLabelText('タイトル')`が完全一致せず失敗する
   (`aria-hidden`はDOMの`textContent`には影響しないため効かない)。装飾テキストは
   `<label>`の**兄弟要素**として配置し、Tailwindの`flex`/`gap`で隣接させる
   (`frontend/src/components/FormField/FormField.tsx`の実装を参考にする)。

## チェックリスト (実装後)

- [ ] `.stories.tsx` があり、代表的なprops組み合わせごとにstoryがある
- [ ] `.test.tsx` があり、`npm run test` で通る
- [ ] `npm run test:storybook` (storyをブラウザで描画するテスト) も通る
- [ ] `npm run build-storybook` が失敗しない
- [ ] `getByLabelText`/`getByRole`などのクエリが、装飾テキストの混入で壊れていない
- [ ] `npm run lint` (oxlint) が通る
