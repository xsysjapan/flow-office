---
name: ui-design-system
description: Use when deciding how flow-office UI should look — design tokens, color, spacing, typography, radius, shadow, icons, the shadcn/ui + Radix primitives in components/ui/, component visual variants, date/time input components, and forbidden decorative patterns (React + TypeScript + Tailwind CSS v4 + lucide-react). Covers appearance only; how screens behave (CRUD flows, dialogs, save/cancel, loading/empty/error states) is defined in ui-interaction-patterns.
---

# UIデザインシステムに沿って見た目を決める

flow-officeは長時間使う業務アプリケーション。装飾的なWebサイトではなく、Linearの情報密度 /
Stripe Dashboardのフォームとデータ表示 / Notionの余白とタイポグラフィ / GitHubの実務的な
テーブルとステータス表現 / shadcn/uiのコンポーネント設計を参考にする。**特定サービスの
そのままの模倣はしない**。品質は装飾ではなく、明確な情報階層・一貫した余白・正確な整列・
読みやすいタイポグラフィ・適切な情報密度・控えめな配色で実現する。

**このスキルは「どう見せるか」だけを扱う**。どう操作するか(CRUD・保存/キャンセル/戻る・
Dialog/Sheet/Pageの使い分け・Loading/Empty/Error/Permission・URL状態・キーボード操作など)は
`.claude/skills/ui-interaction-patterns`が扱う。新規画面や画面刷新では両方を併用する。

---

## 1. Mandatory Rules(必ず守る)

1. **shadcn/uiまたはRadix UIに存在する部品を独自実装しない**。まず
   `frontend/src/components/ui/`(§2.2)を確認し、無ければshadcnの標準実装パターンに沿って
   `ui/`に追加してから使う。
2. **デザイントークンの唯一の情報源は`frontend/src/index.css`**。コンポーネント内に任意の
   色・文字サイズ・角丸・余白を直接書かない。`mt-[13px]`・`#3366ff`・`rounded-[10px]`の
   ような任意値クラスや生の16進色は禁止。
3. **色はSemantic Token経由のTailwindユーティリティでのみ使う**(§2.1)。
4. **業務ステータスの色は`frontend/src/utils/statusLabels.ts`のマッピング経由で決める**。
   コンポーネントは`tone`/`variant`のような抽象値を受け取るだけで、業務ステータスの意味を
   知らない。
5. **アイコンは`lucide-react`のみ**。絵文字をUIアイコンとして使わない。
6. **日付・時刻入力にネイティブ`<input type="date">`/`type="time">`を使わない**(§2.4)。
7. **既存ドメインコンポーネントの公開Props契約を変えない**(§3)。
8. **§4の禁止事項に該当する装飾を追加しない**。

---

## 2. Standard Patterns

### 2.1 デザイントークン

`frontend/src/index.css` の `:root` / `@media (prefers-color-scheme: dark)` / `@theme inline`
がトークンの唯一の定義場所。

- **色**: `bg-background` `text-foreground` `bg-card` `bg-popover` `bg-primary`
  `text-primary-foreground` `bg-secondary` `bg-muted` `text-muted-foreground` `bg-accent`
  `bg-destructive` `bg-success` `bg-warning` `bg-info` `border-border` `ring-ring`
- **余白**: 4pxまたは8px単位(Tailwind既定スケール。`gap-1`=4px, `gap-2`=8px, `p-4`=16px)。
  既定スケールにない値を使わない。
- **文字サイズ**: 本文は`text-sm`(14px)または`text-base`(16px)。見出しは`text-base`〜
  `text-lg`程度に留め、`text-3xl`のような巨大見出しは使わない。
- **角丸**: `--radius: 0.5rem`(8px)を基準にした`rounded-md`/`rounded-lg`。`rounded-full`は
  アバターやドットなど用途が明確な場合のみ。
- **影**: `shadow-sm`のみ許可し、Popover / Dialog / DropdownMenu / Sheetなど「浮いている」
  要素にのみ使う。Card / Buttonには影を使わず、`border`と`bg-card`/`bg-background`の差で
  表現する。

### 2.2 既存プリミティブ(`frontend/src/components/ui/`)

alert, badge, button, calendar, card, checkbox, command, dialog, dropdown-menu, input, label,
native-select, popover, select, separator, sheet, skeleton, table, tabs, textarea, tooltip。

これらは薄いRadixラッパー(cva + `cn()`)なので、`.stories.tsx`は代表バリエーションのみの
軽量storyでよく、`add-frontend-component`が要求するフル3点セットは求めない。ドメイン
コンポーネント(`frontend/src/components/<Name>/`)を作る/直すときは、これらの`ui/`
プリミティブを内部で使う。

### 2.3 Visual Variant

新しい見た目のバリエーションは`class-variance-authority`(`cva`)でvariantとして定義する
(`frontend/src/components/ui/badge.tsx`参照)。

- `primary` — 最も強い視覚的強調
- `secondary` / `ghost` — 副次的な操作
- `destructive` — 破壊的な操作

どの操作にどのvariantを割り当てるか(=どれをPrimary Actionとみなすか)は
`ui-interaction-patterns`が決める。このスキルは各variantの**外観**のみを定義する。
Disabledについても、見た目はこのスキル、使ってよい条件は`ui-interaction-patterns` §2.14。

### 2.4 日付・時刻入力

生の`<input type="date">`/`type="time">`(`ui/input.tsx`にネイティブ`type`を指定しただけの
もの)を直接使わない。必ず次のドメインコンポーネントを使う。

- `frontend/src/components/DatePicker/`(日付、値は`"YYYY-MM-DD"`文字列)
- `frontend/src/components/DateRangePicker/`(期間)
- `frontend/src/components/TimePicker/`(時刻)
- `frontend/src/components/DateTimePicker/`(日時)
- `frontend/src/components/YearMonthPicker/`(年月)

ブラウザ・OSごとに見た目が異なるネイティブpickerを避け、アプリ全体で同じ見た目・操作性
(カレンダーポップオーバー、相対日付ショートカット等)に揃えるため。既存コードの
`type="date"`の`Input`は、今回全画面を一括置換する対象ではない(§3参照)。今まさに
着手しているページ・コンポーネントの中で見つけた場合に限り`DatePicker`へ置き換える。

値が未選択でも対象日・対象期間が文脈上自明な入力(例: 7/15の勤怠編集画面にある新規追加欄)
では、初期表示を今日固定にせず、その対象日を基準にカレンダーを開く(`DatePicker`/
`DateTimePicker`/`DateRangePicker`の`defaultDate`)。対象が本当に不明な新規作成(トップ
レベルの「新規作成」ボタンなど)は今日のままでよい。

### 2.5 Visual Accessibility

- **色だけで意味を表現しない**。Badgeは常にテキストラベル付きにし、必要なら意味を持つ
  アイコンも添える。
- Focus Indicator(`:focus-visible`のリング。`index.css`の`@layer base`で定義済み)を
  消さない。
- テキストと背景のコントラストをトークンの組み合わせで確保する
  (`text-muted-foreground`を`bg-muted`の上に重ねるような低コントラストを作らない)。

### 2.6 PC / スマートフォンの見た目

テーブルは行の折り返しではなく、テーブル自体を`overflow-x-auto`のコンテナに入れて横
スクロールさせる(`ui/table.tsx`の`Table`は既に内蔵)。狭幅でナビゲーションをSheetの
ドロワーに切り替える判断は`ui-interaction-patterns`(Dialog/Sheet/Pageの使い分け)。

---

## 3. Exceptions: 段階的ロールアウト(公開Props契約を変えない)

`frontend/src/components/`配下のドメインコンポーネント(Button / Badge / Card / FormField /
ErrorMessage / LoadingState / UserPicker / Pagination など)は多数の画面から使われている。
**既存の公開props(`variant`, `tone`, `title`, `actions`, `error`, `label`, `required`など)を
変えずに内部実装だけをTailwind/shadcn化**すれば、まだ手を付けていないページも自動的に新しい
見た目・トークンを継承する。個別ページの構造(`<ul>`→`Table`など)を変えるのは、そのページ
自体に着手するときでよい。

デザイン刷新前の一部コンポーネントは`fo-`接頭辞の個別`.css`を使っているが、新規追加・刷新時は
個別CSSファイルを増やさず、`ui/`プリミティブ + Tailwindユーティリティで表現する。

---

## 4. 禁止事項

すべての情報をカードで囲う / 不要なグラデーション / 強い影 / 過剰な角丸 / 原色の多用 /
巨大な見出し / 不要なキャッチコピー / 意味のない中央揃え / 画面ごとに異なるデザインルール /
色だけに依存した状態表現 / 絵文字アイコン / 任意値クラス・生の16進色。

行内アクションの配置本数・Loading中のレイアウトシフト対策は操作設計の話であり
`ui-interaction-patterns` §2.3 / §2.16 が扱う(ここでは繰り返さない)。

---

## 5. Repository References

- トークン定義: `frontend/src/index.css`
- 状態色のマッピング: `frontend/src/utils/statusLabels.ts`
- プリミティブ: `frontend/src/components/ui/button.tsx`, `card.tsx`, `badge.tsx`
- プリミティブをラップしつつ既存props契約を維持するドメインコンポーネント:
  `frontend/src/components/Button/Button.tsx`, `frontend/src/components/Badge/Badge.tsx`

### 他スキルとの関係

- `.claude/skills/ui-interaction-patterns` — 操作。CRUD / Save / Cancel / Back /
  Dialog・Sheet・Pageの使い分け / Loading・Empty・Error・Permission / URL状態 /
  キーボード操作 / 実装前メモ。**新規画面・画面刷新ではこのスキルと併用する。**
- `.claude/skills/add-frontend-component` — ドメインコンポーネントの3点セット
  (component + stories + test)を作る手順。`ui/`配下のプリミティブは対象外(§2.2)。
- `.claude/skills/add-page` — ページ実装・ルーティング・ナビ登録の手順。**ナビのラベル/
  構造を変えるとe2eが壊れる**という同スキルの警告は、デザイン刷新でも最優先で引き継ぐ
  (見た目だけ変え、`navGroups`/`adminNavGroups`のラベル文字列やリンク構造は変えない)。
- `.claude/skills/add-api-hook` — 影響なし(データ取得層は対象外)。

---

## 6. Implementation Checklist

- [ ] 同じ用途の部品が同じ外観になっている(独自CSSで似て非なる見た目を作らない)
- [ ] 余白と整列に一貫性がある(4px/8pxスケール、任意値を使っていない)
- [ ] 色がSemantic Token経由で、業務ステータス色は`statusLabels.ts`を通っている
- [ ] 文字サイズが`text-sm`/`text-base`中心で、巨大見出しがない
- [ ] 影が浮遊要素(Popover/Dialog/DropdownMenu/Sheet)にしか使われていない
- [ ] 色と強調表現を使いすぎていない(バッジ・警告色は意味がある箇所のみ)
- [ ] 不要なカード、枠線、説明文、装飾がない
- [ ] 日付・時刻入力がDatePicker系コンポーネントを使っている
- [ ] アイコンが`lucide-react`のみで、絵文字を使っていない
- [ ] 空データ・大量データ・長い文字列でも崩れない(折り返し・省略を確認)
- [ ] スマートフォン幅で実際にレンダリングして崩れがない
- [ ] Focus Indicatorが消えていない
- [ ] 既存ドメインコンポーネントの公開propsを変えていない

---

## 7. Rationale

トークンを一元化し任意値を禁じるのは、27画面規模で同じ`14px`が場所によって`text-sm`と
`text-[14px]`に分岐すると、後からの一括調整(ダークモード・コントラスト・密度変更)が
不可能になるため。影を浮遊要素に限定し装飾を削るのは、業務アプリでは装飾が情報階層の
ノイズになり、長時間利用時の疲労とスキャン速度に直接効くため(Refactoring UI, Wathan &
Schoger)。審美性ユーザビリティ効果により見た目の一貫性は使いやすさの体感に効くので、
社内向け管理画面でも余白・整列の一貫性に手を抜かない。
