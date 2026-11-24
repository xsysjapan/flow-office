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

`src/generated/historyEventTypes.ts` は backend の `php artisan events:export-history-types` が書き出す生成物(手で編集しない)。
履歴画面の表示名・詳細文(`src/utils/statusLabels.ts`)はこの型で網羅させており、漏れは `npm run build` の tsc で検出される。

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
- `add-frontend-component`・`add-api-hook`・`add-page`等のスキルに沿って1コンポーネント/
  1画面分完結する実装は、対象ドメイン名と参照コンポーネント名を指定して
  general-purposeサブエージェントに実装自体を委譲し、メインの会話コンテキストを
  消費させない(ルート`CLAUDE.md`参照)。独立した複数コンポーネントの追加は並列委譲する。

## テストの方針

確認はできる限り単体テスト(frontendはVitest、backendはPHPUnit)で行う。E2E(`e2e/`)は実行時間が増えているため、
主要導線に絞る(根拠: ユーザーの指示(2026-11-24))。

1. 実装は、単体テストで確かめられる形にする。
   - 画面の振る舞い(分岐・表示・入力の検証・文言・並び順など)は、部品と画面のテスト(Vitest + Testing Library。
     MSWなどでAPIを差し替える)で確かめる。
   - 業務ルールと文脈間の連鎖は、backendのUnit・Featureテストで確かめる(`domain-test`スキル)。
   - 判定・変換・整形のロジックは、画面の部品に埋め込まず、関数・hookに出してテストで直接呼べるようにする。
2. E2Eは主要導線(画面をまたぐ一連の操作が通ること)だけにする。
   - 新しいE2Eを足す前に、「単体テストで確かめられないか」を確かめる。足すときは理由を変更セットに書く。
   - 既存のspecにテストを足すより、既存のテストの中で確かめる方を選ぶ。
   - 文言・レイアウト・小さな分岐のためにE2Eを足さない。
3. 開発中の検証でE2Eを全件流さない。
   - 変えた画面を通るspecだけを流す(`grep`で特定する)。
   - 全件は、リリースの前やユーザーが求めたときに限る。
   - レイアウトの確認は、Playwrightの撮影の小さなスクリプトで十分(E2Eのspecにしない)。

## 開発でよく使うパターン (スキル)

- `add-frontend-component` — 新しいUIコンポーネント(story/test付き)を追加する
- `add-api-hook` — 新しいbackend APIエンドポイントに対応する型・APIクライアント関数・
  React Queryフックを追加する
- `add-page` — 新しい画面(ルーティング込み)を追加する
- `api-design` — backend APIの仕様(クエリ・JSON・レスポンス・OpenAPI)の決め方(真偽値の表現を含む)
- `ui-design-system` — 見た目(デザイントークン・色・余白・タイポグラフィ・角丸・影・
  `ui/`プリミティブ・Visual Variant・禁止する装飾)
- `ui-interaction-patterns` — 操作(CRUD・保存/キャンセル/戻る・Dialog/Sheet/Pageの使い分け・
  削除確認・Loading/Empty/Error/Permission・検索/フィルター/ページングとURL状態・
  キーボード操作・実装前メモ)。新規画面や画面刷新では`ui-design-system`と併用する
