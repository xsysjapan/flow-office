# form-layout-standard(フォームのレイアウトの標準化)

ステータス: レビュー中

## 変更要望(原文)
> フォームのレイアウトも標準化をお願いします。

## 背景・目的
新規作成・編集・設定のフォームで、項目の並べ方・幅・セクションの分け方・保存/キャンセルの位置と文言・エラーの位置・説明文の書き方が画面ごとに違う。
`ui-interaction-patterns` §2.5/§2.6/§2.19 はあるが、レイアウトの具体(幅・列・ボタンの並び)が決まっていないため、標準形と部品を決めて揃える。

## 現状(As-Is)

### 部品
- `components/FormField`: label・htmlFor・required・error・className。必須は `*`(`aria-hidden`)。説明文の props なし。外側 `mb-4`(項目間の余白をこれで取っている)。`error` は pages で0件使用。
- `components/ErrorMessage`: 項目エラーも送信エラーも同じ Alert にまとめる。
- `components/Card` にフッターは無い(`ui/card` には `CardFooter` があるが、ラッパーは出さない)。
- `ui/dialog` の `DialogFooter`: `flex-col-reverse`、sm 以上で右寄せ(主ボタンが右、モバイルは縦積みで主ボタンが上)。`DialogContent` の既定は `max-w-lg`、`size="large"` はモバイルで全画面になるだけで PC の幅は同じ。
- pages に `<form>` 要素は無い(Enter で送信できない)。

### 対象のフォーム(ページ13・Dialog/Sheet)
| # | ファイル / ルート | 並べ方 | ボタン(位置・並び・文言) | エラーの位置 |
|---|---|---|---|---|
| F1 | workflow/WorkflowRequestNewPage `/requests/new` | 1列 | 右寄せ: キャンセル・下書き保存・提出する | ボタン行の直下 |
| F2 | expense/ExpenseClaimNewPage `/expenses/new`・`/:id/edit` | 段階式。分岐カードは `sm:grid-cols-2` | 「次へ」単独、「登録方法の選択に戻る」 | 複数箇所 |
| F3 | expense/ExpenseEntryPresetEditPage `/expenses/presets/new`・`/:id` | 基本 `sm:grid-cols-2`、明細行 `sm:grid-cols-3`(枠付き) | キャンセルが Card ヘッダー右上、保存は下部左「作成する」「保存する」 | 保存の直下 |
| F4 | asset/AssetRegisterPage `/assets/new` | `sm:grid-cols-2` のペア | 左寄せ: キャンセル・作成 | ボタン行の直上 |
| F5 | asset/AssetEditPage `/assets/:id/edit` | 名称・カテゴリ `sm:grid-cols-2`、以降1列 | 左寄せ: キャンセル・保存 | ボタン行の直上 |
| F6 | workCalendar/WorkCalendarCreatePage `/admin/work-calendars/new` | `sm:grid-cols-2`、iCal 登録 `grid-cols-2` | 左寄せ: キャンセル・作成する。iCal は右寄せ: キャンセル・登録する | Card の先頭 |
| F7 | workCalendar/WorkCalendarDetailPage の基本設定 `/admin/work-calendars/:id` | `grid-cols-2`・`sm:grid-cols-2` | 右寄せ: 保存する(キャンセルなし) | ボタンの直下 |
| F8 | workflow/RequestTypeEditPage `/admin/request-types/:id` | `sm:grid-cols-2` ×3、`h3`「項目」、項目行リスト | 左寄せ: キャンセル・保存する | ボタン行の直下 |
| F9 | expense/ExpenseCategoryEditPage `/admin/expense-categories/:id` | `sm:grid-cols-2`、項目行 `sm:grid-cols-5` | 左寄せ: キャンセル・作成/保存 | ボタンの直下 |
| F10 | paidLeave/PaidLeaveGrantRuleEditPage `/admin/paid-leave/rules/:id/edit` | `sm:grid-cols-2` | 左寄せ: キャンセル・保存 | 上部側 |
| F11 | admin/UserRoleEditPage `/admin/users/:id` | 基本情報 `md:grid-cols-2`、`h2`+`rounded border p-3` の枠でセクション | セクションごと: 「基本情報を保存する」ほか6つ(secondary)、キャンセルなし | 各セクションの直下 |
| F12 | admin/GroupDetailPage `/admin/groups/:id` | `grid-cols-2` | 作成ボタン | ボタンの直上 |
| F13 | admin/SystemSettingsPage `/admin/system-settings` | 1列、`h3` ×4 | 左寄せ: 保存する(キャンセルなし) | ボタンの直下 |
| D1 | AttendanceDayPage「振替休日を申請する」Dialog | 1列 | DialogFooter: キャンセル・申請する | 説明の直下・項目の上 |
| D2 | ExternalIntegrationConnectionsPage「外部連携を編集」Dialog | — | DialogFooter: キャンセル・保存 | — |
| D3 | RoleDefinitionPage ロール作成・複製 Dialog | `md:grid-cols-2`・`md:grid-cols-3` | DialogFooter: キャンセル・作成/複製作成 | — |
| D4 | WorkCalendarDetailPage「カレンダー年度を作成」Dialog | — | キャンセル・年度を作成する | フォームの直上 |
| D5 | components/WorkStyleFormModal(勤務形態の作成・編集) | — | キャンセル・確定 | — |
| D6 | UserManagementAccessPage 所属変更予約 Dialog(size large) | `md:grid-cols-2` | キャンセル・変更を予約/変更を実行/更新 | — |
| D7 | GroupDetailPage 所属変更 Dialog | — | キャンセル・変更を予約/変更を実行 | DialogFooter の直上 |
| D8 | UserRoleEditPage 所属変更 Dialog | — | キャンセル・登録/変更 | — |
| D9・D10 | PaidLeavePolicyPage 付与表の新バージョン Sheet(通常・比例) | 行ごとの grid | 単独「新しいバージョンを保存」(キャンセルなし) | — |

- 文言: 「作成/作成する」「保存/保存する」「変更を保存」「更新する」「登録する」「申請する」「新しいバージョンを保存」が混在。「登録」は WorkCalendarCreatePage:82・WorkCalendarDetailPage:128・MyIntegrationsPage:49 で Pattern exception として記録済み。
- 説明文: `<p className="text-xs text-muted-foreground">` の直書き、句点の有無がばらつく。見出し「備品を登録」(§2.7 の「登録」)。
- ルール: §2.5(Create は [キャンセル][作成])、§2.6(Edit は [キャンセル][保存])、§2.7(用語)、§2.18(エラーは操作箇所の近く、送信ボタンの直下)、§2.19(単一カラム・ラベルは上・Section)。

## 設計前提の検証
- 正データと派生データ: 画面の表示だけの変更。API・データに影響しない。
- 定義と実態: §2.19「単一カラム」と多列の実装が食い違う。ルールが「組で読む短い項目」の横並びを想定していない。ルールを「原則1列・許す横並びを列挙」に直す。
- 原因の分類: ルールの不足(幅・ボタンの並び・説明文の部品が無い)と、それによる実装のばらつき。部品を作り、全フォームに効かせる。
- 横展開: ページのフォーム13(F1〜F13)と Dialog/Sheet(D1〜D10、components 側の作成・編集 Dialog)。一覧・詳細の中のインラインの入力は、変更セット`20261123-list-layout-standard`の後に別の変更セットで扱う(論点8)。
- 順序: 本変更セットは`20261123-list-layout-standard`の後に行う(同じファイルを触るため)。

## 進め方(2段階)
- 段階A: 部品(`FormLayout`・`FormSection`・`FormActions`、`FormField` の `description`)とルールの書き直し。
- 段階B: F1〜F13・D1〜D10 への適用。

## 仕様検討

### 論点1: フォームの幅
- 選択肢: A. フォームの内容の最大幅を決める / B. Card の全幅のまま
- 決定: A。項目・セクション・ボタン行を新しい部品 `FormLayout`(`max-w-2xl`、縦の間隔は `FormField` の `mb-4` に任せ、`gap` を足さない)の中に置く。Card は全幅のまま。Dialog の幅は変えない。
- 理由: PC で入力欄が横に長いと目線の移動が大きく、短い値(日付・数値)の欄が不自然に長い。
- 細則: 行を繰り返す表形式の明細行エディタ(F3 の明細、F8 の項目行、F9 の項目行)は `FormLayout` の外(全幅)に置く。ページの構成は
  `<FormLayout>基本の項目</FormLayout>` → 明細行エディタ(全幅)→ `<FormLayout>エラー・補足・FormActions</FormLayout>` の順。ボタン行は `max-w-2xl` の右端に来る(Card ヘッダーの右端とはそろわないが、入力欄の右端にそろうのを意図とする)。
- 未確定・要確認事項: なし

### 論点2: 項目の並べ方(列)
- 選択肢: A. 原則1列。横に並べてよい組を列挙する / B. `sm:grid-cols-2` を標準にする / C. 完全に1列
- 決定: A
- 細則:
  - 横に並べてよいのは次の組だけ(`grid gap-4 sm:grid-cols-2`、2項目まで): 期間の開始と終了、時刻の開始と終了、数値と単位。それ以外(コードと名称、名称とカテゴリ、管理番号と名称等)は1列にする。
  - 明細行エディタ(表形式)の3列以上は `md:` 以上でだけ横に並べ(`sm:grid-cols-3/5` を `md:` に)、それ未満は縦に並べる。
  - Dialog の多列(D3 の `md:grid-cols-2/3`、D6 の `md:grid-cols-2`)も同じ規則にする。
- 理由: §2.19 の単一カラムを維持し、組で読む短い項目だけ横に並べる。B は関係の薄い項目まで横に並び、読む順が分かりにくい。C は期間が縦に長くなる。
- 未確定・要確認事項: なし

### 論点3: セクション
- 決定: 新しい部品 `FormSection`(`title`・`description?`・`children`。見出しは `h3`(`text-sm font-semibold`)、セクション間は `mt-8`)で区切る。枠線で囲まない。
  枠で囲むのは、繰り返す単位(明細行1件、付与表の1行)だけで、`rounded-md border p-3`(角丸トークン)にする。
- 対象: F7(会社カレンダーの基本設定)、F11(ユーザー管理の `h2`+`rounded border` の枠 → FormSection)、F13(`h3` → FormSection)、F8(`h3`「項目」→ FormSection)、D9・D10、F3。
- 理由: ui-design-system の「すべてを枠で囲わない」に合う。
- 未確定・要確認事項: なし

### 論点4: 保存・キャンセルのボタン
- 位置と並び:
  - 選択肢: A. 末尾に右寄せ、[キャンセル][主ボタン](DialogFooter と同じ) / B. 左寄せ、[キャンセル][主ボタン](§2.5 の図・現状の多数派)
  - 決定: A
  - 理由: Dialog は既に右寄せで、ページも同じにするとどの器でも主ボタンが右下に来る。並びは §2.5/§2.6 のまま。
  - 細則: ページのフォームは新しい部品 `FormActions`(`flex flex-col-reverse gap-2 sm:flex-row sm:justify-end`。DialogFooter と同じ並び)。Dialog は `DialogFooter` のまま(FormActions を使わない)。
    キャンセルは `secondary`、主ボタンは `primary`(破壊的な操作は `danger`)。
- 文言:
  - 決定: 作成は「作成」、編集・設定の保存は「保存」(「〜する」を付けない)。業務の動作そのものの送信は動詞のまま(「申請する」「提出する」「下書き保存」「実行」)。
    「更新する」「変更を保存」「新しいバージョンを保存」→「保存」、「作成する」→「作成」、「年度を作成する」→「作成」。
    Pattern exception として記録済みの「登録する」(会社カレンダーの休日ソース、API・MCP連携)は維持する。
    セクションごとに保存する F11 は、ボタン名が重複しないよう「{セクション名}を保存」(「基本情報を保存」等)にする(セクションごとの保存をまとめるかは論点8)。
  - 見出し・画面名「備品を登録」→「備品を作成」(§2.7。pageHierarchy の title とブラウザのタブ名も変わる。e2e scenario-14 を追随)。
- キャンセルの有無: 作成・編集のページ(F1〜F6、F8〜F10、F12)と Dialog/Sheet には必ず置く。設定のページ(F7・F11・F13。戻る先の無い設定)は置かなくてよい。
  F3 の Card ヘッダー右上のキャンセルは FormActions に移す。D9・D10 にキャンセルを足す。
- 未確定・要確認事項: なし

### 論点5: エラーの表示位置
- 決定: 送信のエラー(`ErrorMessage`)はボタン行(`FormActions`・`DialogFooter`)の直上に置く。§2.18 の「送信ボタンの直下」を「送信ボタン行の直上」に直す。
  `BulkActionDialog`・`ConfirmActionDialog` も同じ位置か確認し、違えば直す。
- 理由: ボタン行を右寄せ・モバイルで縦積みにすると、直下ではボタンと離れたり画面外になったりする。直上ならどの器でも同じ位置になる。
- 対象: F6(Card の先頭)、F10(上部)、ボタンの直下に置いている F1・F3・F7〜F9・F11・F13、D1(項目の上)。
- 未確定・要確認事項: なし

### 論点6: 説明文
- 決定: `FormField` に `description?: ReactNode` を足し、ラベルの下・入力の上に `text-xs text-muted-foreground` で出す(`id` は `${htmlFor}-description`)。直書きの説明の `<p>` は `description` に置き換える。文は句点で終える。
  フォーム全体の補足(「保存すると〜されます。」)は `FormActions` の直上(エラーより上)。
- 入力欄と説明文を `aria-describedby` で結ぶことは、子の入力部品(NativeSelect・DatePicker 等)に属性を渡す仕組みが要るため本変更では行わない(論点8)。
- 未確定・要確認事項: なし

### 論点7: 適用範囲
- 決定: F1〜F13・D1〜D10 と components 側の作成・編集の Dialog(WorkStyleFormModal ほか)。レイアウトだけを直し、項目・挙動は変えない。
- 未確定・要確認事項: なし

### 論点8: 本変更セットで扱わないこと(別の変更セットの候補)
- 一覧・詳細の中のインラインの入力(社員一覧・端末・外部連携設定・API・MCP連携の作成フォーム、本人の休暇の申請フォーム、シフト・特別休暇設定 等)のレイアウトと、インラインで作成すること自体の見直し(§2.11)
- 項目エラーを項目の近くに出す(`FormField` の `error`。§2.19)
- 離脱警告(`useUnsavedChangesGuard`)が4画面にしか無い(§2.9)
- Page にすべき作業を Dialog/Sheet で行っている(働き方の編集、ロールの作成、付与表の編集、所属変更の予約。§2.11)
- `<form>` が無く Enter で送信できない
- 必須の表示の読み上げ(`*` が `aria-hidden`)と、説明文の `aria-describedby`(テストの `getByLabelText` 約545か所への影響の検討が要る)
- ユーザー管理のセクションごとの保存(§2.8 の「まとめて保存」との関係)
- 決定: ユーザーに報告し、別の変更セットで扱う。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
段階A:
1. 新規 `FormLayout`(`max-w-2xl`、children を縦に並べる)、`FormSection`(`title`・`description?`、`h3 text-sm font-semibold`、セクション間 `mt-8`)、`FormActions`(`flex flex-col-reverse gap-2 sm:flex-row sm:justify-end`)。各3点セット。
2. `FormField` に `description?: ReactNode`(id `${htmlFor}-description`、ラベルの下・入力の上)。
3. ルール: `ui-interaction-patterns` §2.5・§2.6(右寄せ・並び・部品)、§2.7(「〜する」の使い分け、「登録」の例外の扱い)、§2.18(エラーは送信ボタン行の直上)、
   §2.19(原則1列と横並びの組、最大幅、セクション、説明文)。`ui-design-system` にフォームの最大幅とセクション見出しを1行。`add-page` に「フォームのページは FormLayout・FormSection・FormActions を使う」を1行。
段階B:
4. F1〜F13・D1〜D10 を論点1〜6 に合わせる(上の表の各行。横並びは論点2の組だけ、明細行は `md:`、セクションは FormSection、ボタン行は FormActions/DialogFooter、文言、エラーの位置、説明文)。
5. 見出し・画面名「備品を作成」。
6. テスト: 新しい部品のテスト、`FormField` の description。文言を変えた各画面のテストと e2e の追随(「作成する」「保存する」「更新する」「変更を保存」「備品を登録」等を grep)。

## 受け入れ条件
- F1〜F13 と D1〜D10 で、内容の幅が `max-w-2xl`(明細行エディタを除く)、ボタン行が右寄せの [キャンセル][主ボタン](モバイルは縦積み・主ボタンが上)、エラーがボタン行の直上、
  文言が「作成」「保存」・業務の動詞・記録済みの例外のいずれか、横並びは論点2の組だけ、セクションは FormSection で区切られている。
- 説明文が `FormField` の `description` で出ている。
- `ui-interaction-patterns` にフォームのレイアウトのルールがある。
- 幅1280・768・390 のスクリーンショットで F1〜F13 を確認。frontend 単体全件・型チェック・e2e 全件がPASS。

## 対象外
- 論点8の各項目(別の変更セットの候補としてユーザーに報告)
- 項目の追加・削除、入力部品の種類の変更
- 一覧の検索フォーム・画面の見出し(変更セット`20261122-list-search-form-standard`・`20261123-list-layout-standard`)

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md`(§2.5・§2.6・§2.7・§2.18・§2.19)、`ui-design-system/SKILL.md`、`add-page/SKILL.md`
- docs: 変更なし(画面の見た目のみ)

## モック・アセット
なし

## 実装対象
- frontend/src/components: FormField(変更)、FormLayout・FormSection・FormActions(新規)
- frontend/src/pages: F1〜F13、D1〜D10 の画面と components 側の Dialog、テスト・stories、e2e。`routes/pageHierarchy.ts`(備品を作成)
- skills

## 検証方法
- `cd frontend && npx vitest run --project unit`、`npx tsc -p tsconfig.app.json --noEmit`、e2e 全件
- F1〜F13 を幅1280・768・390 で撮って確認

## レビュー履歴
- 初版(2026-11-23)
- 2026-11-23 提示前の独立設計レビュー(general-purpose)の指摘を反映:
  - 高: 棚卸し表が無い → 対象のフォームの表(F1〜F13・D1〜D10)を本文に
  - 高: 「(必須)」の読み上げの追加は単体テスト約545か所の getByLabelText に影響 → 本変更から外し論点8へ(aria-describedby も同様)
  - 中: `size="large"` は PC の幅を変えない → 記述を削除。FormLayout と明細行エディタ・FormActions の関係、FormField の mb-4 との関係、Dialog では DialogFooter を使うことを明記
  - 中: 「組で読む項目」が判定できない → 横並びを許す組を列挙(期間・時刻・数値と単位)
  - 中: 「登録」の Pattern exception の扱い、F11 のボタン名の重複、エラー位置の Dialog との整合、規模 → 段階A/Bに分割、インラインの入力は別の変更セットへ
  - 低: CardFooter の記述、FormActions の右端の位置、備品を作成のタブ名・e2e、list-layout-standard との順序

## 実装結果
未着手
