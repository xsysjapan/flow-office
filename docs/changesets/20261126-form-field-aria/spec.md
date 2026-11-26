# form-field-aria(必須・説明文・エラーを入力欄に属性で結ぶ)

ステータス: レビュー中

## 変更要望(原文)
変更候補の判断 `docs/changesets/candidates-20261124.md` の #9:
> 必須の印・説明文が読み上げで伝わらない → 属性(aria-required・aria-describedby)で対応する。ラベルの文字は変えない

変更セット`20261123-form-layout-standard`の論点8「必須の表示の読み上げ(`*` が `aria-hidden`)と、説明文の `aria-describedby`」の解決にあたる。

## 背景・目的
`FormField` の必須の印 `*` は `aria-hidden` で、説明文・エラーの文も入力欄に結ばれていない。スクリーンリーダーでは、入力欄に入ったときに「必須」「説明」「エラー」が読まれない。ラベルの文字(=アクセシブルネーム)を変えずに、属性で伝える。

## 現状(As-Is)(調査 2026-11-26、investigator)
- `components/FormField/FormField.tsx`: props は `label`・`htmlFor`(必須)・`required?`・`error?`・`description?`。
  - `*` はラベルの外の `<span aria-hidden="true">`。
  - 説明文は `<p id="{htmlFor}-description">` だが、入力欄から参照されていない(`aria-describedby` は src 全体で0件)。
  - エラーは `<p class="text-xs text-destructive">` で id が無い。入力欄に `aria-invalid` も無い(src 全体で0件)。
  - id は発番しない。呼び出し側が子の `id` と `htmlFor` を同じ文字列で書く規約。子に値を渡す仕組み(context・cloneElement)は無い。
  - `FormField.test.tsx:60` が「入力に aria-describedby が無い」ことを確かめている。
- 利用: 60ファイル、開きタグ381、うち `required` 付き176(概数)。
- 入力部品:

| 部品 | 操作対象の要素 | aria-* を受けるか |
|---|---|---|
| `ui/input`・`ui/textarea`・`ui/native-select` | ネイティブ要素 | `...props` を本体へ渡す |
| `GroupPicker` | `NativeSelect` | id のみ |
| `DatePicker`・`DateRangePicker`・`TimePicker`・`YearMonthPicker` | ボタン(Popover のトリガー) | id・aria-label のみ |
| `DateTimePicker` | 日付のボタンと時刻のボタン(`{id}-time`)の2つ | id・aria-label のみ |
| `UserPicker` | ボタン(`role="combobox"`) | id のみ |
| `MultiUserPicker` | ボタン | id のみ |
| `CategoryCombobox` | `Input` | id・aria-expanded |
| `AssetPicker`・`TextFilterInput` | 部品の中で自分の `FormField` を使う | — |
| `ui/radio-group` | Radix(root=radiogroup) | `...props` |
| `ui/checkbox` | Radix(button role=checkbox) | `...props`。ふつうは `FormField` の外 |
| `ui/select`(Radix) | — | 未使用 |

- `FormField` を使わない label と入力: `<label htmlFor=` が5行(`ExpenseItemsTable` 4行、`WorkCalendarDaysPage:638`)。残り約73行はチェックボックス・ラジオを包む label。
- テストへの影響(静的な grep): `getByLabelText` は単体567行・E2E の `getByLabel` 143行。ラベルに `*` や「必須」を含めて探すものは0件。`toBeRequired`・`toHaveAccessibleDescription` 等も0件。

## 設計前提の検証
- 正データと派生データ: 画面の表示だけの変更。データ・イベント・API に関わらない。
- 定義と実態: 規則(`ui-interaction-patterns` §2.19)は「ラベルの無い入力を作らない」「Error は項目の近く」。属性の結び付けは規則に無い。本変更で規則に足す。
- 横展開: `FormField` を使う全フォーム(ページ・Dialog・Sheet)に、部品の側の対応だけで効かせる。呼び出し側(60ファイル)は変えない。

## 仕様検討

### 論点1: 属性を入力欄に渡す仕組み
- 選択肢:
  - A. 呼び出し側の60ファイルで、子の入力に `aria-required`・`aria-describedby` を手で書く。
  - B. `FormField` が context で「id・必須・説明文の id・エラーの id」を配り、入力部品が context を読んで自分の操作対象の要素に付ける。
  - C. `FormField` が `cloneElement` で子に props を注入する。
- 決定: B。
- 理由: A は書き忘れが起き、今後の画面でも守られない。C は子が部品を包んだ要素(`div` 等)だと届かず、Picker の中の実際のボタンにも届かない。B なら部品の側の1か所の対応で全画面に効き、呼び出し側を変えない。
- 結び付けの条件: 部品は、自分の `id` が context の `id`(=`htmlFor`)と一致するときだけ属性を付ける。1つの `FormField` の中に入力が複数ある場合(例: 範囲の2つ目、補助のボタン)に、関係の無い入力へ付けないため。
- 呼び出し側が `aria-describedby` を明示的に渡した場合は、context の値と連結する(エラーとの結び付きが外れないように。今、明示している箇所は0件)。`aria-required`・`aria-invalid` を明示した場合は、それを優先する。
- 漏れの検出: 開発時(`import.meta.env.DEV` かつテストでないとき)だけ、`FormField` が `htmlFor` に一致する要素が DOM に無ければ `console.warn` を出す。
- 未確定・要確認事項: なし

### 論点2: 付ける属性
- 決定:
  - 説明文: `aria-describedby` に `{id}-description` を入れる。
  - エラー: エラーの `<p>` に `id="{id}-error"` を付け、`aria-describedby` に入れる(説明文の後)。入力欄に `aria-invalid="true"` を付ける(エラーがあるときだけ)。
  - 必須:
    - ネイティブの入力(`input`・`textarea`・`select`)と `role="combobox"`・`radiogroup`・`checkbox` には `aria-required="true"`。
    - ボタン(Popover のトリガー)は ARIA の仕様上 `aria-required` を持てない。`FormField` が視覚的に隠した `<span id="{id}-required" class="sr-only">必須</span>` を **Label 要素の外**(アクセシブルネームを変えないため)に置き、ボタンの `aria-describedby` の先頭に入れる。
  - `*` は今のまま `aria-hidden`(読み上げは上の属性で行う)。sr-only の「必須」は必須のとき常に描く。ネイティブの入力でも、読み上げの閲覧モードで「必須」が読まれる(属性と重なるが許容する)。ラベルの文字は変えない。
  - HTML の `required` 属性は付けない(ブラウザの標準の検証の吹き出しが出て、画面の検証と二重になるため)。
- 理由: ラベル(アクセシブルネーム)を変えないので、`getByLabelText`(単体567行・E2E 143行)を壊さない。ボタンへの `aria-required` は不正な ARIA になり、読み上げも保証されない。
- 未確定・要確認事項: なし

### 論点3: 対応する部品
- 決定: 現状の表の部品のうち、`FormField` の子に来るものすべて。
  - `ui/input`・`ui/textarea`・`ui/native-select`(`GroupPicker`・`CategoryCombobox` はこれらを通じて効く)
  - `DatePicker`・`DateRangePicker`・`TimePicker`・`YearMonthPicker`: 操作対象のボタンに付ける(ボタンの扱い)。
  - `UserPicker`・`MultiUserPicker`: ボタンが `role="combobox"` のため、ネイティブと同じ扱い(`aria-required`)。
  - `GrantTargetPicker`: 中の入力の id が `{prefix}-users`・`{prefix}-group` で、グループのモードでは `htmlFor` と一致しない。モードに関わらず、そのとき表示している入力に context の id を当てる(またはモードごとに部品が `useFormFieldControl` を表示中の入力に渡す)。
  - `ui/checkbox`: `FormField` の中で使う場合(例: `SingleExpenseItemForm:327` の項目の型による切り替え)に効くよう、context を読む(外で使う場合は context が無いので何もしない)。
  - 部品を通らない生の入力: `<input type="file">` 4か所(`SingleExpenseItemForm:363`・`WorkCalendarCreatePage:322`・`WorkCalendarDetailPage:686,860`)は `ui/input` に、生の `<select>`(`PaidLeavePolicyPage:264`)は `ui/native-select` に置き換える。見た目が変わる場合は実装結果に記録する。
  - `DateTimePicker`: 日付のボタンに付ける。時刻のボタン(`{id}-time`)には、説明文・エラーの `aria-describedby` と `aria-invalid` を付ける(必須の `sr-only` も入れる)。2つで1つの値のため。
  - `ui/radio-group`: root(radiogroup)に付ける。
  - `AssetPicker`・`TextFilterInput`: 中の `FormField` で自動的に効く。
- 未確定・要確認事項: なし

### 論点4: FormField を使わない label
- 決定: 対象外。`<label htmlFor>` の5行(`ExpenseItemsTable`・`WorkCalendarDaysPage`)は明細行・格子の見出しで、必須・説明文を持たない。チェックボックス・ラジオを包む label も同じ。
- `AttendanceDayPage` の placeholder の「(必須)」(訂正理由・削除理由)は、§2.19「Placeholder を Label 代わりにしない」に反するが、ラベルの無い入力の見直し(変更セット`20261123-form-layout-standard`の残り)に属するため本変更では扱わない。`:566` の `FormField`(required)と placeholder の重複も同じ。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `components/FormField`:
  - `FormFieldContext`(`{ id, required, describedBy, invalid, requiredNoteId }`)を提供する。context と `useFormFieldControl(id, explicit?)` hook は `FormField.tsx` とは別のファイル(`components/FormField/formFieldControl.ts`)に置く(`components/ui` → `FormField` → `ui/label` の循環を避けるため)。部品はこれで「付ける属性のオブジェクト」を得る(id が一致しない・context が無いときは空)。hook は `kind: 'native' | 'button'` を受け、native は `aria-required`、button は `aria-describedby` の先頭に `{id}-required` を入れる。
  - 説明文 `<p id="{id}-description">`(今のまま)、エラー `<p id="{id}-error">`、必須のとき `<span id="{id}-required" class="sr-only">必須</span>`(ラベルの後ろ。`*` は aria-hidden のまま)。
  - `aria-describedby` の順: 必須の注記(button のみ)→ 説明文 → エラー。
- 部品は論点3のとおり。呼び出し側が明示した `aria-describedby`・`aria-required`・`aria-invalid` を優先する。
- 呼び出し側(pages 等)は変更しない。
- スキル: `ui-interaction-patterns` §2.19 に「必須・説明文・エラーは `FormField` が入力に属性で結ぶ。入力部品を新しく作るときは `useFormFieldControl` を使う」を足す。`add-frontend-component` に、入力部品の作り方として同じことを1行足す。

## 受け入れ条件
- `FormField` の中の入力(論点3の部品すべて)で、`required` のとき読み上げの属性(native は `aria-required="true"`、ボタンは `aria-describedby` に「必須」)が付く。
- 説明文があるとき `aria-describedby` が説明文を指し、エラーがあるとき `aria-invalid="true"` と `aria-describedby` がエラーを指す(`toHaveAccessibleDescription` で確かめられる)。
- id が `htmlFor` と一致しない入力には付かない。
- ラベルの文字・見た目は変わらない。既存の単体テスト全件が通る(`FormField.test.tsx:50-60` は生の `<input>` を `ui/input` に差し替えて新しい期待値に直す)。

## 対象外
- ラベルの無い入力(aria-label・placeholder だけの入力)の見直し
- エラーを項目の下に出す対応(変更セット`20261126-field-errors-near-fields`。本変更で付けた id・`aria-invalid` をそのまま使う)
- エラー時のフォーカス移動
- チェックボックス単体、`FormField` を使わない label

## ドキュメントへの影響
- `.claude/skills/ui-interaction-patterns/SKILL.md` §2.19、`.claude/skills/add-frontend-component/SKILL.md`
- docs/: 変更なし(画面の属性のみ)

## モック・アセット
なし

## 実装対象
- `frontend/src/components/FormField/`(context・hook・テスト)
- `frontend/src/components/ui/input.tsx`・`textarea.tsx`・`native-select.tsx`・`radio-group.tsx`
- `frontend/src/components/DatePicker`・`DateRangePicker`・`TimePicker`・`YearMonthPicker`・`DateTimePicker`・`UserPicker`・`MultiUserPicker`(各テストに1〜2件)

## 検証方法
- `npx tsc -p tsconfig.app.json --noEmit`、`npx vitest run --project unit`(全件)
- E2E は流さない(アクセシブルネームが変わらないため。`getByLabel` の143行は影響を受けない)。

## レビュー履歴
- 2026-11-26 初版。
- 2026-11-26 実装前の独立設計レビュー(general-purpose。FormField 415件を走査)の指摘を反映:
  - 漏れる部品を追加(`GrantTargetPicker`、FormField の中の `ui/checkbox`、生の file 入力・select の置き換え)。開発時の漏れの警告を追加。
  - `UserPicker`・`MultiUserPicker` は combobox なのでネイティブの扱いに統一(論点2と3の矛盾の解消)。
  - sr-only は Label の外、読み上げの重複は許容、明示の `aria-describedby` は連結、context は別ファイル、`FormField.test.tsx` の差し替え。

## 実装結果
未着手
