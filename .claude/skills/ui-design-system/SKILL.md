---
name: ui-design-system
description: Use when creating or redesigning any flow-office frontend screen or shared component (React + TypeScript + Tailwind CSS + shadcn/ui + Radix UI + lucide-react). Guides the Linear/Stripe/Notion/GitHub-inspired design language, the token rules (4px/8px spacing, 14px/16px type, 8px radius, minimal shadow), forbidden decorative patterns, the pre-implementation write-up, and the post-implementation QA checklist.
---

# UIデザインシステムに沿って画面・コンポーネントを作る

flow-officeは長時間使う業務アプリケーション。装飾的なWebサイトではなく、Linearの情報密度 /
Stripe Dashboardのフォームとデータ表示 / Notionの余白とタイポグラフィ / GitHubの実務的な
テーブルとステータス表現 / shadcn/uiのコンポーネント設計を参考にする。**特定サービスの
そのままの模倣はしない**。品質は装飾ではなく、明確な情報階層・一貫した余白・正確な整列・
読みやすいタイポグラフィ・適切な情報密度・控えめな配色・予測可能な操作・状態の分かりやすい
フィードバックで実現する。

## 技術構成

- React + TypeScript + Tailwind CSS v4 + shadcn/ui相当のコンポーネント + Radix UI
- **shadcn/ui または Radix UIに存在する部品は独自実装しない**。まず
  `frontend/src/components/ui/`(下記「既存プリミティブ」)を確認し、無ければ
  shadcnの標準実装パターンに沿って`ui/`に追加してから使う。
- アイコンは`lucide-react`のみ。絵文字はUIアイコンとして使わない。
- 参考実装: `frontend/src/components/ui/button.tsx`, `card.tsx`(shadcn風プリミティブ),
  `frontend/src/components/Button/Button.tsx`, `Badge/Badge.tsx`
  (プリミティブをラップしつつ既存props契約を維持するドメインコンポーネント)。

## デザイントークン(一元管理・唯一の情報源)

`frontend/src/index.css` の `:root` / `@media (prefers-color-scheme: dark)` / `@theme inline`
がトークンの唯一の定義場所。**コンポーネント内に任意の色・文字サイズ・角丸・余白を直接
書かない**(`mt-[13px]`, `#3366ff`, `rounded-[10px]`のような任意値クラスや生の16進色は禁止)。

- **色**: `bg-background` `text-foreground` `bg-card` `bg-popover` `bg-primary`
  `text-primary-foreground` `bg-secondary` `bg-muted` `text-muted-foreground` `bg-accent`
  `bg-destructive` `bg-success` `bg-warning` `bg-info` `border-border` `ring-ring` の
  Tailwindユーティリティ経由でのみ色を使う。状態色(success/warning/danger/info)は
  `frontend/src/utils/statusLabels.ts`のマッピング経由でのみ選ぶ(コンポーネント側は
  `tone`/`variant`のような抽象値を受け取るだけで、業務ステータスの意味を知らない)。
- **余白**: 4pxまたは8px単位(Tailwindの既定スケール、`gap-1`=4px, `gap-2`=8px, `p-4`=16px
  など)を基本とする。既定スケールにない値を使わない。
- **文字サイズ**: 本文は`text-sm`(14px)または`text-base`(16px)を基本とする。見出しは
  `text-base`〜`text-lg`程度に留め、`text-3xl`のような巨大見出しは使わない
  (禁止事項「巨大な見出し」)。
- **角丸**: `--radius: 0.5rem`(8px)を基準にした`rounded-md`/`rounded-lg`を基本とする。
  `rounded-full`はアバターやドットなど用途が明確な場合のみ。
- **影**: `shadow-sm`のみ許可し、Popover/Dialog/DropdownMenu/Sheetなど「浮いている」
  要素にのみ使う。Card/Buttonには影を使わず、`border`と`bg-card`/`bg-background`の
  差で表現する(禁止事項「強い影」「不要なグラデーション」)。

## 既存プリミティブ(`frontend/src/components/ui/`)

button, badge, card, input, label, textarea, select, checkbox, table, dialog,
dropdown-menu, tooltip, separator, skeleton, alert, popover, command, sheet が既にある。
これらは薄いRadixラッパー(cva + `cn()`)なので、`.stories.tsx`は代表バリエーションのみの
軽量storyでよく、`add-frontend-component`が要求するフル4点セット(story+test+ドメイン知識
分離)は求めない。ドメインコンポーネント(`frontend/src/components/<Name>/`)を作る/直す
ときは、これらの`ui/`プリミティブを内部で使うこと。

## 禁止事項

すべての情報をカードで囲う / 不要なグラデーション / 強い影 / 過剰な角丸 / 原色の多用 /
巨大な見出し / 不要なキャッチコピー / 意味のない中央揃え / 画面ごとに異なるデザインルール /
モーダルの乱用 / テーブル内への大きなボタンの大量配置(行内アクションは
`ghost`/`icon`サイズのButtonか`DropdownMenu`に集約する) / 色だけに依存した状態表現
(Badgeは常にテキストラベル付き、必要なら意味を持つアイコンも添える) / Loading中の
レイアウトシフト(`Skeleton`は最終コンテンツと同程度の高さ・行数を確保する)。

## 段階的ロールアウトの原則: 公開Props契約を変えない

`frontend/src/components/`配下のドメインコンポーネント(Button/Badge/Card/FormField/
ErrorMessage/LoadingState/UserPicker)は27画面から使われている。**既存の公開props
(`variant`, `tone`, `title`, `actions`, `error`, `label`, `required`など)を変えずに
内部実装だけをTailwind/shadcn化**すれば、まだ手を付けていないページも自動的に新しい
見た目・トークンを継承する。個別ページの構造(`<ul>`→`Table`など)を変えるのは、その
ページ自体に着手するときでよい。

## 実装前に提示する9項目

新規/刷新する画面ごとに、着手前に**簡潔に**(チャットに長文を貼らず、短いメモ程度に)
まとめる。

1. 画面の目的
2. 主要ユーザー
3. 最重要操作
4. 情報の優先順位
5. レイアウト構成
6. 使用する共通コンポーネント(`ui/`プリミティブ・ドメインコンポーネントを具体名で)
7. PCとスマートフォンでの違い
8. Loading、Empty、Error、Disabled状態
9. デザイン上の判断と理由

## PC/スマートフォン対応

- ナビゲーションのようにPCで横並び/サイドバーの要素は、狭幅で`ui/sheet.tsx`の
  ドロワーに切り替える。
- テーブルは行の折り返しではなく、テーブル自体を`overflow-x-auto`のコンテナに入れて
  横スクロールさせる(`ui/table.tsx`の`Table`は既にこれを内蔵している)。
- キーボード操作(Tab移動・Enter/Escape・矢印キー)とフォーカス表示
  (`:focus-visible`のリング、`index.css`の`@layer base`で定義済み)が常に機能すること。

## 実装後の品質チェックリスト

- [ ] 同じ用途の部品が同じ外観と挙動になっている(独自CSSで似て非なる見た目を作らない)
- [ ] 余白と整列に一貫性がある(4px/8pxスケール、任意値を使っていない)
- [ ] 主要操作が明確である(1画面に強い視覚的プライマリボタンは基本1つ)
- [ ] 色と強調表現を使いすぎていない(バッジ・警告色は意味がある箇所のみ)
- [ ] 不要なカード、枠線、説明文、装飾がない
- [ ] 空データ・大量データ・長い文字列でも崩れない(Empty state・折り返し・省略を確認)
- [ ] Loading、Error、権限不足の状態が用意されている(`LoadingState`/`ErrorMessage`を
      使い、レイアウトシフトがない)
- [ ] スマートフォンでも操作可能である(狭幅で実際にレンダリングして確認する)
- [ ] キーボードのみで主要操作を完了できる
- [ ] 既存のデザインシステム(このスキルのトークン・禁止事項)から逸脱していない

## 他スキルとの関係

- `add-frontend-component`: ドメインコンポーネント(`frontend/src/components/<Name>/`)の
  4点セット(component + stories + test、CSSファイルではなくTailwindユーティリティ/cvaで
  スタイリングする)を作るときに併用する。`ui/`配下のプリミティブはこのスキルの対象外
  (上記「既存プリミティブ」参照)。
- `add-page`: ページ実装・ルーティング・ナビ登録の手順は変わらない。**ナビのラベル/構造を
  変えるとe2eが壊れる**という同スキルの警告は、デザイン刷新でも最優先で引き継ぐ
  (見た目だけ変え、`navGroups`/`adminNavGroups`のラベル文字列やリンク構造は変えない)。
- `add-api-hook`: 影響なし(データ取得層はこのスキルの対象外)。
