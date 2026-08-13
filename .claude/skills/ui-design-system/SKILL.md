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

## 日付・時刻入力

日付・時刻を入力させる箇所では、生の`<input type="date">`/`type="time">`(shadcn風の
`ui/input.tsx`にネイティブ`type`を指定しただけのもの)を直接使わない。必ず
`frontend/src/components/DatePicker/DatePicker.tsx`(日付、値は`"YYYY-MM-DD"`文字列)・
`frontend/src/components/TimePicker/`(時刻)・`frontend/src/components/DateTimePicker/`
(日時)のいずれかのドメインコンポーネントを使う。ブラウザ・OSごとに見た目が異なる
ネイティブpickerを避け、アプリ全体で同じ見た目・操作性(カレンダーポップオーバー、
相対日付ショートカット等)に揃えるため。新しい画面・コンポーネントを作るときはもちろん、
既存コードに`type="date"`の`Input`を見つけたら気付いた範囲で`DatePicker`に置き換える。

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

## UI設計の心構え(見た目の前に決めること)

トークン・禁止事項は「見た目」のルール。以下は画面を組む前に決める「考え方」で、
実装前の9項目メモを書く際の判断材料にする(出典: Nielsen Norman Groupのユーザビリティ
10ヒューリスティクス、Refactoring UI (Wathan & Schoger))。

- **画面はユーザーの作業単位で設計する。DBスキーマの形をそのまま画面にしない。**
  テーブル・カラムの並びではなく「このユーザーが何を達成したいか」を主語に情報を並べる。
- **1画面に強い視覚的プライマリボタンは1つ**。副次的操作は`secondary`/`ghost`に落とし、
  目立たせるボタンを絞る(禁止事項の「行内アクションの大量配置」と同じ考え方)。
- **エラーメッセージより先に、そもそも間違えられない設計にする**。無効な選択肢は
  disabled/非表示にし、破壊的操作は確認を挟む。
- **すべての操作に対して状態を返す**。保存中・成功・失敗が画面から常に読み取れるように
  する(`isLoading`/`ErrorMessage`/トースト等の既存パターンを使う)。
- **段階的開示(progressive disclosure)**。上級者向け・頻度の低い設定は折りたたみ・
  別画面に逃がし、初期表示は今必要な情報だけに絞る。
- **一貫性を新奇性より優先する**。同じ役割のUIは同じコンポーネント・同じ言い回しに揃え、
  1画面だけの独自パターンを作らない。
- **記憶より再認**。前の画面の情報を覚えていないと使えない設計を避け、必要な情報は
  その場に表示する。
- **Happy pathだけでなくLoading/Empty/Error/権限不足の状態も画面の一部として設計する**
  (品質チェックリスト参照)。
- **元に戻せる・抜けられる操作にする**。取消・キャンセル・一覧に戻る導線を用意し、
  ユーザーを後戻りできない状態に閉じ込めない。
- **業務用語はユーザーの語彙に合わせる**。内部実装名(テーブル名・enum値など)を
  画面表示に漏らさない。
- **個別画面のレビューだけでなく、実際の一連の業務フローを通しで辿って検証する**。
  1画面ずつの見た目チェックでは気づけない導線の分断・手戻りはE2Eシナリオ相当の
  通し確認で見つける。

### オブジェクト指向UI(OOUI/OOUX): 「タスク」ではなく「オブジェクト」を起点に設計する

flow-officeは社員・勤怠日・申請・カレンダー年度・経費精算のような明確な業務エンティティを
多数扱う業務アプリであり、OOUI(Object-Oriented UI/UX、Sophia V. Prater氏らが体系化)が
最も効く領域である。出典: [ooux.com](https://ooux.com/what-is-ooux)、
[Object-oriented user interface (Wikipedia)](https://en.wikipedia.org/wiki/Object-oriented_user_interface)、
[UX Mastery: Object-focused vs Task-focused](https://uxmastery.com/object-focused-vs-task-focused/)。

- **タスク指向(動詞起点)とオブジェクト指向(名詞起点)を区別する**。タスク指向は
  「経費を申請する」「従業員を編集する」のように操作(動詞)でメニュー・画面を組み立て、
  データモデルが隠れて画面ごとにバラバラの項目が出がちになる。オブジェクト指向は
  「経費申請」「従業員」のような実体(名詞)を起点にし、操作(承認する・編集する・
  取消す)はそのオブジェクトの画面上に文脈として添える。ユーザーは「編集→誰を?」ではなく
  「この従業員→次に何をする?」の順で考える。
- **失敗の兆候**: 同じエンティティ(例: 従業員)が5つの無関係な画面(オンボーディング・
  給与・管理者ユーザー一覧・プロフィール設定)からバラバラの項目だけ編集でき、
  「この従業員に関するすべて」を1箇所で見られる場所が無い状態。データの不整合・
  ユーザーの混乱の典型的な原因になる。
- **オブジェクトマップ(ORCA)を先に作る**。ワイヤーフレームの前に、対象オブジェクトごとに
  以下を洗い出す軽量な設計成果物(ER図よりUX寄り): O=Objects(対象オブジェクト)、
  R=Relationships(他オブジェクトとの関係)、C=Calls-to-action(そのオブジェクトに対して
  できる操作)、A=Attributes(属性)。出典:
  [Introducing ORCA](https://medium.com/design-bootcamp/introducing-orca-the-third-diamond-in-your-ux-process-23a1babb0389)。
- **どのオブジェクトも「正史・唯一の詳細画面」を持つ**。一覧行・検索結果・他オブジェクトの
  関連項目パネルなど、そのオブジェクトが参照されるどこからでも同じ詳細画面に辿り着く
  ようにする。操作(CTA)はタスク専用画面ではなく、そのオブジェクトの詳細画面上に置く
  (このリポジトリでの実例: 会社カレンダー本体`CompanyCalendar`の詳細画面
  `WorkCalendarDetailPage`に、名称・年度一覧・祝日iCalendar設定をすべて集約し、
  年度`CompanyCalendarYear`の同期もその年度オブジェクトの行に文脈として置く、という
  今回の刷新の考え方そのものがOOUIの実践)。
- **入れ子オブジェクト**を意識する。あるオブジェクトが別オブジェクトを内包/参照する場合
  (カレンダー年度が日別設定を内包する、申請が承認履歴を内包する等)、一覧では要約だけ
  埋め込み、詳細はリンクで正史画面に逃がす。

### 広範なユーザビリティ手法(NN/G 10ヒューリスティクス以外)

- **Fittsの法則**: 対象が大きく・近いほど到達が速い。→ 主要ボタン(保存・承認)は
  大きく、直前に編集した内容の近くに置く。破壊的操作だからと小さくしすぎない。
- **Hickの法則**: 選択肢が増えるほど意思決定が遅くなる。→ 1画面の主要アクションボタンは
  絞る(プライマリ1つ+セカンダリ1〜2程度)。頻度の低い操作はオーバーフローメニューへ。
- **Jakobの法則**: ユーザーは他アプリと同じ挙動を期待する。→ 独自の一覧/編集操作を
  発明せず、検索+フィルタ+テーブルのような業務アプリの定番パターンを踏襲する。
- **Millerの法則(チャンキング)**: 作業記憶は7±2項目程度。→ フォーム項目・テーブル列は
  5〜9項目程度の意味のあるグループに分ける(見出し付きセクション)。
- **審美性ユーザビリティ効果**: 見た目が良いUIは多少の不便があっても使いやすいと
  感じられやすい。→ 社内向け管理画面でも余白・整列の一貫性に手を抜かない。
- **ピークエンドの法則**: 体験の記憶は最も強い瞬間と終わり方で決まる。→ 承認・申請等の
  複数ステップ操作の最終確認画面は明確で落ち着いた完了表示にする(次に何をすべきかも
  示す)。
- **ツァイガルニク効果**: 未完了のタスクは記憶に残り続け不安を生む。→ 複数ステップの
  入力には進捗表示(「5件中3件入力済み」等)を出し、下書きを保持して離脱不安を減らす。
- **認知的ウォークスルー/ヒューリスティック評価**: 実装者以外が実際のタスク手順を
  1ステップずつ「次に何をすべきか分かるか・フィードバックに気づけるか」で検証する手法。
  →新画面は見た目レビューだけでなく、実タスクを最初から最後まで操作して検証する。
- **フォーム設計のベストプラクティス**(Luke Wroblewski): 単一カラム、ラベルは項目の
  上、リアルタイムのインライン検証、必須項目を絞る。→ 新規フォームは単一カラム・
  上ラベル・blur時検証をデフォルトにし、赤い必須マークを増やさない。出典:
  [Inline Validation in Web Forms – A List Apart](https://alistapart.com/article/inline-validation-in-web-forms/)。
- **段階的強化(progressive enhancement)**: まず基本のテーブル+標準フォームで
  完全に動く状態を作り、その上に高度な操作(ドラッグ&ドロップ・インライン編集)を
  重ねる。高度な操作だけが唯一の手段にならないようにする。
- **ユーザビリティテストの基本(5人ルール・思考発話法)**: 実ユーザー5人程度の
  テストで大半のユーザビリティ課題が見つかるというNielsenの知見。テスト対象者に
  実タスクをしながら考えを声に出してもらう。→ 一覧・フォームを刷新したら、実際の
  利用者(人事担当者等)数名に本物のタスクで20分程度の思考発話テストを行い、
  問題を直してから展開する。

出典: [Laws of UX (Banani)](https://www.banani.co/blog/laws-of-ux-design)、
[UX Design Institute: Laws of UX](https://www.uxdesigninstitute.com/blog/laws-of-ux/)。

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
