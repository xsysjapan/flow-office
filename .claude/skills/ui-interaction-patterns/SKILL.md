---
name: ui-interaction-patterns
description: Use when creating or changing flow-office user interactions — CRUD flows, forms, tables, navigation, dialogs, filters, destructive actions, status changes, loading/empty/error/permission states, save-cancel-back behavior, or page transitions. Defines the repository's interaction patterns so equivalent actions behave the same across every screen. Visual tokens and component appearance are out of scope (see ui-design-system).
---

# flow-officeの操作パターンに沿って画面の挙動を決める

flow-officeでは、**画面ごとに最適そうな操作方法を独自に作らない**。同じ意味の操作には同じ
Interaction Patternを使い、ユーザーが別機能へ移動しても過去の操作経験をそのまま再利用できる
ことを優先する。

優先順位は次の通り。

```
Consistency > Predictability > Efficiency > Local novelty
```

「この画面だけ少し便利」は独自UIを導入する理由にならない。UI品質は個々の画面の格好良さでは
なく、予測可能・学習の再利用が効く・間違えにくい・状態が分かる・戻れる・迷わない・長時間
使って疲れない、で評価する。

このスキルは「どう操作するか」だけを扱う。色・余白・タイポグラフィ・角丸・影・
コンポーネントの外観バリアントは`.claude/skills/ui-design-system`が扱う。

---

## 1. Mandatory Rules(必ず守る)

1. **同じ意味の操作は同じPatternを使う**。逸脱する場合は実装前メモに`Pattern exception:`と
   `Reason:`を書く(§3)。「なんとなくこの方が使いやすそう」は理由として不十分。
2. **UI語彙を統一する**(§2.7)。「作成」「編集」「保存」「追加」「削除/解除」「キャンセル」。
   「登録」は業務用語として自然な場合(打刻端末登録など)以外はUIの一般操作名に使わない。
3. **1画面に強いPrimary Actionは1つ**。副次操作は`secondary`/`ghost`、低頻度操作は
   `DropdownMenu`のオーバーフローへ。
4. **Formは明示的保存**。保存に失敗しても入力値を破棄しない。Validationエラーで画面を
   離脱させない。編集内容が失われないことを最優先する。
5. **Back(戻る)とCancel(キャンセル)を同義に使わない**。Backは情報階層の移動、Cancelは
   現在の変更の破棄。
6. **戻せない破壊的操作は「結果」を確認させる**。対象名と不可逆であることを本文に書く。
   `本当に削除しますか?`だけの確認は不可。
7. **確認UIを新規に発明しない**。確認は`components/ConfirmActionDialog`(メニュー等から開くときは`open`/`onOpenChange`を渡す制御モード)、
   一括操作は`components/BulkActionDialog`(ConfirmActionDialogの上に組んだもの)を使う
   (`components/ConfirmDialog`も現存するが、2種類併存は解消すべき重複であり新規の参照先にしない)。
8. **Loading / Empty / Error / Permission denied / Disabled を別々の状態として設計する**。
   Happy pathだけで実装完了にしない。EmptyはInitialとFilteredを区別する(§2.16)。
9. **Radix UIが持つ標準キーボード操作を独自実装で壊さない**(Tab / Shift+Tab / Enter /
   Space / Escape / 矢印キー)。
10. **同じ種別の一覧では行クリックの挙動を揃える**。原則「行クリック → 対象オブジェクトの
    詳細」(§2.2)。
11. **一覧の絞り込み状態はURLに載せる**(§2.10)。Browser Back・リロード・URL共有で状態が
    壊れないようにする。
12. **すべてのUser Actionに状態変化を返す**(Idle → Submitting → Success / Error)。押した
    のに何も起きないように見える操作を作らない(§2.18)。変更操作の成功は`notify.success`(トースト)、失敗は操作した場所の直近の
    `ErrorMessage`で必ず返す。確認を挟むかは§2.12の分類で決める(変更セット`20261122-list-action-feedback-standard`)。
13. **戻るリンクは掘り下げで開いた画面にだけ出す**。画面内の進むリンクは「掘り下げ・切り替え・横移動」の
    どれかを判定し、掘り下げだけ遷移元を記録する(§2.26-3)。戻り先は「来た画面(遷移元)→ 階層上の親画面」の
    順に決め、`navigate(-1)`を戻る導線に使わない(§2.26)。

---

## 2. Standard Patterns

### 2.1 List Page

```
PageHeader(管理画面のみ。管理画面以外はCardのtitleが画面名)
 ├─ Title(h1)
 ├─ Description
 └─ Primary Action        ← 右上。通常は「新規作成」

一覧Card本文(§2.20)
 ├─ 主軸タブ              ← 作業キュー型のみ(件数付き)
 ├─ フィルターバー        ← Search / Filters(詳細条件)/ 検索条件をクリア
 └─ Bulk Actions          ← 選択時のみ表示(表の直上)

DataTable / List
 ├─ Rows(Status表示を含む)
 ├─ Context Actions
 └─ Pagination
```

検索・絞り込みは検索専用Cardにせず、一覧Cardの本文先頭に置く。詳細は§2.20。

#### 画面の見出し

- **管理画面(`/admin`配下の全ルート)**: 本文の先頭(`BackLink`がある画面はその下)に`components/PageHeader`を置く。
  - props: `title`(省略時は現在のルートの`pageHierarchy`のtitle。ブラウザのタブ名・ナビと同じ語になる)・`description`(必須。です・ます調で句点で終える、画面の目的を1〜2文)・
    `actions?`(画面全体の操作。右寄せ)・`meta?`(状態のバッジ・期間など。見出しの横)。直書きのh1は置かない。
  - ルートは`<section aria-labelledby>`で名前付きのregionになる(e2e・単体テストは`getByRole('region', { name: 見出し })`で範囲を絞れる)。
    actionsは本文幅が`@2xl`未満なら見出しと説明の下へ回り込む(画面幅の`md:`は使わない。§2.20のコンテナクエリと同じ理由)。
  - 読み込み中・エラー・権限なしでもPageHeader(見出しと説明)は出す(状態の分岐より前に置く)。actionsは、データを読み込めて操作の権限がある場合だけ出す。
  - Cardのtitleはセクション名だけにし、PageHeaderのtitleと同じ語にしない。Cardが1つだけの画面は、そのCardのtitleを外す(重複を避ける)。
    例外(詳細画面): 管理画面の詳細画面(利用者詳細・グループ詳細・会社カレンダー詳細・付与ルールの編集)は、先頭のCardのtitleを対象名(氏名・グループ名等)にする。
  - 画面全体の操作(新規作成・CSV・関連画面への移動・「デフォルトに設定する」など)はPageHeaderのactions、セクションの操作はCardのactions。
    画面に複数のセクションがあり操作がその一覧に属する場合(例: ロール管理の「新規作成」)は、そのセクションのCardのactionsに置く。
- **管理画面以外**: ページ見出し(h1)は置かない。主となる(先頭の)Cardのtitleを画面名にする(`pageHierarchy`のtitleと揃える)。
  Cardが複数の画面は、2つ目以降のtitleをセクション名にする(「自分の〜」は付けない)。Cardのtitleは今までどおりh2のままにする。
  - 例外(詳細画面): `/requests/:id`・`/expenses/:id`・`/backoffice-tasks/:id`・`/assets/:id`はCardのtitleを対象名(申請タイトル等)にする。ホーム(`/`)は各Cardの内容の見出しのまま。
  - 入口が違う別画面で同名にすると区別できない場合(備品の「備品貸出」「備品管理」)は、`BackLink`の文言の規則(§2.26)を優先して無理に揃えない。
  - ステップごとにCardが出る画面(経費精算の編集)は画面名のCardが無いので、この規則の対象外。
- `BackLink`の位置・文言は変えない(§2.26)。

#### 主操作とその他の画面操作

- 主操作は、管理画面はPageHeaderのactions、管理画面以外は主となるCardのactionsに、右端のprimaryの`Button`1つで置く(`size="sm"`にしない)。
  Primary Actionは右上。1画面に強いPrimary Actionは原則1つ。
- 文言は「新規作成」に統一する(§2.7)。業務用語の例外は残す: 申請一覧の「新規申請」(遷移先の画面名)、API・MCP連携と外部連携設定の「新規登録」(docs/25の業務用語。`Pattern exception`を書く)。
- その他の画面操作(CSV・関連画面への移動・一括操作の入口)は、主操作の左にsecondaryの`Button`で置く(テキストリンクにしない)。
  操作が多いときはsecondaryの`DropdownMenu`(「一括操作」等)にまとめる。権限による出し分けは保つ(管理権限が無い人には主操作が出ず、メニューだけになってよい)。
- インラインのフォームを開閉するトグルは、閉じているときは主操作の文言、開いているときは「閉じる」(secondary。「キャンセル」にしない)。フォームは本文の先頭(PageHeader/Cardヘッダーの直下)に開く。
- 空状態の作成ボタンも主操作と同じ文言(§2.17)。同名のボタンが2つになるため、e2eはPageHeaderのregionかCardのactionsの中に絞って押す。

根拠: 変更セット`20261123-list-layout-standard`。

### 2.2 行クリック

原則:

```
行クリック → そのオブジェクトの詳細画面
```

「ある一覧では編集、別の一覧では詳細」という差異を作らない。

**実装は`frontend/src/components/ClickableTableRow/`に統一する**(`TableRow`のラッパー。
行全体に`cursor-pointer`・`role="button"`・`tabIndex`・Enter/Spaceでの起動を実装済み)。
主要フィールド(名称等)のセルは、ルート遷移する一覧では実体の`<Link>`のままにして
中クリック・新規タブで開く操作を残す。行内アクション(削除ボタン等)を含むセルは
`onClick={(e) => e.stopPropagation()}`で行クリックとの二重発火を防ぐ。行の主目的が
Dialog/Modalを開くことである一覧(承認詳細プレビュー等)でも同じ`ClickableTableRow`を使う。
削除済み等クリック自体を無効化したい行は`disabled`propで通常の`TableRow`として描画する。

### 2.3 行内アクション

行内に直接置くのは、**その一覧の主目的の操作1つまで**(承認一覧の「承認」など)。2つ以上ある場合は行末の「⋯」`DropdownMenu`にまとめる。

```
⋯
 ├ 編集
 ├ 無効にする
 └ 削除
```

- メニューから確認ダイアログを開くときは、`DropdownMenuItem`の`onSelect`で`preventDefault`してメニューを閉じてから、`ConfirmActionDialog`の制御モードで開く
  (Radixのフォーカス・pointer-eventsが残るのを防ぐ)。
- 有効/無効などの切替は確認なしで即実行し、成功トースト(「〇〇を無効にしました」)。失敗時は行の直近にエラーを出し、表示を元に戻す。

### 2.4 Detail Page

Detail Pageは対象オブジェクトの**正史**として扱う。

```
〈 {戻り先}に戻る          ← BackLink(§2.26)

<Object Name>                     [Primary Action] [⋯]

Section
  Attribute
  Attribute

Section
  Related Objects(要約 + 正史画面へのリンク)
```

- 左上に戻る導線(`components/BackLink`。戻り先と文言は§2.26)、タイトルは対象オブジェクト名
- Primary CTAは右上、低頻度操作はOverflow
- **属性の表示にForm Controlを使わない**(閲覧専用の値を`Select`や`Input`で描画しない)
- 同じオブジェクトの情報を無関係な複数画面に分散させない

### 2.5 Create Page

Createは新規オブジェクトを作る行為。UI文言は原則「作成」。

```
ユーザーを作成
...
[キャンセル] [作成]    ← 末尾に右寄せ
```

ボタン行の規則(§2.5・§2.6共通。変更セット`20261123-form-layout-standard`):
- **位置と並び**: 末尾に右寄せで [キャンセル][主ボタン]。3つのときは [キャンセル][その他(下書き保存など)][主ボタン]。狭い幅では縦に積み、主ボタンが上に来る。
- **部品**: ページは`components/FormActions`、Dialogは`DialogFooter`(中にはボタンだけを置く)、Sheetは`SheetFooter`が無いので末尾に`FormActions`。入れ子の小さなフォームも`FormActions`。
- **キャンセルの有無**: 作成・編集のページとDialog・Sheetには必ず置く(編集のキャンセルは戻る先へ移る)。戻る先の無い設定・操作の画面(基本設定、ユーザー管理、システム設定、ロール詳細、日次の作成、一括操作の作成、データ出力、初回セットアップ、運用コマンド等)には置かない。
- **対象ごとの保存が複数並ぶ行**(入社日を保存・退社日を保存など)は`FormActions`を使わず、`flex flex-wrap justify-end gap-2`で項目の順に並べる。
- **ボタンの種類**: キャンセルは`secondary`、主ボタンは`primary`(破壊的な操作は`danger`)。1画面に「{対象}を保存」が複数並ぶ設定の画面は、primaryが並ばないよう`secondary`にする。

作成成功後は、作成されたオブジェクトの詳細画面、または元の一覧へ遷移する。どちらにするかは
一覧内で完結する作業か否かで決め、同じドメイン内では揃える。

### 2.6 Edit Page

既存オブジェクトの変更は「編集」、完了操作は「保存」。

```
ユーザーを編集
...
[キャンセル] [保存]    ← 末尾に右寄せ(ボタン行の規則は§2.5)
```

- 保存成功後は詳細画面へ戻る(作成時だけ遷移して更新時は留まる、のような非対称にしない)
- 保存失敗時は入力値を保持する
- Validation Errorでは画面を離脱しない

### 2.7 用語ルール

| 意味 | UI文言 |
| --- | --- |
| 新規オブジェクトを作る(フォームの送信ボタン) | 作成 |
| 一覧から作成画面・作成フォームを開く(一覧の主操作) | 新規作成 |
| 既存オブジェクトを変更する | 編集 |
| 変更を確定する | 保存 |
| 既存オブジェクトを関連付ける | 追加 |
| 関連を外す | 削除 / 解除(意味で使い分ける) |
| 変更せず戻る | キャンセル |

「登録」は原則使わない。例外は法令・業務上「登録」が正式名称のもの(打刻端末登録など)。
一覧の主操作の「新規作成」(作成画面・フォームを開くボタン)と、フォームの送信ボタンの「作成」は使い分ける(§2.1。変更セット`20261123-list-layout-standard`)。
「新規申請」(申請一覧の主操作)・「新規登録」(API・MCP連携、外部連携設定)は業務用語の例外として残す。

フォームの送信ボタン・画面名(変更セット`20261123-form-layout-standard`):
- 送信は「作成」「保存」。「作成する」「保存する」「更新する」「変更を保存」のように「する」を付けない。「保存」「作成」の複合語も「する」を外す(「補正を保存」「明細を保存({n}件)」「この内容で保存」「保存して開始」)。作成と編集を兼ねる画面は、作成時「作成」、編集時「保存」。
- 業務の動作そのものの送信は動詞を残す(「申請する」「提出する」「下書き保存」「複製する」「確定する」「変更を予約」「変更を実行」「実行」「出力」「セットアップを完了する」など)。
- 「登録する」はPattern exception(会社カレンダーの休日ソース、API・MCP連携)だけに残す。マスタの作成・編集(勤務形態・備品など)は「作成」「保存」にする。
- 画面名(`pageHierarchy`のtitle・PageHeader)は「〜を作成」「〜を編集」。「〜の新規作成」「〜の編集」「〜を新規作成」「〜を登録」にしない(「新規作成」は一覧のボタンの語)。「新規申請」は業務用語として残す。

### 2.8 Save Pattern

- **Form**: 明示的保存。
- **単純なToggle**: 即時反映を許可(例: `通知を有効にする [ON]`)。
- **複雑な設定**: 複数変更をまとめて保存。
  - 1画面に保存ボタンを項目・セクションごとに並べない。末尾の「保存」1つ(FormActions)にし、変更が無いときは無効にする。
  - 変更の有無は読み込んだ値と比べて判定し、変更した項目だけを送る。
  - 保存は全て成功するか全て戻すか(backendのまとめて保存のエンドポイント。`api-design`)。画面から既存のAPIを順に呼ばない(途中の失敗で一部だけ確定するため)。
  - 422の項目のエラーはその項目の下に出す(`utils/fieldErrors.ts`、§2.19)。離脱警告(§2.9)を付ける。
  - 行の追加・削除のような別の操作は、まとめて保存に含めず別のセクションにする。
  - 例: 利用者詳細(`UserRoleEditPage`)、ロール詳細(`RoleDefinitionPage`)。変更セット`20261125-save-all-user-and-role`。
- **Auto Save**: 明確な理由がある場合のみ。採用時はSaving / Saved / Errorをユーザーが
  確認できるようにする。

保存方法を画面ごとに勝手に変えない。

### 2.9 Cancel / Back / Unsaved Changes

- `← 戻る` = Navigation(前の情報階層へ)
- `[キャンセル]` = 現在の変更の破棄

編集途中で離脱すると変更が失われる場合は離脱警告を出す。対象はBrowser Back、他ページへの
Navigation、Cancel、実装可能な範囲でのタブクローズ。**変更が存在しない場合は警告しない**。

### 2.10 URLと一覧状態

可能な限り以下をURLへ表現する。

```
/users?status=active&group=sales&page=3
```

一覧 → 詳細 → 一覧と戻ったとき、検索条件・フィルター・ソート・ページを可能な限り維持する。
`navigation/backState`の`useSearchParamsKeepingBackState`を使う(戻り先情報を保つため。§2.26)。

#### URLに載せるかの基準

判断の軸は「リロード・URL共有・Browser Backのあとに、**同じものが表示されるべきか**」。画面が**何を**
表示するかを決める状態はURLに載せ、**どう入力・操作している途中か**を表す状態は載せない。

**URLに載せる(必須)**

| 種類 | 例 |
|---|---|
| 一覧の検索・絞り込み・並び替え・ページ | `q`・`status`・`group_type_id`・`page` |
| 画面の表示対象の選択(対象社員・対象期間) | 勤怠参照の`userId`・`yearMonth`、週次勤怠の`start` |
| 詳細パネル・サイドパネルの対象ID | 承認一覧の`requestId`、端末一覧の`deviceId` |
| そのページ専用のタブ・表示切り替え(どの画面・フォームを開いているか) | ロール定義の`roleId`、運用コマンドの`command` |

**URLに載せない**

| 種類 | 理由・代わりの手段 |
|---|---|
| フォームの入力中の値(下書き) | 離脱警告(§2.9)で守る。新規・編集フォームのパラメータ入力を含む |
| 一括操作のチェック選択・行の展開・アコーディオン | 一時的な操作状態。リロードで解除されてよい |
| 展開した行・カードの中の絞り込み(行ごとに独立し、同時に複数開ける) | 1つのキーに対応しない |
| Dialog・Sheetの開閉と、その中の選択 | 一時的な操作状態(詳細パネルは上の「載せる」) |
| 複数の画面に埋め込まれる共通部品の内部状態 | 同じ画面に複数並ぶ・埋め込み先のキーと衝突する(例: `AttendanceMonthReferenceTabs`の月次/週次/日次タブ) |
| 操作結果の通知・エラー・発行したトークン等 | 再表示すべきでない。秘密情報はURLに出さない |

**他画面からの初期値(プリフィル)**: `?date=`・`?dates=`・`?type=`のように遷移元が新規フォームの初期値を渡す
パラメータは「状態」ではなく「初期値」として扱い、画面を開いたときに一度だけ読む(以後フォームの値と同期しない)。

迷う場合は「その状態のままURLを同僚に送ったら、相手に同じ表示を見せたいか」で判断する。見せたいなら載せる。

URL同期の書き方は次の規則で揃える(参照実装: `ApprovalsPage`の`updateParams`)。

- 更新は`setSearchParams`の関数形式で`prev`を複製し、値`null`でキーを削除、`{ replace: true }`で
  履歴を積まない(Browser Backで1つ前の選択ではなく前の画面へ戻る)。
- 画面の対象社員のキーは`userId`。既存に同じ概念のキーがあればその名前に合わせる。
- 年月`YYYY-MM`・年`YYYY`などの不正値は既定値で表示し、URLからそのキーを消す(表示とURLを一致させる)。
  消す処理は不正値のときだけ動かし、ループさせない。判定の目安: 日付は`YYYY-MM-DD`で暦に存在する日、
  ページは1以上の整数、数値IDは正の整数、UUIDは版を問わない8-4-4-4-12桁の16進、列挙値は画面の選択肢
  (backendが選択肢以外の値も受け付ける場合は判定しない。正当な値を消さないことを優先する)。
- 固定の既定値(1ページ目など)はURLに書かない。日付で変わる既定値(今月・今週)は、キーがない初期表示では
  書かず、利用者が選んだ・移動したときは書く。
- 真偽値のフラグは、オンを`"1"`、オフ(既定)はキーを書かない。読み取りは`searchParams.get(key) === "1"`。
- 社員などIDで選ぶ入力は、IDだけ渡されても名前を表示できること(`UserPicker`・`MultiUserPicker`参照。URL復元・リロード・
  親からの値変更でも空欄にしない)。

### 2.11 Dialog / Sheet / Page の使い分け

| 用途 | 使うもの |
| --- | --- |
| 短時間で完了する補助操作(削除確認・簡単な状態変更・小規模な選択) | Dialog |
| コンテキストを維持したまま少し多くの情報を扱う(Filter、モバイルナビ、補助情報) | Sheet |
| 考慮が必要な作業(ユーザー作成、働き方編集、カレンダー編集、権限設定) | Page |

入力項目が増え始めたDialogをそのまま巨大化させない。項目が増えたらPageに移す。

### 2.12 変更操作の確認の基準(Delete / Destructive Actionを含む)

| 分類 | 例 | 確認 | 実行後 |
|---|---|---|---|
| 取り消せる・単一対象・フォームで値を選んで実行 | 保存、ステータス変更、担当者変更、有効/無効の切替 | なし | 成功トースト |
| 取り消せる・状態を進める(後で取消・再提出で戻せる) | 提出、差戻し(コメント入力が確認を兼ねる) | なし | 成功トースト |
| 取り消せない・重大・影響が広い | 削除、取消、却下、承認(個別・一括とも)、トークン再発行、失効、確定、カレンダーの公開 | `ConfirmActionDialog` | 成功トースト |
| 一括操作(すべて) | まとめて承認、担当者の一括変更、一括完了、一括付与、まとめて取消 | `BulkActionDialog`(件数・入力・結果) | 成功トースト(件数)/一部失敗はダイアログ内に内訳 |
| 画面遷移だけの操作 | 勤怠の選択モードから申請画面へ | なし | なし(遷移先が結果) |

確認ダイアログの文言はタイトル「〜しますか?」、説明に対象と**結果**(何が起き、取り消せるか)、実行ボタンは動詞(§2.25と同じ型)。

```
この申請を承認しますか?

承認すると取り消せません。申請者に承認の通知が届きます。

[キャンセル] [承認する]
```

確認させるのは「操作」ではなく「結果」。Undo(取り消し)は、取り消し用のCommand/Eventがある操作に限って検討する。

### 2.13 Status Change

状態の「表示」と「変更」を分離する。

```
表示:     状態  有効
編集:     状態  [有効 ▼]
アクション: 状態  有効   [無効にする]
```

表示専用画面にSelectを置いて「編集できそう」に見せない。

### 2.14 Disabled / Hidden

- **Disabled**: ユーザーが操作の存在を知る必要がある場合。**必ず押せない理由を添える**。

  ```
  [保存 disabled]
  所属グループを選択してください
  ```

- **Hidden**: 存在自体を見せる必要がない場合(機能が完全無効、権限上アクセス不能)。

理由の分からないDisabledを大量に並べた画面にしない。

### 2.15 Permission

権限不足をErrorと混同しない。以下は別状態として扱う。

```
Loading / Empty / Error / Permission Denied / Unavailable
```

権限不足は「失敗」ではないので、再試行ボタンではなく、誰に依頼すべきか等の次の行動を示す。

### 2.16 Loading

- `components/LoadingState`を使う
- Skeletonは最終表示とおおむね同じ高さ・行数にし、大きなLayout Shiftを起こさない
- 画面全体が真っ白になる実装を避ける(部分更新中は既存表示を保持する)
- 一覧の2回目以降の読み込み・取得エラーは表の場所だけに出し、主軸タブ・フィルターバーは残す(§2.20)

### 2.17 Empty State

**Initial Empty**(まだデータが無い)と**Filtered Empty**(条件に一致しない)を区別する。`components/EmptyState`で出し、文言を次の2種に揃える。

```
Initial:
グループはまだありません。
「新規作成」からグループを作成できます。
[新規作成]

Filtered:
条件に一致するグループはありません。
検索条件を変えると表示される場合があります。
[検索条件をクリア]
```

- Initial: 見出し「{対象}はまだありません。」。主操作がある画面で、操作できる人には説明「「{主操作の文言}」から{対象}を作成できます。」(ボタン名は「」で囲む)と、
  主操作と同じボタン(`variant`は primary、sizeは既定)を出す。主操作が無い、または操作できない人には説明とボタンを出さない。
- Filtered: 見出し「条件に一致する{対象}はありません。」+説明「検索条件を変えると表示される場合があります。」+「検索条件をクリア」(`variant="secondary"`、sizeは既定)。
- 主軸タブのタブごとのInitial文言は§2.20のとおり。端末の「削除済みの端末も表示」のように件数が減らないだけの切替はFilteredにしない。

両方を単に「データがありません」にしない。(根拠: 変更セット`20261123-list-layout-standard`)

### 2.18 Error / Feedback

Errorでは「何が失敗したか」と「次に何ができるか」を示す。内部ExceptionやAPIの生メッセージを
そのまま表示しない。

```
ユーザー一覧を取得できませんでした。
[再試行]
```

状態遷移は最低限 `Idle → Submitting → Success / Error` を考慮する。表示は
`components/ErrorMessage`(Field Errorも展開される)を使う。

**フォームの補足とエラーの位置**(変更セット`20261123-form-layout-standard`): ボタン行の直上に、上から 補足 → 送信のエラー(`ErrorMessage`)→ ボタン行 の順に置く。
- 補足(フォーム全体の注意、「保存すると〜されます。」、無効の理由「〜を入力してください。」)は`text-xs text-muted-foreground`で、句点で終える。
- Dialogでは、補足とエラーを本文の末尾(`DialogFooter`の外の直上)に置き、`DialogFooter`の中に入れない。共有の`ConfirmActionDialog`は children → エラー → footer の順。
- 狭い幅でボタン行が縦に積まれても、直上ならどの器でも同じ位置になる(「送信ボタンの直下」にしない)。

**成功はトースト**で知らせる(`notify.success(message, { description? })`。`src/lib/notify.ts`。画面から`sonner`を直接呼ばない)。
- 文言は「何がどうなったか」(「ステータスを処理中に変更しました」「3件のタスクの担当者を山田 太郎さんに変更しました」)。「成功しました」等の汎用文言は使わない。
- 保存後に別画面へ遷移する場合も、遷移の直前に`notify.success`を呼ぶ(トーストは遷移先でも見える)。インラインの「保存しました」・Badgeは使わない。
- 表示はPC右下・モバイル下端全幅、10秒、ホバー・フォーカス中は止める、同時3件まで、`aria-live=polite`(`components/ui/sonner.tsx`)。
- **エラー・部分失敗はトーストにしない**。操作した場所(ダイアログ内、送信ボタン行の直上)に`ErrorMessage`で出す。カード上部にまとめない。
- テストでは`notify`を`vi.mock`し、呼ばれた文言を確かめる。

### 2.19 Form Interaction

- 原則1列、ラベルは項目の上(`components/FormField`)。ラベルの無い入力を作らない(aria-label・placeholderだけの入力は`FormField`にし、その語をラベルにする)
- PlaceholderをLabel代わりにしない
- 関連項目はSectionでGroupingする(大量のフィールドを単純に縦に並べない)
- 不要な必須項目を作らない
- Errorは対象の入力項目の近くに表示する
- Submit Error(全体)とField Error(項目)を区別する

レイアウトの規則(変更セット`20261123-form-layout-standard`):
- **横並びは次の4種(組で1つの値・範囲を表す2項目)と曜日の格子だけ**。`components/FormRow`を使う(`columns`は2または4。自分の幅のコンテナクエリで列を決めるので、ページ・Dialog・Sheetで同じに動く)。
  1. 期間の開始と終了(入社日と退社日を含む) 2. 時刻の開始と終了 3. 数値と単位 4. 月と日(年度開始の月・日など)
  - 曜日ごとの休日区分のような同種の短い選択の集まりは、例外として格子(`FormRow columns={4}`)に並べる。
  - 上記以外は1列(コードと名称、名称とカテゴリ、2つのしきい値、複数の数値の設定も1列)。
- **`FormLayout`**(`max-w-2xl`、`@container`)の中に項目・セクション・ボタン行を置く。Cardは全幅のまま。項目間の余白は`FormField`の`mb-4`に任せ、`gap`を足さない。
  - 明細行エディタ(表形式で行を繰り返すもの)は`FormLayout`の外(全幅)に置き、外側を`@container`にして3列以上は`@2xl:`以上でだけ横に並べる。構成は `<FormLayout>基本の項目</FormLayout>` → 明細行エディタ → `<FormLayout>補足・エラー・FormActions</FormLayout>`。
  - **Dialogでは`FormLayout`を使わない**(器の幅が狭いため)。`DialogFooter`を使う(§2.5)。
- **`FormSection`**(`title`・`description?`・`headingLevel?: 2 | 3`)で区切る。枠線で囲わず、見出しの下に区切り線(`border-b pb-2`)を引く。見出しレベルは、Cardのtitleを外した画面(h1の直後)では2、Cardのtitleの下では3(既定)。見出しの無い先頭のまとまりは`FormSection`にせず、そのまま並べる。
  - 枠(`rounded-md border p-3`)を残すのは、繰り返す単位(明細行の1件、付与表の1行、Permissionのカテゴリ)と、入力ではない表示の箱(結果・プレビュー・警告・登録済みの表示)だけ。説明文だけの箱は外して、ふつうの説明文にする。
- **説明文**は`FormField`の`description`(ラベルの下・入力の上。`text-xs text-muted-foreground`、句点で終える)。直書きの`<p>`にしない。チェックボックスは`FormField`を使わないので、ラベル直下に`<p className="mt-1 text-xs text-muted-foreground">`で置く。
- **Dialogの本文の項目は1つの`<div>`にまとめる**(`DialogContent`の`gap-4`が項目ごとにかかって`FormField`の`mb-4`と重なり、余白が2倍になるため。`ConfirmActionDialog`はchildrenを1つの`<div>`で包む)。
- ボタン行・補足・エラーの順序と位置は§2.5・§2.18。文言は§2.7。

### 2.20 Search / Filter / Sort / Pagination

検索のある一覧は、次の標準形に従う。共通部品は`frontend/src/components/ListFilterBar/`・`TextFilterInput/`・`ListQueueTabs/`を使い、画面ごとに作らない。

#### 構成

一覧Cardの本文の先頭に「主軸タブ → フィルターバー → 一括操作バー → 表 → Pagination」の順で置く。検索専用のCardは作らない。
Cardの`actions`は「新規作成・CSVダウンロード」等の画面の操作の場所とし、絞り込みを置かない。

```
┌ Card: 承認一覧 ───────────────────────────────────────┐
│ [承認待ち 12] [承認済み 30] [差戻し済み 2] [取消 1] [すべて 46]   ← 主軸タブ(作業キュー型のみ) │
│ 年月                                                              │
│ [2026年11月 ▼]   [検索条件をクリア]                  ← フィルターバー │
│ [3件を選択中] [まとめて承認する]               [選択を解除]  ← 選択時のみ(§2.21) │
│ Table                                                             │
│ Pagination                                                        │
└───────────────────────────────────────────────────┘
```

#### 作業キュー型と台帳型

- **作業キュー型**: 自分が処理すべきものを消化する一覧(承認・タスク・通知)。主となる1軸を件数付きタブにする。
- **台帳型**: 記録を探す一覧(申請・社員・備品・監査ログ等)。複数条件を組み合わせて探すのでタブを使わず、フィルターバーだけで絞る
  (1軸だけタブに出すと条件の優先度が誤って伝わる)。
- 新しい一覧を作業キュー型にするかは変更セットで判断する。

主軸タブの規則:

- タブは1画面1段まで。選択肢は5つ以下の固定値。`ui/tabs`を使い、狭い幅では折り返して全タブを見せる(`ListQueueTabs`は`h-auto flex-wrap`。横スクロールにしない。続きのタブと件数が見えなくなるため)。2行になると最終行は左詰めになるが許容する。
- 既定タブは原則「要対応」(承認待ち・未割り当て)で先頭に置く。「すべて」は既定でなければ末尾。既定を要対応以外にする場合は変更セットに理由を書く。
- **全タブに件数を出す**(「承認待ち 12」)。件数は「タブの軸以外の条件(キーワード・フィルター)を適用した件数」とし、一覧APIの`meta.<軸>_counts`で
  一覧の取得と同じ応答で返す(別APIを呼ばない)。aria-labelにも件数を含める。
- タブごとにInitial Emptyの文言を持つ(例: 「承認済みの申請はありません。」)。Filtered Empty(§2.17)にするかの判定は**タブを除いた**フィルターバーの条件だけで行う。
- タブはURLに載せる(既定値は載せない)。切り替えたら`page`を消し、選択を解除する。フィルターバーの条件と詳細パネルの対象ID(`requestId`等)は保つ。
- タブの軸と同じ意味のselectをフィルターバーに重ねて置かない。別軸のselect(タスクのステータス等)は残す。

#### フィルターバー

- 全項目に可視ラベルを付ける(`FormField`、項目の上)。placeholderやaria-labelだけにしない(§2.19)。
- 並び順: キーワード → 主な状態・区分のselect → その他のselect・チェックボックス → 年月・期間 → 「検索条件をクリア」(末尾)。
- キーワード欄: ラベル「キーワード」、placeholderは検索対象を「・」区切りで列挙する(「タイトル・種別・担当部署・担当者名」。「〜で検索」は付けない)。`type="search"`。
  検索対象が1項目だけなら、ラベルをその項目名にする(「社員名」「名称」)。
- キーワード以外の自由入力の絞り込み(管理番号・対象ID等)も`TextFilterInput`を使い、反映方式をそろえる。
- selectの「すべて」の選択肢文言は「すべて」に統一する(「すべての種別」「全〜」にしない。何のすべてかはラベルで分かる)。
- チェックボックスはラベルを右に置く(`FormField`は使わない)。高さはほかの項目の入力欄の行にそろえる。
- 期間は`DateRangePicker`1つにする(開始・終了のDatePicker2つに分けない)。年月は`YearMonthPicker`。
- レイアウトと幅は`ListFilterBar`が持つ(`flex flex-wrap items-end gap-x-4 gap-y-3`)。`ListFilterBar`は`@container`で、項目の幅は画面幅ではなくフィルターバー(本文)の幅で決める
  (管理画面は管理メニューの分だけ本文が狭く、画面幅では本文の幅が分からないため。区切りは`@sm`=24rem)。
  フィルターバーが24rem未満なら全項目が全幅で縦に並び、以上の基本は キーワード`@sm:w-72`・select`@sm:w-44`・年月`@sm:w-48`・期間`@sm:w-72`(`filterWidth`)。選択肢文言が長い場合のみ広げる。
  申請種別などのselectも`filterWidth.select`を使い、画面ごとの固定幅を作らない。
- 末尾のボタン(「詳細条件」「検索条件をクリア」)は1つのまとまり(`ml-auto flex gap-2`)にして項目の後ろに置く。折り返すときはまとまりごと次の行の右端へ行き、ボタンが1つずつ取り残されない
  (項目と同じ折り返しに入れない)。クリアボタンが条件の入力で現れて、まとまりが次の行に落ち表が下がることは許容する。

#### 詳細条件

- 直接表示する項目は「検索条件をクリア」を除いて4つまで。頻繁に使うものだけを直接表示し、低頻度の項目は「詳細条件」ボタンで開閉する領域にまとめる。
- 詳細条件はフィルターバーの直下にインラインで展開する(Dialog/Sheetにしない。条件を見ながら結果を確かめるため)。
- ボタン文言は「詳細条件」(`variant="secondary"`、開閉の`ChevronDown`/`ChevronUp`アイコン付き)。既定以外の値が入っている数を「詳細条件(2)」のように出す。
- 詳細条件の領域は、フィルターバーの下の枠付きの面(`rounded-md border bg-muted/40 p-3`)で、中は等幅のグリッド(`@sm:grid-cols-2 @3xl:grid-cols-3`)。
  項目の幅はグリッドに任せ、**呼び出し側で詳細条件の項目に`filterWidth`を渡さない**。チェックボックスの項目は`h-9`の行にして、入力欄の下端に揃える。
- 詳細条件に値がある状態でURLから開いたときは最初から開いておく。開閉状態自体はURLに載せない(§2.10)。

#### 反映方式

- 全項目を即時に反映する。「検索」ボタンは置かない。
- テキスト入力(`TextFilterInput`)は最後の入力から300ms後に反映する。Enter・blurでは待たず即反映する。
- 日本語入力の変換中(`compositionstart`〜`compositionend`)は反映しない。`compositionend`で待ちを開始する(inputイベントだけを見ると確定文字が反映されないブラウザがある)。
- 前後の空白を除いて反映し、空ならURLのキーを消す。入力欄の表示は除去しない(打ち続けるための末尾空白を残す)。反映中もフォーカス・カーソル位置は保つ。
- URLの値が**外から**変わったとき(戻る・クリア・タブ切替)だけ入力欄の表示を合わせる。判定は「直前に自分が反映した値と異なる」こと。反映待ちの間は合わせない。
- 画面を離れたら(アンマウント)予約中の反映を取り消す(遷移先のURLを書き換えないため)。発火時の`onChange`はrefで最新を参照する
  (`useSearchParamsKeepingBackState`のsetterはlocation.stateが変わるたびに作り直される)。
- `maxLength`を付け、バックエンドの検証上限に合わせる(上限が無ければ100)。
- 反映待ちの300ms以内にCSVダウンロード等を押した場合は、反映済み(URL)の条件で動く。これは許容する。

#### クリア

- 文言は「検索条件をクリア」(`variant="secondary"`)に統一する。フィルターバー末尾(既定以外の条件があるときだけ)とFiltered Empty(§2.17)の両方に置く。項目が1つだけのフィルターバーでも同じ。
- クリアの対象はフィルターバー(詳細条件を含む)の全項目と`page`。主軸タブと詳細パネルの対象ID(`requestId`等)は戻さない。押下後はキーワード欄の表示も空にする。
- フィルター有効の表示は「検索条件をクリア」の出現と「詳細条件(n)」で足りるものとし、項目ごとの個別ハイライトはしない。

#### URL

- 全一覧で`navigation/backState`の`useSearchParamsKeepingBackState`を使う(詳細から戻ったときに条件を復元するため。§2.10・§2.26)。
- キーワードのURLキーは`q`、作業キューのタブ・主な状態は`status`(タスクの担当者区分は既存の`assignee`)に統一する。
  バックエンドのパラメータ名が違う場合は`src/api/*.ts`で変換し、バックエンドのパラメータ名は変えない。
- 条件・タブを変えたら`page`を消し、選択を解除する(§2.21)。更新は§2.10の書き方(関数形式・null削除・`replace: true`)。

#### 読み込み・エラー

- 初回読み込みのみ`LoadingState`で置き換えてよい。2回目以降の読み込み中・取得エラーは表の場所にだけ出し、主軸タブとフィルターバーは常に残す(条件を直せなくならないように。§2.16)。
- 一覧を取得するhookは`placeholderData: keepPreviousData`を必須にする(条件変更のたびに表がLoadingに置き換わらないように)。

#### ページング

- ページングは`components/Pagination`だけを使う(独自のページャーを作らない。クライアント側でページを分ける一覧も`Pagination`に渡す。番号ボタンは持たず前後ボタンのみ)。
  件数(「{total}件中 {c} / {p} ページ」)は`Pagination`の中の1か所だけに出し、表の上など別の場所に重ねて出さない。
  `Pagination`は表の直下、Cardの本文の末尾に置く。1ページなら出さない。件数の文言が収まらないときは折り返す(`flex-wrap`)。
- 一覧を作る・変えるときは、件数が増え得るかを必ず検討し、増え得るならAPI側でページングし(`paginate`)、画面は`components/Pagination`を使う。
  全件を返す・表示する場合は、件数が増えない理由を変更セットに書く。
- 状態はURLに載せる(§2.10)。

根拠: 変更セット`20261122-list-search-form-standard`・`20261123-list-layout-standard`(タブの折り返し・末尾ボタンのまとまり・詳細条件の面・コンテナクエリ・ページングの表示)。

### 2.21 Selection / Bulk Actions

一括操作は`components/BulkActionBar`と`components/BulkActionDialog`で組む(画面ごとに独自のバーを作らない)。

```
[3件を選択中]  [担当者を変更] [完了にする] [その他 ▼]            [選択を解除]
```

- **バー**(`BulkActionBar`): 選択があるときだけ、表の直上(主軸タブ・フィルターバーの下。フィルターバーより上に置かない)に1つだけ置く(Cardの`actions`スロットに置かない)。「n件を選択中」・操作ボタン
  (4つまで。超える分は「その他」メニュー)・「選択を解除」。モバイル(`sm`未満)は2行構成(1行目: 件数と選択を解除、2行目: 横スクロールの操作列)。
  ボタンはダイアログを開くだけで、**バーの中に入力欄を置かない**。押せない操作はdisabled+理由(§2.14)。
- **ダイアログ**(`BulkActionDialog`): タイトル「〜しますか?」、対象の要約(件数、必要なら内訳・対象外・変更不要の件数)、入力欄、結果の説明、実行ボタン「n件を〇〇」。
  実行の結果は`{ succeededIds, failures: [{ id, label, message }] }`の型にそろえる。
  - 1件ずつ呼ぶ操作は`Promise.allSettled`で詰める。一括APIが1件ずつの結果を返す操作はその結果を詰める。全体成功/全体失敗の一括APIは失敗時に例外を投げ、
    ダイアログ内に`ErrorMessage`を出して選択を残す。
  - 全件成功: 閉じて選択を解除し、成功トースト(件数)。一部失敗: 開いたまま「n件を〜しました。m件はできませんでした。」と失敗した行と理由を出し、**成功した行は選択から外し、
    失敗した行だけ選択に残す**(再実行で二重実行しない)。
- フィルター・ページ・検索条件が変わったら選択を解除する(一覧間で揃える)。
- **選択列は、選択できる行が1つでもあるときだけ出す**(見出しと本体の両方を、同じ条件で出し分ける)。選択できる行が無い画面(取り消せる申請が無い一般の社員、操作できる承認が無い一覧、判定が`Eligible`の行が無い付与予定)に、空の列を残さない。
  `BackOfficeTaskListPage`・`WorkflowRequestListPage`・`ApprovalsPage`・`PaidLeaveSchedulePage`が同じ形(変更セット`20261124-list-display-fixes`)。
- 実装例: `frontend/src/pages/backOffice/BackOfficeTaskListPage.tsx`。勤怠の選択モード(`AttendanceSelectionActionBar`)は申請画面への遷移だけの別物で、この規則の対象外。

### 2.22 Keyboard / Accessibility

最低限、Tab / Shift+Tab / Enter / Space / Escape / 矢印キーを壊さない。Custom Shortcutは
必要性が明確な場合だけ追加する。あわせて:

- Icon Buttonにaccessible nameを付ける
- Errorを色だけでなくテキストでも示す
- Click Targetを極端に小さくしない

Focus Indicatorの見た目・消さないルールは`ui-design-system` §2.5。

### 2.23 Progressive Disclosure

低頻度・高度な設定は初期表示で目立たせない。ただし「隠せばよい」ではなく、必要なときに
**発見できる**構造にする。

```
基本設定

詳細設定 ▼
```

### 2.24 オブジェクト起点で設計する(OOUI)

- **Object First**: 「編集する → 誰を?」ではなく「従業員 → 次に何をする?」の順で設計する。
- **Canonical Detail Page**: 主要業務オブジェクト(Employee / Attendance Day / Application /
  Company Calendar / Calendar Year / Expense Claim)は正史となる詳細画面を1つ持つ。どこから
  参照されても同じ詳細画面に辿り着く(実例: 会社カレンダー本体`CompanyCalendar`の詳細画面
  `WorkCalendarDetailPage`に、名称・年度一覧・祝日iCalendar設定をすべて集約し、年度
  `CompanyCalendarYear`の同期もその年度オブジェクトの行に文脈として置く)。
- **Related Objects**: 関連オブジェクトは一覧側に要約だけ埋め込み、詳細は正史画面へ逃がす。
- **失敗の兆候**: 同じエンティティが無関係な複数画面からバラバラの項目だけ編集でき、
  「このオブジェクトに関するすべて」を見られる場所が無い状態。
- **入れ子オブジェクト**を意識する。あるオブジェクトが別オブジェクトを内包/参照する場合
  (カレンダー年度が日別設定を内包する、申請が承認履歴を内包する等)、一覧では要約だけ
  埋め込み、詳細はリンクで正史画面に逃がす。

### 2.25 同種の業務を持つ画面群の操作感の統一(休暇)

有給・特別休暇・代休は、同じ種類の業務(付与・消化・申請・取消)を持つ画面群として扱い、操作感を揃える。
1つの休暇の画面・APIを変えるときは、残りの休暇にも同じ変更が要るかを必ず確かめる(変更セットの
「設計前提の検証」の横展開。`changeset`スキル)。

- 対象の画面:
  - 本人の画面:`pages/paidLeave/MyPaidLeavePage.tsx`・`pages/specialLeave/MySpecialLeavePage.tsx`・
    `pages/compensatoryLeave/MyCompensatoryLeavePage.tsx`
  - 管理画面:`pages/paidLeave/PaidLeavePolicyPage.tsx`(付与・使用状況)・`pages/specialLeave/SpecialLeaveAdminPage.tsx`・
    `pages/compensatoryLeave/CompensatoryLeaveAdminPage.tsx`
- 揃えるもの:
  - 付与一覧の行内の操作は共通部品を使い、同じ並び・同じ名称にする(取消:`components/RevokeGrantButton`、
    付与日数の変更:`components/ChangeGrantDaysButton`(変更セット20261025-edit-leave-grant-daysで追加))。
  - ある休暇でできない操作は、ボタンを隠さず同じ位置に置き、押せない理由を`disabledReason`で示す
    (例:代休の日数は勤怠から決まるため変更できない)。§2.14と同じ考え方。
  - 本人の画面の付与一覧・合計に出す付与の条件(取り消した付与・残りの無い付与を出さない等)、申請一覧の並び順・
    ページングは、3種で同じにする。条件は本人向けのAPI(`/*/grants/mine`・`/*/requests/mine`)側で揃える。
  - 確認ダイアログの文言の型(タイトル「〜しますか?」、説明に対象の付与日・日数、取り消せない場合はその旨)を揃える。
- 揃えられない差がある場合(休暇ごとの業務ルールの違い。例:有給は最新の付与だけ変更・取消できる)は、
  判定をAPI側に置き、画面は押せる/押せないと理由の表示だけを揃える。差と理由は変更セットに書く。

### 2.26 画面階層と戻る導線

目的: どの画面から入っても「今どこにいるか」「どこへ戻れるか」が分かり、戻った先が
**来たときと同じ表示**(絞り込み・ページ・対象期間)になること。

#### 1. 画面階層は1か所で定義する

- すべてのルートは`frontend/src/routes/pageHierarchy.ts`に **画面名**と**親画面**を持つ。
  ルートを追加したら必ずここにも追加する(未登録のルートはテストで落ちる)。
- 階層は「サイドバーの項目(第1階層)→ 詳細・履歴・編集など(第2階層以下)」の木構造にする。
  例: `有給(/paid-leave)` → `有給履歴(/paid-leave/history)`、
  `月次勤怠一覧` → `○年○月の勤怠` → `○月○日の勤怠`。
- 親は「その画面を**直接URLで開いたとき**に戻るべき画面」で決める(遷移元が分からないときの既定値)。
- サイドバーから直接開ける画面(日次・月次など)は`menuEntry: true`を持つ。直接開いたときは親があっても戻るリンクを出さない。

#### 2. 第2階層以下の画面は、左上に必ず戻る導線を置く

- 共通部品`components/BackLink`を使う。ページ内で独自に`<Link to="/...">← 一覧へ戻る</Link>`を書かない。
- 位置: ページ本文の最上部・左寄せ(最初のCard/見出しの上)。1画面に1つ。
- 文言: `〈 {戻り先の画面名}に戻る`(アイコンは`ChevronLeft`。テキストの「←」は使わない)。
  例: 「有給に戻る」「申請一覧に戻る」「2026年11月の勤怠に戻る」。
- 第1階層の画面は親を持たないため、掘り下げで来たときだけ戻るリンクが出る。掘り下げの行き先になる第1階層の画面
  (週次勤怠・有給・特別休暇・代休など)には`BackLink`を置く。ホーム・申請一覧など掘り下げの行き先にならない
  第1階層の画面には置かない。
- `navigate(-1)`/`history.back()`を戻る導線に使わない(直接開いたときアプリ外へ出る・戻り先が予測できない)。

#### 3. 戻り先は「来た画面」を優先し、来たときの表示を復元する

戻り先は次の順で決める(`useBackTarget`が解決する)。

1. **遷移元**: リンク・行クリックで来た場合は、その画面(パス+クエリ)へ戻る。
   一覧の絞り込み・ページ、週次の`start`などは来たときのまま復元される。
   例: 有給 →(差戻し通知)→ 申請詳細 の「戻る」は **有給に戻る**(申請一覧ではない)。
2. **親画面**: 直接開いた場合(URL直接・ブックマーク・共有リンク・再読込・新規タブ)は2分類で扱う。
   - **メニューから開ける画面**(`pageHierarchy`の`menuEntry: true`。日次・月次などサイドバーの行き先): 戻るリンクを出さない
     (メニューから開いたのと同じに扱う)。
   - **単独では成り立たない画面**(申請詳細・経費の編集・有給履歴など、親の中の1件・付属の画面): `pageHierarchy`の親へ戻る。

**進むリンクの分類**(画面内から別の画面へ進むリンク・`navigate`を、次の3種類に分ける):

| 種類 | 判定 | 例 | 戻るリンク |
|---|---|---|---|
| **掘り下げ** | 遷移元の画面に表示されている**1件**(一覧の行・集計の内訳・警告やお知らせのカード・参照先)を選んで**その1件**を開く。または、遷移元の対象の**付属画面**(履歴・編集・作成)を開く。遷移先は遷移元の**中にあるもの**。 | 週次・月次の日の行 → 日次、月次の週の警告 → その週の週次、月の一覧 → ○年○月、申請一覧 → 申請詳細、ホームの「今日の勤怠」→ 日次、有給 →「履歴」 | 出す(遷移元へ戻る) |
| **切り替え** | 見る単位・見る場所を替える。遷移先は遷移元を**含む全体**か、**並列の別の画面**。メニュー・ヘッダからの移動もこれ。 | メニュー → 日次、日次 →「週次」(その日を含む週)、週次 →「月次勤怠」(その週を含む月)、ホームの集計カード「詳しく見る」→ 申請一覧、ユーザー一覧 →「外部ID・HR連携」 | 出さない(それまでの戻り先も捨てる) |
| **横移動** | 同じ単位の隣へ動く。 | 前日/翌日/今日、前月/翌月/今月、画面内タブ・絞り込み | 掘り下げで来ていれば、その戻り先をそのまま保つ |

迷ったときの問い: **「遷移先は、遷移元の画面の中にあるもの(表示されていた1件、またはその画面の対象の付属画面)か?」**
はい → 掘り下げ、いいえ(全体・並列の画面)→ 切り替え。
同じ画面でも、どう来たかで扱いが変わる(例: 週次は、メニューから来れば切り替え、月次の週の警告から来れば掘り下げ)。

実装規則:

- 画面内の進むリンク・`navigate`は、上の分類で**掘り下げか切り替えかを判定する**。
  - **掘り下げ**(行クリック・「履歴を見る」・「申請詳細を開く」・作成画面へのボタンなど)は、進む先の階層に関わらず
    `useForwardState()`が返す`state`を渡す。これで遷移元(パス・クエリ・ハッシュ・遷移元自身の戻り先)が履歴に記録され、
    遷移先で戻るリンクが出る。
  - **切り替え**(メニュー・ヘッダ・「週次」「月次勤怠」への移動・並列の別メニューへの移動など)は`state`を渡さない。
    それまでの戻り先も捨てるため、遷移先で戻るリンクは出ない。
  - **横移動**(前日/翌日・前月/翌月・画面内タブ)は後述の「同じ階層内の移動」に従い`useKeepBackState()`で引き継ぐ。
- 掘り下げの具体例(勤怠): 週次・月次の日の行 → 日次は`useForwardState()`を渡し、日次で「週次勤怠に戻る」「○年○月の勤怠に戻る」が出る。
  切り替えの具体例(勤怠): 日次 →「週次」、週次 →「月次勤怠」は`state`を渡さず、遷移先に戻るリンクを出さない。
  ホームでは「今日の勤怠」→ 日次が掘り下げ(「ホームに戻る」)、集計カードの「詳しく見る」は切り替え。
  ユーザー管理では、ユーザー一覧の「外部ID・HR連携」や説明文のリンクは並列の別メニュー画面への切り替え。
- **上へ戻る**操作(BackLink・キャンセル・削除後・保存後の遷移)では新しい遷移元を記録しない
  (`useForwardState`を使わない)。代わりに、記録済みの戻り先が持っていた`state`を復元する
  (`useBackTarget`が返す`state`を渡す)。**サイドバー・ヘッダ**のリンクには`state`を渡さない(連鎖をそこで切る)。
- 遷移元の記録は入れ子(遷移元の戻り先も保持)にし、深さは5段まで。超えた分は親画面に頼る。
  これにより「有給 → 申請詳細 → 備品詳細」から戻るを2回押すと有給へ戻る。
- **同じ階層内の移動**(前月/次月、前日/翌日、画面内タブ・絞り込みのURL更新)では、現在の戻り先を引き継ぐ
  (`useKeepBackState()`、`useSearchParamsKeepingBackState()`)。前月へ移動しても戻り先が変わらない。
  履歴の積み方(`replace`の有無)は§2.10のとおりで、ここでは変えない。
- 中クリック・新規タブ・ログインを挟んだ場合・共有URLでは遷移元が分からないため、親画面へ戻る(仕様どおり)。

#### 4. キャンセル・削除後も同じ戻り先へ

- 作成・編集画面の「キャンセル」と、詳細画面で対象を削除した後の遷移は、BackLinkと同じ戻り先へ
  `replace: true`で移動する(`useBackTarget()`の`to`と`state`)。一覧の絞り込みが残り、Browser Backで
  破棄した編集画面・削除済みの詳細に戻らない。
- 「戻る」という名前のボタンで変更を破棄しない(§2.9。破棄なら「キャンセル」)。
- 保存後の遷移先は§2.5・§2.6に従う。遷移先が戻り先と同じ画面ならその`state`を使い、別の画面(詳細など)へ
  進むなら`useKeepBackState()`の`state`を渡す(保存しても戻り先が消えない)。

#### 5. 今いる場所を示す

- サイドバーは、表示中の画面の**第1階層の祖先**の項目を点灯させる
  (例: 有給履歴・申請詳細・過去月の月次勤怠・管理画面の利用者詳細でも親項目が点灯する)。
  判定は`findActiveNavPath`(祖先チェーンを近い順にたどり、最初にナビ項目と一致した段を採る)で行い、
  今日・今月の固定URLの完全一致にしない。
- ブラウザのタブ名(`document.title`)を`{画面名} | flow-office`にする(履歴の長押しメニュー・タブで場所が分かる)。
  画面名は`pageHierarchy`の名前を使い、各ページで個別に設定しない。

#### 6. やらないこと

- パンくずリストは置かない(階層は最大3〜4段で、戻る導線1つと点灯するサイドバーで足りる。モバイルで幅を取る)。
- 画面ごとに戻り先を推測する独自ロジック(`document.referrer`を見る等)を書かない。

### 2.27 狭い幅の表示

画面幅768・390でも崩れないようにする規則。表のカード型表示や列を隠す表示は作らない(全画面分の別表示が要り、必要な情報が見えなくなるため)。

- **表**: `ui/table`の`overflow-x-auto`による横スクロールは保ち、`TableCell`の一律`whitespace-nowrap`も残す。そのうえで
  **利用者が自由に入力する長い文字列の列**(タイトル・件名・名称・説明・理由)の`TableHead`・`TableCell`に`whitespace-normal min-w-48`を付けて折り返す。
  氏名・コード・日付・数値・状態・識別子の列には付けない(例: 付与予定の「社員」)。新しい表を作るときは、各列がどちらかを決める。
- **識別子**(監査ログのイベント種別など、区切りの無い長い文字列)は`break-all`で折り返し、ページ全体が横スクロールしないようにする。
- **期間入力**(`DateRangePicker`)の表示は`min-w-0`を持ち、枠からはみ出さない。
- **管理画面のメニュー**: 縦のサイドバーは`xl`(1280px)以上だけに出し、未満はメニューボタン(Sheet)で開く(`AdminLayout`)。
  アプリのサイドバーと並ぶと、1024pxでも本文が約446pxと狭くなるため。
- 幅の判定は、部品の内側は画面幅の`sm:`・`md:`ではなくコンテナクエリ(`@container`・`@sm:`。§2.20)を使う。単体テスト(jsdom)では幅を確かめられないので、classNameの確認にとどめ、幅1280・768・390の撮影で確かめる。

- **横スクロールの影**: `ui/table`は、横にはみ出しているときだけ、はみ出している側の端に内側の影を出す(`useOverflowEdges`が外枠に`data-overflow-start`・`data-overflow-end`を付け、
  `data-[overflow-start]:`・`data-[overflow-end]:`で影を切り替える)。列の表示の切替・優先度は採らない。影はトークン(`--foreground`を薄めた色)の内側の影で、グラデーションは使わない。
  CSSだけの方式(`background-attachment`)は単体テストで保証できないため使わない。判定はhookのテスト(寸法の差し替え)で、見た目はStorybook(`UI/Table`の`WideTable`)で確かめる。
  根拠: 変更セット`20261124-list-display-fixes`。

根拠: 変更セット`20261123-list-layout-standard`。

### 2.28 列挙値は表示名の関数を通す

画面にAPIの列挙値(状態・区分・種別・管理元・項目のキーなどの内部コード)を出すときは、`{value}`をそのまま描画せず、`utils/statusLabels.ts`の表示名の関数を通す。

- 関数は既知の値を表示名にし、**未知の値はそのまま返す**(新しい値がAPIに増えても画面が壊れない。自由入力の語は管理者が付けた語としてそのまま出る)。`statusLabels.test.ts`に、既知の値と未知の値のテストを足す。
- 同じ概念が既に別の画面にあるときは、その語に揃える(例: 項目名は社員一覧の見出し)。主所属の印のように、同じ意味の表示が隣にあるときは1つにまとめる(`membershipKindLabel`)。
- 対象外: 意図してコードを出す列(勤務形態・ロール・申請種別・経費区分・シフトのパターンのコード、運用コマンド名)、監査ログの集約・イベントの種別、自由入力の文字列。
- 根拠: 変更セット`20261124-list-display-fixes`(論点1)。

---

## 3. Exceptions

### 3.1 例外を出す手順

Interaction Patternから逸脱する場合、無意識に独自実装しない。実装前メモ(§3.3)に次を書く。

```
Pattern exception:
ユーザー作成をDialogで実装する。

Reason:
入力項目が氏名とメールの2項目のみで、作成後も現在の一覧コンテキストを
維持する価値が高いため。
```

### 3.2 通常CRUDと異なるInteractionを許可する画面

Calendar / Scheduler / Spreadsheet / Wizard のような画面は、通常CRUDと異なるInteractionを
許可する。ただし「この画面だけ特殊」ではなく「**Scheduler Patternを使用する**」という扱いに
する。将来的に`ui-page-patterns`として切り出す候補(§4.3)。

### 3.3 実装前メモ(新規画面・大規模刷新の前に)

チャットに長文を貼らず、短いメモとしてまとめる。

1. 画面の目的
2. 主要ユーザー
3. 最重要操作
4. 対象オブジェクト
5. 情報の優先順位
6. 使用するPage Pattern
7. 使用する共通Component(具体名で)
8. Navigation / Save / Cancelの挙動
9. Loading / Empty / Error / Permission
10. PC / Mobile差分
11. 既存Interaction Patternからの例外
12. 例外が必要な理由

---

## 4. Repository References

### 4.1 参照する実装

現時点で最も規約に近い実装。**そのままの模倣ではなく、上記Patternに沿っている部分だけを
参照する**(既存実装には不統一が残っている)。

| Pattern | Reference |
| --- | --- |
| List(フィルター + ページング + 一括操作 + URL状態。作業キュー型) | `frontend/src/pages/backOffice/BackOfficeTaskListPage.tsx` |
| 検索フォーム部品 | `frontend/src/components/ListFilterBar/`・`TextFilterInput/`・`ListQueueTabs/` |
| List(シンプルなTable一覧) | `frontend/src/pages/expense/ExpenseClaimListPage.tsx` |
| 行クリック | `frontend/src/components/ClickableTableRow/` |
| Detail(役割による操作分岐) | `frontend/src/pages/workflow/WorkflowRequestDetailPage.tsx` |
| Create Page(項目数が多くDialogから切り出した例) | `frontend/src/pages/workCalendar/WorkCalendarCreatePage.tsx` |
| Create / Edit Form(項目構成のみ参照) | `frontend/src/pages/expense/ExpenseCategoryEditPage.tsx`(保存後の遷移は§2.6未準拠) |
| Destructive Confirmation | `frontend/src/components/ConfirmActionDialog/` |
| Loading | `frontend/src/components/LoadingState/` |
| Error | `frontend/src/components/ErrorMessage/` |
| Empty State | `frontend/src/components/EmptyState/` |
| Permission Denied | `frontend/src/components/PermissionDenied/` |
| Pagination | `frontend/src/components/Pagination/` |
| Form項目 | `frontend/src/components/FormField/` |
| 戻る導線・画面階層 | `frontend/src/components/BackLink/`・`frontend/src/routes/pageHierarchy.ts`・`frontend/src/navigation/backState.ts` |
| URL状態同期 | `frontend/src/pages/approvals/ApprovalsPage.tsx`(status/yearMonth/page/requestIdすべて同期) |

### 4.2 他スキルとの関係(責務は重複させない)

- `.claude/skills/ui-design-system` — 見た目。トークン・色・余白・タイポグラフィ・角丸・影・
  プリミティブ・Visual Variantの定義。**どの操作をPrimaryとして扱うか**はこのスキル、
  **Primary/Secondary/Destructiveの見た目**はui-design-system。同様に、Disabledの
  **見た目**はui-design-system、Disabledを**使う条件**はこのスキル。
- `.claude/skills/add-page` — ページ実装・ルーティング・ナビ登録の手順。ナビのラベル/構造を
  変えるとe2eが壊れるという警告はそちらが本体。
- `.claude/skills/add-frontend-component` — component + story + testの作り方。
- `.claude/skills/add-api-hook` — データ取得層。このスキルの対象外。

スキル選択の目安:

```
新しい一覧画面        → ui-design-system + ui-interaction-patterns + add-page
CRUD画面の全面刷新    → ui-design-system + ui-interaction-patterns + add-page
新しいDomain Component → ui-design-system + add-frontend-component
Buttonの外観変更      → ui-design-system のみ
```

### 4.3 将来の分離候補

内容が増えたら次を切り出す。今回は分離しない。

- `ui-page-patterns` — List / Detail / Create / Edit / Settings / Calendar / Scheduler /
  Master-Detail / Wizard / Spreadsheet / Dashboard の各Reference Implementation
- `ui-information-architecture` — Object Identification / Relationships / CTA / Attributes /
  ORCA / Canonical Page / Nested Objects / Navigation(§2.24が肥大化したら)
- `ui-review` — Pre Implementation Review / Interaction QA / Accessibility QA /
  Responsive QA / Cognitive Walkthrough / E2E Flow Review

---

## 5. Implementation Checklist

- [ ] 同じ意味の操作が他画面と同じ位置・名称・挙動になっている
- [ ] 休暇(有給・特別休暇・代休)の画面を変えた場合、残りの休暇の画面にも同じ変更が要るかを確かめ、操作感が揃っている(§2.25)
- [ ] UI文言が§2.7の語彙に沿っている(「登録」を一般操作名に使っていない)
- [ ] 強いPrimary Actionが1画面に1つ
- [ ] 行クリックの挙動が同種の一覧と揃っている
- [ ] 低頻度の行内アクションが`DropdownMenu`にまとまっている
- [ ] 保存が明示的で、失敗時に入力値が保持される
- [ ] 保存後の遷移先が作成時/更新時で不自然に非対称になっていない
- [ ] BackとCancelを区別している
- [ ] 新しいルートを`pageHierarchy`に画面名・親付きで登録した
- [ ] 第2階層以下の画面に`BackLink`があり、文言が「{戻り先}に戻る」になっている
- [ ] 進むリンクが掘り下げか切り替えかを判定し、掘り下げだけ`useForwardState()`の`state`を渡している
- [ ] キャンセル・削除後の遷移先が`useBackTarget`の戻り先になっている
- [ ] 前後移動・タブ切替で戻り先が引き継がれている
- [ ] 直接URLで開いても親画面へ戻れる
- [ ] 未保存の変更がある場合だけ離脱警告が出る
- [ ] 一覧のページングの要否を検討した(件数が増え得るならAPIと画面の両方でページングしている)
- [ ] Search / Filter / Sort / PageがURLに反映され、詳細から戻っても維持される
- [ ] 検索フォームが§2.20の標準形(`ListFilterBar`等の共通部品、全項目に可視ラベル、「検索」ボタンなし、「検索条件をクリア」)になっている
- [ ] 管理画面は`PageHeader`(見出し+説明)を置き、管理画面以外はCardのtitleが画面名になっている(§2.1)
- [ ] 一覧の主操作が「新規作成」(業務用語の例外を除く)で、置き場所が§2.1のとおり。空状態の文言が§2.17の2種になっている
- [ ] 表の自由入力の長い列が折り返し、識別子が`break-all`になっている(§2.27)
- [ ] 作業キュー型の一覧は件数付きの主軸タブ(`meta.<軸>_counts`)を持ち、台帳型はタブを使っていない
- [ ] 取得エラー・2回目以降の読み込みでも主軸タブとフィルターバーが残り、一覧hookが`placeholderData: keepPreviousData`を指定している
- [ ] Dialog / Sheet / Page の選択が§2.11に沿っている
- [ ] 戻せない削除に、対象名と不可逆であることを明示した確認がある
- [ ] 状態の表示と変更が分離されている
- [ ] Disabledに理由が添えてある
- [ ] Loading / Empty(Initial・Filtered) / Error / Permission Denied が実装されている
- [ ] すべての操作に Idle → Submitting → Success / Error のフィードバックがある
- [ ] Tab / Enter / Escape / 矢印キーが壊れていない
- [ ] Patternから逸脱した箇所に`Pattern exception:` / `Reason:`を書いた

---

## 6. Rationale

上記ルールの背景。**判断に迷ったときだけ読む**。理論そのものより上のRepository Ruleが優先。

- **Nielsen 10ヒューリスティクス** — システム状態の可視性(§2.18)、ユーザーコントロールと
  自由(§2.9, §2.12)、一貫性と標準(§1-1)、エラー予防(§2.14)、記憶より再認、エラーからの
  回復支援(§2.18)の出典。
- **Fittsの法則**(対象が大きく近いほど到達が速い) → 主要操作はユーザーが操作対象を確認した
  直後にアクセスできる位置へ置く。長いフォームで保存が画面外になる場合はsticky footerか
  末尾のaction areaを検討する。破壊的操作だからと小さくしすぎない。
- **Hickの法則**(選択肢が増えるほど意思決定が遅くなる) → §1-3、§2.3のオーバーフロー集約。
- **Jakobの法則**(ユーザーは他アプリと同じ挙動を期待する) → 独自の一覧/編集操作を発明せず、
  検索+フィルタ+テーブルという業務アプリの定番を踏襲する(§2.1)。
- **Millerの法則**(作業記憶は7±2) → フォーム項目・テーブル列を意味のあるグループに分ける
  (§2.19)。
- **ピークエンドの法則** → 複数ステップ操作の完了表示を明確にし、次に何をすべきかを示す。
- **ツァイガルニク効果** → 複数ステップ入力には進捗表示を出し、下書きを保持して離脱不安を
  減らす。
- **OOUI / OOUX**(Sophia V. Prater、ORCA: Objects / Relationships / Calls-to-action /
  Attributes) → §2.24。ワイヤーフレームの前に対象オブジェクトとその関係・操作・属性を
  洗い出す。参考: [ooux.com](https://ooux.com/what-is-ooux)、
  [Introducing ORCA](https://medium.com/design-bootcamp/introducing-orca-the-third-diamond-in-your-ux-process-23a1babb0389)。
- **フォーム設計**(Luke Wroblewski: 単一カラム・上ラベル・インライン検証・必須を絞る) →
  §2.19。参考:
  [Inline Validation in Web Forms](https://alistapart.com/article/inline-validation-in-web-forms/)。
- **段階的強化** → まず標準のテーブル+フォームで完全に動く状態を作り、その上に
  ドラッグ&ドロップ・インライン編集を重ねる。高度な操作だけが唯一の手段にならないように
  する。
- **認知的ウォークスルー / 5人ルール** → 新画面は見た目レビューだけでなく、実タスクを
  最初から最後まで通しで操作して検証する。一覧・フォームを刷新したら実利用者数名に
  実タスクで試してもらってから展開する。
- 参考: [Laws of UX (Banani)](https://www.banani.co/blog/laws-of-ux-design)、
  [UX Design Institute: Laws of UX](https://www.uxdesigninstitute.com/blog/laws-of-ux/)。
