---
name: add-frontend-component
description: Use when creating a new reusable UI component in frontend/src/components/ for flow-office (e.g. a new Button variant, a form input, a layout element). Guides the file layout (component + css + stories + test) and flags the accessible-label pitfall found in FormField, so every component ships with a working Storybook story and a passing Vitest test.
---

# 新しいUIコンポーネントを追加する

flow-office のフロントエンドでは、`frontend/src/components/` 配下のコンポーネントは必ず
「実装 + スタイル + Storybook story + テスト」の4点セットで作る。story とテストを後回しに
しない(ユーザー指示: 「コンポーネントを作成した際はstoriesファイルを作ってください」)。

参考実装: `frontend/src/components/Button/`, `frontend/src/components/Badge/`,
`frontend/src/components/FormField/`

## ファイル構成

```
frontend/src/components/<ComponentName>/
  <ComponentName>.tsx           コンポーネント本体
  <ComponentName>.css           スコープされたスタイル(クラス名は fo-<kebab-name> 接頭辞)
  <ComponentName>.stories.tsx   Storybook story (必須)
  <ComponentName>.test.tsx      Vitestテスト (必須)
```

## 手順

1. **Propsをシンプルに保つ**: 既存コンポーネント(`Button`, `Badge`, `Card`)のように、
   HTML標準属性を`extends`しつつ最小限の独自propsだけ追加する。ドメイン知識(勤怠/申請の
   状態など)をコンポーネント内に埋め込まない — 色分けなどのマッピングは
   `frontend/src/utils/statusLabels.ts` のような外部ユーティリティに置き、コンポーネントは
   汎用のまま(`Badge`がtoneを受け取るだけで、statusを知らないのと同じ)。

2. **CSSはコンポーネントスコープのクラス名にする**: `fo-button`, `fo-badge--success` のように
   接頭辞を付け、グローバルセレクタを汚染しない。色・間隔は `frontend/src/index.css` の
   CSS変数(`--text`, `--border`など)を使う。

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
   `<label>`の**兄弟要素**として配置し、CSSで隣接させる
   (`frontend/src/components/FormField/FormField.tsx`の実装を参考にする)。

## チェックリスト (実装後)

- [ ] `.stories.tsx` があり、代表的なprops組み合わせごとにstoryがある
- [ ] `.test.tsx` があり、`npm run test` で通る
- [ ] `npm run test:storybook` (storyをブラウザで描画するテスト) も通る
- [ ] `npm run build-storybook` が失敗しない
- [ ] `getByLabelText`/`getByRole`などのクエリが、装飾テキストの混入で壊れていない
- [ ] `npm run lint` (oxlint) が通る
