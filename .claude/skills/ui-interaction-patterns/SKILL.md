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
   参照先にしない — `known-gaps.md` §1)。
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

「ある一覧では編集、別の一覧では詳細」という差異を作らない。行全体をクリック可能にする場合は
`cursor-pointer`と`role`/キーボード到達性を必ず伴わせ、セル内リンクと二重の遷移先を作らない。

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
← 一覧へ戻る

<Object Name>                     [Primary Action] [⋯]

Section
  Attribute
  Attribute

Section
  Related Objects(要約 + 正史画面へのリンク)
```

- 左上に戻る導線、タイトルは対象オブジェクト名
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

対象: Search / Filter / Sort / Page / 必要に応じてTab・詳細パネルの対象ID。

一覧 → 詳細 → 一覧と戻ったとき、検索条件・フィルター・ソート・ページを可能な限り維持する。
`react-router-dom`の`useSearchParams`を使う。

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

> 現状flow-officeにToast基盤は存在しない(`known-gaps.md`)。成功フィードバックは画面遷移・
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
- 状態はURLに載せる(§2.10)

### 2.21 Selection / Bulk Actions

複数選択できる一覧では、**選択があるときだけ**Bulk Actionを表示する。

```
☑ 3件選択中     [無効化] [削除] [その他]
```

通常状態で大量の一括操作ボタンを常時表示しない。フィルター・ページ・検索条件が変わったら
選択を解除する(同じ挙動を一覧間で揃える)。

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
参照する**(既存実装には不統一が残っている — `known-gaps.md`)。

| Pattern | Reference |
| --- | --- |
| List(フィルター + ページング + 一括操作) | `frontend/src/pages/approvals/ApprovalsPage.tsx` |
| List(シンプルなTable一覧) | `frontend/src/pages/expense/ExpenseClaimListPage.tsx` |
| Detail(役割による操作分岐) | `frontend/src/pages/workflow/WorkflowRequestDetailPage.tsx` |
| Create / Edit Form(項目構成のみ参照) | `frontend/src/pages/expense/ExpenseCategoryEditPage.tsx`(保存後の遷移は§2.6未準拠 — `known-gaps.md` §4) |
| Destructive Confirmation | `frontend/src/components/ConfirmActionDialog/` |
| Loading | `frontend/src/components/LoadingState/` |
| Error | `frontend/src/components/ErrorMessage/` |
| Pagination | `frontend/src/components/Pagination/` |
| Form項目 | `frontend/src/components/FormField/` |
| URL状態同期 | `frontend/src/pages/approvals/ApprovalsPage.tsx`(詳細IDのみ同期。Filter/PageのURL化は未実装) |

Empty State・Permission Deniedには現状Reference実装が無い。該当画面に着手した際に、
§2.17 / §2.15に沿った実装を最初に作り、それをReferenceに昇格させる。

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
- [ ] UI文言が§2.7の語彙に沿っている(「登録」を一般操作名に使っていない)
- [ ] 強いPrimary Actionが1画面に1つ
- [ ] 行クリックの挙動が同種の一覧と揃っている
- [ ] 低頻度の行内アクションが`DropdownMenu`にまとまっている
- [ ] 保存が明示的で、失敗時に入力値が保持される
- [ ] 保存後の遷移先が作成時/更新時で不自然に非対称になっていない
- [ ] BackとCancelを区別している
- [ ] 未保存の変更がある場合だけ離脱警告が出る
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
