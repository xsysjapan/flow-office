# バックオフィスタスク詳細の提出系ボタン整理と勤怠参照画面の月別検索・レイアウト統一

ステータス: レビュー中

## 変更要望(原文)

> 勤怠差し戻し後の再提出ボタンがタスク詳細で二つ表示されています。管理画面の参照機能と整合性を合わせてください。また、参照画面については月別で検索・参照できるようにして画面表示をタスク詳細と合わせてください。
> 加えてタスク詳細だとカードの中のカードの中のカードのようになっていて、領域が狭いです。もう少し広く表示されるように見直しをお願いします

ユーザーへの確認により、「タスク詳細」= バックオフィスタスク詳細(`/backoffice-tasks/:id`、
`BackOfficeTaskDetailPage`)であること、「二つ」は同一ラベルの重複ではなく
「見た目・場所が異なる2つの提出系ボタン」であることを確認済み。

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
    タスク自体を完了にする必要がある。この2段階が同じ階層で並んでおり、見た目も配置も異なる
    2つの「確定/提出」的ボタンとして視認されてしまっている。
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

### 論点1: 「状態を変更する」(汎用)と「締める/締めを取り消す」(月次勤怠固有)の関係をどう整理するか

- 選択肢:
  - A. 汎用「状態を変更する」セクションを`task_type === 'attendance_month_confirmation'`では
    非表示にし、`AttendanceMonthConfirmationSection`側の「締める」操作だけを唯一の完了操作とする。
  - B. 両方残すが、表示順序を「月次勤怠の締め処理」→「状態を変更する」に入れ替え、
    「締め処理が完了したら下のステータスを更新してください」という注記を挟むことで、
    2つが競合する選択肢ではなく直列の2ステップであることを明示する。
  - C. 「締める」を押した時点で`backoffice_tasks.status`も自動的に`completed`へ連動させる
    バックエンド改修(Reactor追加)を行い、汎用フォームを事実上不要にする。
- 決定: B。
- 理由: 設計原則6「バックオフィス処理は承認とは別ステータス系列で管理する」により、
  `backoffice_tasks.status`(担当者・期限管理のための処理進行ステータス)と
  `attendance_months.status`(月次勤怠の確定状態)は独立した系列として維持する必要があり、
  AやCのように一方を消したり自動連動させたりすると、担当者が「割り当てのみ完了しタスク自体は
  まだ人が確認中」といった中間状態(`in_review`/`needs_fix`等)を表現できなくなる。
  よって両方の操作自体は残し、「見た目・場所が異なる2つの提出系ボタン」に見えてしまう問題は
  表示順序と説明文で解消する(直列のステップだと分かれば、並んだ2つの独立ボタンには見えなくなる)。
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
   - `task_type === 'attendance_month_confirmation'`の場合、表示順序を
     「担当者を割り当てる(未割当時のみ、既存どおり)」→「月次勤怠の締め処理」
     (`AttendanceMonthConfirmationSection`)→「状態を変更する」の順に変更する
     (現状は「状態を変更する」が先、締め処理が最後)。
   - `AttendanceMonthConfirmationSection`の見出し(`<h3>月次勤怠の締め処理</h3>`)の直下に、
     「締め処理が完了したら、下部の「状態を変更する」でこのタスクのステータスを更新してください。」
     という説明文(`<p className="text-sm text-muted-foreground">`)を追加する。
   - `AttendanceMonthConfirmationSection`内の`AttendanceMonthReferenceTabs`を囲んでいる
     `<div className="flex flex-col gap-4 rounded-md border border-border p-3">`
     (60-62行目)を削除し、`AttendanceMonthReferenceTabs`を直接配置する(前後の
     CSV/Excel出力ボタン・締める/締めを取り消すの`div`はそのまま残す)。
   - 「状態を変更する」セクション自体・その表示条件(全task_typeで表示)は変更しない
     (論点1決定Bにより両方残す)。
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
  - セクション表示順序の変更、説明文の追加、余分な`div.border`ラッパーの削除。
  - 既存の`BackOfficeTaskDetailPage.test.tsx`・`BackOfficeTaskDetailPage.stories.tsx`が
    表示順序に依存している場合は追随して更新する。
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

初版。

## 実装結果

未着手。
