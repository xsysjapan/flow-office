# frontend/

Vite + React + TypeScript の SPA。設計原則の全体像はリポジトリルートの `CLAUDE.md` を参照。
バックエンドAPIの仕様(エンドポイント・レスポンス形状)は `docs/06`〜`docs/17` にある。

## セットアップ

```
cd frontend
npm install
cp .env.example .env   # VITE_API_BASE_URL をbackendのURLに合わせる
npm run dev             # http://localhost:5173
npm run storybook       # http://localhost:6006
npm run test             # Vitest単体テスト(jsdom)
```

## ディレクトリ構成

```
src/
├── api/<domain>.ts         fetchラッパー。型定義はこのファイル内 or api/types.ts
├── hooks/use<Domain>.ts    React Query hook。api/<domain>.ts の1関数に対応
├── components/<Name>/      Name.tsx + Name.stories.tsx + Name.test.tsx の3点セット
│   └── ui/                 shadcn/ui風の共通プリミティブ(ui-design-systemスキル参照)
├── pages/<domain>/          画面。既存コンポーネント・hookを組み合わせるだけにする
├── auth/                    Sanctumトークンの保持・認証状態
└── lib/, utils/             横断的な小道具
```

`api/<domain>.ts` → `hooks/use<Domain>.ts` → `pages/<domain>/*.tsx` の対応関係は
ファイル名(ドメイン名)で1対1に揃えてあるので、機能追加時はこの3ファイルだけを見れば足りる
ことが多い(他ドメインのファイルを読む必要は基本的にない)。

## 効率的なコード参照

- 「〇〇ドメインの画面を直す」場合、`pages/<domain>/` → 対応する `hooks/use<Domain>.ts` →
  `api/<domain>.ts` の3ファイルだけを読めば足りることが多い。`components/`配下は
  実際に使われているコンポーネント名がわかってから該当ディレクトリだけを開く。
- 新規コンポーネント作成時は既存の類似コンポーネント1つ(例: `FormField`)を参照実装として
  読めば十分。`components/`全体を走査する必要はない。
- 横断的な調査(「このpropsを使っている箇所を全部探す」等)はGrepで対象文字列を検索する。
  読み込み範囲が広がりそうな場合はExploreサブエージェントに委譲する。

## 開発でよく使うパターン (スキル)

- `add-frontend-component` — 新しいUIコンポーネント(story/test付き)を追加する
- `add-api-hook` — 新しいbackend APIエンドポイントに対応する型・APIクライアント関数・
  React Queryフックを追加する
- `add-page` — 新しい画面(ルーティング込み)を追加する
- `ui-design-system` — 画面・共通コンポーネントのデザイン言語
