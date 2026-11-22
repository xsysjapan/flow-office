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
7. **確認UIを新規に発明しない**。新規実装では`components/ConfirmActionDialog`を使う
   (`components/ConfirmDialog`も現存するが、2種類併存は解消すべき重複であり新規の
   参照先にしない)。
8. **Loading / Empty / Error / Permission denied / Disabled を別々の状態として設計する**。
   Happy pathだけで実装完了にしない。EmptyはInitialとFilteredを区別する(§2.16)。
9. **Radix UIが持つ標準キーボード操作を独自実装で壊さない**(Tab / Shift+Tab / Enter /
   Space / Escape / 矢印キー)。
10. **同じ種別の一覧では行クリックの挙動を揃える**。原則「行クリック → 対象オブジェクトの
    詳細」(§2.2)。
11. **一覧の絞り込み状態はURLに載せる**(§2.10)。Browser Back・リロード・URL共有で状態が
    壊れないようにする。
12. **すべてのUser Actionに状態変化を返す**(Idle → Submitting → Success / Error)。押した
    のに何も起きないように見える操作を作らない(§2.18)。
13. **戻るリンクは掘り下げで開いた画面にだけ出す**。画面内の進むリンクは「掘り下げ・切り替え・横移動」の
    どれかを判定し、掘り下げだけ遷移元を記録する(§2.26-3)。戻り先は「来た画面(遷移元)→ 階層上の親画面」の
    順に決め、`navigate(-1)`を戻る導線に使わない(§2.26)。

---

## 2. Standard Patterns

### 2.1 List Page

```
PageHeader
 ├─ Title
 ├─ Description
 └─ Primary Action        ← 右上。通常は「新規作成」

ListToolbar
 ├─ Search
 ├─ Filters
 ├─ Sort
 └─ Bulk Actions          ← 選択時のみ表示

DataTable / List
 ├─ Rows(Status表示を含む)
 ├─ Context Actions
 └─ Pagination
```

Primary Actionは右上。1画面に強いPrimary Actionは原則1つ。

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

頻度の低い操作は`DropdownMenu`にまとめる。

```
⋯
 ├ 編集
 ├ 複製
 └ 削除
```

各行に大きなButtonを大量に並べない。ただし**その一覧の主目的そのものである操作**
(承認一覧の「承認」など)は行内に直接置いてよい。

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
[キャンセル] [作成]
```

作成成功後は、作成されたオブジェクトの詳細画面、または元の一覧へ遷移する。どちらにするかは
一覧内で完結する作業か否かで決め、同じドメイン内では揃える。

### 2.6 Edit Page

既存オブジェクトの変更は「編集」、完了操作は「保存」。

```
ユーザーを編集
...
[キャンセル] [保存]
```

- 保存成功後は詳細画面へ戻る(作成時だけ遷移して更新時は留まる、のような非対称にしない)
- 保存失敗時は入力値を保持する
- Validation Errorでは画面を離脱しない

### 2.7 用語ルール

| 意味 | UI文言 |
| --- | --- |
| 新規オブジェクトを作る | 作成 |
| 既存オブジェクトを変更する | 編集 |
| 変更を確定する | 保存 |
| 既存オブジェクトを関連付ける | 追加 |
| 関連を外す | 削除 / 解除(意味で使い分ける) |
| 変更せず戻る | キャンセル |

「登録」は原則使わない。例外は法令・業務上「登録」が正式名称のもの(打刻端末登録など)。

### 2.8 Save Pattern

- **Form**: 明示的保存。
- **単純なToggle**: 即時反映を許可(例: `通知を有効にする [ON]`)。
- **複雑な設定**: 複数変更をまとめて保存。
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
`react-router-dom`の`useSearchParams`を使う。

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
- 社員などIDで選ぶ入力は、IDだけ渡されても名前を表示できること(`UserPicker`・`MultiUserPicker`参照。URL復元・リロード・
  親からの値変更でも空欄にしない)。

### 2.11 Dialog / Sheet / Page の使い分け

| 用途 | 使うもの |
| --- | --- |
| 短時間で完了する補助操作(削除確認・簡単な状態変更・小規模な選択) | Dialog |
| コンテキストを維持したまま少し多くの情報を扱う(Filter、モバイルナビ、補助情報) | Sheet |
| 考慮が必要な作業(ユーザー作成、働き方編集、カレンダー編集、権限設定) | Page |

入力項目が増え始めたDialogをそのまま巨大化させない。項目が増えたらPageに移す。

### 2.12 Delete / Destructive Action

危険度でInteractionを変える。

- **軽微・容易に戻せる**: 即実行 + Undoを検討
- **重要だが戻せる**: 確認不要でもよい
- **戻せない**: Confirmation Dialog
- **重大**: 対象と結果を明示したConfirmation

```
田中 太郎を削除しますか?

この操作は元に戻せません。

[キャンセル] [削除]
```

確認させるのは「操作」ではなく「結果」。

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

### 2.17 Empty State

**Initial Empty**(まだデータが無い)と**Filtered Empty**(条件に一致しない)を区別する。

```
Initial:
グループがまだありません。
グループを作成すると、社員を組織単位で管理できます。
[グループを作成]

Filtered:
条件に一致するグループがありません。
[検索条件をクリア]
```

両方を単に「データがありません」にしない。

### 2.18 Error / Feedback

Errorでは「何が失敗したか」と「次に何ができるか」を示す。内部ExceptionやAPIの生メッセージを
そのまま表示しない。

```
ユーザー一覧を取得できませんでした。
[再試行]
```

状態遷移は最低限 `Idle → Submitting → Success / Error` を考慮する。表示は
`components/ErrorMessage`(Field Errorも展開される)を使う。

> 現状flow-officeにToast基盤は存在しない。成功フィードバックは画面遷移・
> 一覧の更新・インラインメッセージで表現する。Toastを導入する場合も、**ユーザーが対応する
> 必要のあるErrorをToastだけにしない**(消えると気づけないため)。

### 2.19 Form Interaction

- 単一カラム、ラベルは項目の上(`components/FormField`)
- PlaceholderをLabel代わりにしない
- 関連項目はSectionでGroupingする(大量のフィールドを単純に縦に並べない)
- 不要な必須項目を作らない
- Errorは対象の入力項目の近くに表示する
- Submit Error(全体)とField Error(項目)を区別する

### 2.20 Search / Filter / Sort / Pagination

```
[検索________________] [フィルター▼]

Table

Pagination
```

- 頻繁に使うFilterのみ直接表示し、低頻度Filterは追加Filterにまとめる
- Filterが有効なときはそれが視覚的に分かるようにする
- ページングは`components/Pagination`を使う
- 一覧を作る・変えるときは、件数が増え得るかを必ず検討し、増え得るならAPI側でページングし(`paginate`)、画面は`components/Pagination`を使う。
  全件を返す・表示する場合は、件数が増えない理由を変更セットに書く
- 状態はURLに載せる(§2.10)

### 2.21 Selection / Bulk Actions

複数選択できる一覧では、**選択があるときだけ**Bulk Actionを表示する。

```
☑ 3件選択中     [無効化] [削除] [その他]
```

通常状態で大量の一括操作ボタンを常時表示しない。フィルター・ページ・検索条件が変わったら
選択を解除する(同じ挙動を一覧間で揃える)。

**モバイル幅(`sm`未満)でのBulk Action Barの構成**: PC幅では選択件数・アクションボタン群・
キャンセルを1行に横並びできても、モバイル幅ではそのまま並べるとボタン群がラップ/はみ出して
崩れる。次の2行構成に分ける。

```
[3件選択中]                [キャンセル]   ← 1行目: 件数(左)とキャンセル(右)をjustify-between
[申請A] [申請B] [申請C] →              ← 2行目: アクション列。横スクロール(overflow-x-auto)
```

- 1行目: 選択件数を左、キャンセル(選択解除)を右に固定して1行で収める。
- 2行目: アクションボタンは折り返させず、`overflow-x-auto`の横スクロール列にする(ボタン数が
  増えても縦に伸びず、Fittsの法則的にも指でスワイプしやすい)。ボタン自体はラベル付きのままで
  よく、アイコンのみへの置き換えは必須ではない(現状の実装例では未使用)。
- PC幅(`sm`以上)では既存通り1行に戻す。件数→アクション列→キャンセルの順を保つ
  (キャンセルは`order`ユーティリティで末尾に固定してよい)。
- 採用理由: Material Designのbulk-action bar(選択時のみ表示、アクションを絞りoverflowへ逃がす)、
  iOS HIGのボトムToolbar(頻出操作をタップしやすい位置にまとめる)、主要SaaS(Gmail等)の
  モバイル多重選択でみられる横スクロール可能なアクション列、の3つに共通する要点を踏襲した。
- **配置場所の注意**: `Card`の`title`と`actions`は同じ行で`flex-wrap`する実装になっており、
  Bulk Action Barをそのまま`actions`スロットに渡すと、モバイル幅では「タイトルとBulk Action
  Barの横幅がぶつかり合い、タイトル文字列(スペースを含まない日本語見出し)側が
  `min-width:auto`により先に押し潰されて文字の途中で改行される」崩れが起きる
  (`flex-basis`や`w-full`をBulk Action Bar側に付けても、`actions`側のコンテナ自体が
  auto幅で確定していないため効果がない)。そのため、モバイル幅(`sm`未満)ではBulk Action
  Barを`actions`スロットに渡さず、`Card`の子要素(コンテンツ側)の先頭に独立したブロックとして
  置き、`hidden sm:block` / `sm:hidden`で表示箇所をPC/モバイルで出し分ける
  (`Card`・`CardTitle`・`CardAction`自体は他画面へ影響するため変更しない)。
- 実装例: `frontend/src/components/AttendanceSelectionActionBar/`(週次・月次勤怠の選択モード
  一括操作バー)と、その呼び出し側`frontend/src/pages/attendance/WeekAttendancePage.tsx`・
  `AttendanceMonthDetailPage.tsx`(`actions`スロットには`hidden sm:block`で包んで渡し、
  `Card`の子要素側先頭に`sm:hidden`で同じコンポーネントをもう一度描画している)。**今後、
  同様の「複数選択→一括操作」UIをモバイル対応込みで新規実装する際はこのパターンに従う**
  (画面ごとに独自のラップ/縮小処理を発明しない)。

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
| List(フィルター + ページング + 一括操作 + URL状態) | `frontend/src/pages/approvals/ApprovalsPage.tsx` |
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
