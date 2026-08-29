# バックオフィスタスク詳細の提出系ボタン整理と勤怠参照画面の月別検索・レイアウト統一

ステータス: レビュー中

## 変更要望(原文)

> 勤怠差し戻し後の再提出ボタンがタスク詳細で二つ表示されています。管理画面の参照機能と整合性を合わせてください。また、参照画面については月別で検索・参照できるようにして画面表示をタスク詳細と合わせてください。
> 加えてタスク詳細だとカードの中のカードの中のカードのようになっていて、領域が狭いです。もう少し広く表示されるように見直しをお願いします

ユーザーへの確認により、「タスク詳細」= バックオフィスタスク詳細(`/backoffice-tasks/:id`、
`BackOfficeTaskDetailPage`)であること、「二つ」は
「管理者操作という枠の中にあるもの」(`MonthlyReferenceView`の`Card title="管理者操作"`内の
`ReopenMonthDialog`)と「バックオフィスタスクのExcel出力と同じところにあるもの」
(`AttendanceMonthConfirmationSection`がCSV/Excel出力ボタンの下に直接出している
`ReopenMonthDialog`)を指すことを特定済み。いずれも「締めを取り消す」という**同一操作**の
`ReopenMonthDialog`コンポーネントであり、月が締め済み(`status === 'closed'`)かつ
`attendance.month_reopen`権限を持つ場合に、同一タスク詳細ページ内へ二重に描画されていた
(見た目が異なるのは、片方がCardの中、片方が素のdivの中に置かれているため)。

## 背景・目的

`BackOfficeTaskDetailPage`(月次勤怠確定タスク)には、汎用タスクステータス変更フォームと
月次勤怠固有の締め処理が並んで表示されており、担当者にとって「どちらを押せば処理が完了するのか」
が分かりにくい。さらに月次勤怠の実績表示が3〜4階層のカードのネストになっており表示領域が狭い。
これらを、既存の管理画面の勤怠参照機能(`AttendanceReferencePage`、月次/週次/日次の閲覧専用画面)
と表示・操作感を揃えつつ整理する。合わせて`AttendanceReferencePage`側にも年月を直接指定して
検索できるUIを追加する。

## 現状(As-Is)

- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`
  - 125-226行目 `BackOfficeTaskDetailPage`: 全タスク種別共通の「状態を変更する」セクション
    (196-218行目、`STATUS_OPTIONS` + `NativeSelect` + 「更新する」ボタン)を持つ。これは
    `backoffice_tasks.status` を変更する汎用フォーム。
  - 220-222行目: `task_type === 'attendance_month_confirmation'` の場合のみ末尾に
    `AttendanceMonthConfirmationSection`(39-108行目)を追加表示。こちらは
    `attendance_months.status` を変更する月次勤怠固有の操作で、「締める」
    (`ConfirmActionDialog`、89-102行目)または締め済みなら「締めを取り消す」
    (`ReopenMonthDialog`)を持つ。
  - この2系統は設計原則6「バックオフィス処理は承認とは別ステータス系列で管理する」により
    データモデル上は正しく分離されているが、バックエンド側にも両者を連動させるReactor等は無く
    (`backend/app/Domain/Attendance/Reactors/CreateBackOfficeTaskOnAttendanceMonthApprovalReactor.php`
    がタスク生成のみを行う)、担当者は「締める」で月次勤怠を確定した後、別途「状態を変更する」で
    タスク自体を完了にする必要がある(この2段階自体は仕様として妥当。論点1参照)。
  - **実際の重複ボタンの原因(ユーザー再確認により特定)**:
    `AttendanceMonthConfirmationSection`(81-87行目)は、`month.status === 'closed'`かつ
    `canReopenMonth`の場合、CSV/Excel出力ボタンと同じ`div`の並びに直接
    `<ReopenMonthDialog monthId={month.id} yearMonth={month.year_month} />`(「締めを取り消す」)
    を描画している。一方、同じセクション内で呼んでいる`AttendanceMonthReferenceTabs`
    (61行目)→`MonthlyReferenceView`は、`AttendanceReferencePage.tsx`の239-243行目で
    `canReopenMonth && month?.status === 'closed'`のときに`Card title="管理者操作"`の中で
    **同じ`ReopenMonthDialog`を同じpropsで**描画する。したがって月が締め済み・
    かつ担当者が`attendance.month_reopen`権限を持つ場合、
    「管理者操作」カードの中の「締めを取り消す」ボタンと、Excel出力ボタンの下にある
    「締めを取り消す」ボタンが、同一タスク詳細ページ内に**同一操作として二重表示**される。
    見た目(Card内かdiv内か)と場所(タブ群の中か下部の並びか)が異なるため、ユーザーには
    「見た目・場所が異なる2つの提出系ボタン」に見えていた。
  - AttendanceMonthConfirmationSection内(60-62行目)は
    `<div className="flex flex-col gap-4 rounded-md border border-border p-3">` で
    `AttendanceMonthReferenceTabs` を囲んでおり、
    `Card(task.title)` → `div(border)` → `AttendanceMonthReferenceTabs` → `MonthlyReferenceView`
    → `Card(月次勤怠)`/`Card(日別の内訳)`/`Card(管理者操作)` という4階層のネストになっている。
- `frontend/src/pages/attendance/AttendanceReferencePage.tsx`
  - `MonthlyReferenceView`(145-246行目)は`restrictToYearMonth`が未指定の場合、
    `navigation`(176-190行目)に前月/今月/次月ボタンのみを表示し、年月を直接指定するUIは無い。
  - 570-618行目 `AttendanceReferencePage`(`/admin/attendance`): 対象社員(`UserPicker`)+
    月次/週次/日次タブのみで、月の絞り込みも同様に無い。
  - 515-561行目 `AttendanceMonthReferenceTabs`(`BackOfficeTaskDetailPage`・
    `WorkflowRequestSubjectDetail`から共通利用)も同じ`MonthlyReferenceView`を呼ぶ。
- `frontend/src/components/YearMonthPicker/YearMonthPicker.tsx`
  - 既存の年月直接指定コンポーネント。`frontend/src/pages/attendance/AttendanceExportPage.tsx`
    120行目で「対象年月を追加する」用途に使われている。今回の年月検索にも再利用できる。
- `frontend/src/components/WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail.tsx`
  - `AttendanceMonthSubjectView`(63-131行目)の107行目でも同様の
    `<div className="flex flex-col gap-6 rounded-md border border-border p-3">` によるネストがある
    (`WorkflowRequestDetailPage`側)。ユーザー要望は「タスク詳細」に限定されているため今回は
    対象外とするが、同一パターンのため対象外の理由をここに明記する(下記「対象外」参照)。

## 仕様検討

### 論点1: 「管理者操作」カード内と、Excel出力ボタン下の、2つの`ReopenMonthDialog`(締めを取り消す)をどう一本化するか

- 選択肢:
  - A. `AttendanceMonthConfirmationSection`(81-87行目)の`ReopenMonthDialog`を削除し、
    「締め処理済みのため修正できません。」という説明文だけを残す。実際の「締めを取り消す」
    操作は`AttendanceMonthReferenceTabs`→`MonthlyReferenceView`が出す「管理者操作」カードの
    ものだけに一本化する。
  - B. 逆に`MonthlyReferenceView`側(`AttendanceReferencePage.tsx`239-243行目)の
    「管理者操作」カードを、`restrictToYearMonth`が指定されている(＝
    `AttendanceMonthReferenceTabs`経由で呼ばれている)場合は表示しないようにし、
    `AttendanceMonthConfirmationSection`側の`ReopenMonthDialog`だけを残す。
  - C. どちらのボタンも残すが、片方を無効化(disabled)表示にする。
- 決定: A。
- 理由: 「管理者操作」カードは`AttendanceReferencePage`(管理画面の参照機能)が単独ページとして
  開かれたとき(`/admin/attendance`)にも表示される、月次勤怠の閲覧・管理者操作の**唯一の場所**
  として設計されている(`MonthlyReferenceView`のdocコメント「参照専用の月次勤怠カードとは分離し、
  状態を変更する管理者操作であることを明示する」)。`AttendanceMonthConfirmationSection`側の
  重複表示を消してこちらに一本化することが、まさにユーザー要望の「管理画面の参照機能と整合性を
  合わせる」に直結する。Bのように`AttendanceMonthReferenceTabs`経由の呼び出し元で条件分岐を
  増やすと、`WorkflowRequestSubjectDetail`(`AttendanceMonthSubjectView`)側の呼び出しにも
  影響する条件付きpropsが必要になり複雑化する。Cは重複した見た目上のボタンが2つ残る点が
  そもそもの問題を解消しない。
  なお、`AttendanceMonthConfirmationSection`が締め済みでない場合に出している「締める」
  (`ConfirmActionDialog`、89-102行目)は、`MonthlyReferenceView`側には対応するボタンが無い
  (`MonthlyReferenceView`は閲覧専用+締め済み時の「取り消し」のみを持つ)ため、これは重複ではなく
  そのまま残す。
- 未確定・要確認事項: なし(確定)。

### 論点2: `AttendanceReferencePage`に月別検索UIをどう追加するか

- 選択肢:
  - A. 既存の`YearMonthPicker`(`frontend/src/components/YearMonthPicker/YearMonthPicker.tsx`)を
    `MonthlyReferenceView`の`navigation`(前月/今月/次月ボタンの並び)に追加し、直接年月を選ぶと
    その年月へジャンプする。
  - B. `AttendanceReferencePage`本体(社員選択の並び)に年月フィルターを追加し、
    `MonthlyReferenceView`へ`initialYearMonth`として渡す(週次・日次タブでは使わない)。
  - C. 新規に年月専用の検索ボックス(テキスト入力+正規表現バリデーション)を自作する。
- 決定: A(`MonthlyReferenceView`の`navigation`内、前月/次月ボタンの並びに`YearMonthPicker`を追加)。
- 理由: `restrictToYearMonth`が指定されていない(=単独ページとして開かれている)場合のみ
  ナビゲーションが表示される既存の分岐(176行目)にそのまま追加できるため、
  `BackOfficeTaskDetailPage`・`WorkflowRequestDetailPage`側(`restrictToYearMonth`指定あり)には
  影響しない。既存コンポーネント(`YearMonthPicker`)を再利用するため実装・見た目ともに
  `AttendanceExportPage`と一貫性が保てる(Cのような自作は不要)。Bは社員選択と年月選択が
  同じFormFieldの並びに来て「探して見る」操作感になり、月次ビュー内の前月/次月ナビゲーションと
  UIが二重になるため採用しない。
- 未確定・要確認事項: なし(確定)。`YearMonthPicker`選択時の挙動は「選んだ年月へ`setYearMonth`で
  移動する」のみ(既存の前月/次月ボタンと同じ状態を更新するだけで、URLへの反映は行わない=
  既存の月次ビュー内ナビゲーションの扱いを変えない)。

### 論点3: ネストしたカード構造(カードの中のカードの中のカード)をどう解消するか

- 選択肢:
  - A. `AttendanceMonthConfirmationSection`内で`AttendanceMonthReferenceTabs`を囲んでいる
    `<div className="... rounded-md border border-border p-3">`(60-62行目)を削除し、
    タブ切り替えボタン列と`MonthlyReferenceView`等が生成する`Card`群を、外側の
    `Card(task.title)`の直下にそのまま配置する。
  - B. 外側の`Card(task.title)`自体を廃止し、タスク詳細ページ全体をCard無しの素の`div`構成に
    作り直す。
  - C. `MonthlyReferenceView`/`WeeklyReferenceView`/`DailyReferenceView`が内部で使っている
    `Card`をやめ、`div`+見出しだけの軽量な表示に変更する(`AttendanceReferencePage`単独利用時も
    含めて変更されるため影響範囲が広い)。
- 決定: A。加えて、`BackOfficeTaskDetailPage`のトップレベルの`Card(task.title)`は残しつつ、
  その中の他セクション(担当者割り当て・状態変更・締め処理)は今まで通り`border-t`区切りの
  素の`div`のまま、`AttendanceMonthConfirmationSection`内の余分な枠(`div.border`)だけを取り除く。
- 理由: 問題の本質は「`Card`そのものが悪い」のではなく、`Card`の中に見た目上意味のない
  `div.border`のラッパーがもう一段挟まっていること(すでにCardが枠線を持つのに、その内側で
  さらに枠線付きdivで囲み、さらにその中に月次勤怠用Cardが並ぶ、という三重の枠線)。
  Aはその不要な一段だけを取り除くため影響が最小で、`AttendanceReferencePage`単独利用時の
  見た目(C)や他ページの`Card`運用(B)には影響しない。取り除いた後は
  `Card(task.title)` → `Card(月次勤怠)`/`Card(日別の内訳)` の2階層になり、
  `AttendanceReferencePage`単独ページでの見た目(`Card`が並ぶだけ)に近づく=論点1・2で
  参照している「管理画面の参照機能との整合性」の一部でもある。
  横幅についても、余分な`div.border.p-3`のpadding分(1セット)が無くなるため実効表示幅が広がる。
- 未確定・要確認事項: なし(確定)。

## 仕様確定事項(まとめ)

1. `BackOfficeTaskDetailPage.tsx`(`frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`):
   - `AttendanceMonthConfirmationSection`の`month.status === 'closed'`分岐(81-87行目)から
     `{canReopenMonth && <ReopenMonthDialog monthId={month.id} yearMonth={month.year_month} />}`
     を削除し、「締め処理済みのため修正できません。月次勤怠の内容は引き続き確認できます。」の
     説明文だけを残す。実際の「締めを取り消す」操作は、直上の`AttendanceMonthReferenceTabs`が
     表示する「管理者操作」カード(`MonthlyReferenceView`側)のものに一本化する。
     ※このとき`canReopenMonth`変数はこの箇所では未使用になるが、`AttendanceMonthReferenceTabs`
     配下の`MonthlyReferenceView`が同じ権限チェックを内部で独自に行うため、
     `AttendanceMonthConfirmationSection`側の`canReopenMonth`宣言自体は削除してよい
     (未使用importにならないよう`useAuth`の使用箇所も合わせて確認する)。
   - `AttendanceMonthConfirmationSection`内の`AttendanceMonthReferenceTabs`を囲んでいる
     `<div className="flex flex-col gap-4 rounded-md border border-border p-3">`
     (60-62行目)を削除し、`AttendanceMonthReferenceTabs`を直接配置する(前後の
     CSV/Excel出力ボタン・締める/締めを取り消すの`div`はそのまま残す)。
   - 「状態を変更する」セクション・「締める」(`ConfirmActionDialog`、締め済みでない場合)は
     重複ではないため変更しない。
2. `AttendanceReferencePage.tsx`(`frontend/src/pages/attendance/AttendanceReferencePage.tsx`):
   - `MonthlyReferenceView`の`navigation`(`restrictToYearMonth === undefined`の分岐、
     176-190行目)内、前月ボタンと今月ボタンの間に`YearMonthPicker`
     (`frontend/src/components/YearMonthPicker/YearMonthPicker.tsx`)を追加する。
   - `YearMonthPicker`の`value`は現在の`yearMonth`、`onChange`は選ばれた年月を
     `setYearMonth`に渡す(前月/次月ボタンと同じstate `yearMonth`を更新するだけで、URLには
     反映しない=既存の前月/次月ボタンの扱いと揃える)。
   - `AttendanceReferencePage`本体(社員選択欄)の変更は無し(論点2決定の通り、年月検索は
     `MonthlyReferenceView`側に閉じる)。
3. カードのネスト解消は上記1で実施済み(`AttendanceMonthConfirmationSection`内の
   余分な`div.border`の削除)。`BackOfficeTaskDetailPage`のトップレベル`Card`・
   `MonthlyReferenceView`/`WeeklyReferenceView`/`DailyReferenceView`側の`Card`構成自体は変更しない。
4. `WorkflowRequestDetailPage`・`WorkflowRequestSubjectDetail.tsx`(`AttendanceMonthSubjectView`)は
   今回のユーザー要望が「タスク詳細」(バックオフィスタスク詳細)に限定されているため変更しない
   (「対象外」参照)。

## 対象外

- `WorkflowRequestDetailPage.tsx`・`WorkflowRequestSubjectDetail.tsx`
  (`AttendanceMonthSubjectView`)側のネスト構造・提出ボタン配置の変更。同一パターンの
  ネストが存在するが、ユーザー要望は「タスク詳細」(`BackOfficeTaskDetailPage`)に
  限定されているため今回は触らない。将来的に同様の指摘があれば別の変更セットで対応する。
- `backoffice_tasks.status`と`attendance_months.status`を連動させるバックエンド改修
  (論点1選択肢C)。設計原則6を維持するため見送る。
- `AttendanceReferencePage`本体(社員選択欄)への年月フィルター追加(論点2選択肢B)。
- 週次・日次ビュー(`WeeklyReferenceView`・`DailyReferenceView`)への年月直接指定UIの追加
  (今回は月次ビューのみ)。
- `AttendanceMonthReferenceTabs`(`WorkflowRequestSubjectDetail`からも使われる共通コンポーネント)
  自体のAPI変更。今回は`AttendanceReferencePage.tsx`内の`MonthlyReferenceView`の
  `navigation`部分のみを変更するため、`AttendanceMonthReferenceTabs`経由(`restrictToYearMonth`
  指定あり)の呼び出しには影響しない。

## ドキュメントへの影響

変更なし。今回の変更はUIレイアウト・操作導線の整理であり、ユースケースの手順・イベント名・
状態遷移(`docs/07-usecases-attendance.md`のUC-A011、`docs/11-usecases-backoffice.md`の
UC-B007)自体に変更はない。

## モック・アセット

なし。

## 実装対象

- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`
  - `AttendanceMonthConfirmationSection`の締め済み分岐から重複する`ReopenMonthDialog`を削除、
    余分な`div.border`ラッパーの削除。
  - 既存の`BackOfficeTaskDetailPage.test.tsx`・`BackOfficeTaskDetailPage.stories.tsx`が
    「締めを取り消す」ボタンの個数・位置に依存している場合は追随して更新する。
- `frontend/src/pages/attendance/AttendanceReferencePage.tsx`
  - `MonthlyReferenceView`の`navigation`に`YearMonthPicker`を追加。
  - 既存の`AttendanceReferencePage.test.tsx`に月選択の動作確認テストを追加する。

## 検証方法

```
cd frontend
npm run test -- BackOfficeTaskDetailPage AttendanceReferencePage
npm run build   # 型チェック含む
```
併せて、Storybookで`BackOfficeTaskDetailPage`(締め処理あり/なしの両状態)・
`AttendanceReferencePage`(月選択)の表示を目視確認する。

## レビュー履歴

- 初版。
- ユーザーから「管理者操作という枠の中にあるものと、バックオフィスタスクのExcel出力と同じ
  ところにあるものです」との追加情報を得て、重複ボタンの実体を
  `AttendanceMonthConfirmationSection`と`MonthlyReferenceView`(管理者操作カード)が
  同じ`ReopenMonthDialog`を二重描画していたことと特定。論点1・現状(As-Is)・
  仕様確定事項・実装対象を、「表示順序の入れ替え+説明文追加」案から
  「重複描画の削除による一本化」案に修正。

## 実装結果

未着手。
