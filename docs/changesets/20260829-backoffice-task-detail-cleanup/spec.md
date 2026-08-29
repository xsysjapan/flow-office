# 月次勤怠表示コンポーネントの統一(申請詳細・バックオフィスタスク詳細・勤怠参照画面)

ステータス: レビュー中

## 変更要望(原文)

> 勤怠差し戻し後の再提出ボタンがタスク詳細で二つ表示されています。管理画面の参照機能と整合性を合わせてください。また、参照画面については月別で検索・参照できるようにして画面表示をタスク詳細と合わせてください。
> 加えてタスク詳細だとカードの中のカードの中のカードのようになっていて、領域が狭いです。もう少し広く表示されるように見直しをお願いします

> 月次勤怠の表示にあたり、申請詳細、バックオフィスタスク詳細、管理画面の勤怠参照で表示するコンポーネントを合わせてください。当該月内で週次、日次を表示し、Excel出力、CSV出力、状態変更ができるのが想定の挙動です。

ユーザーへの確認・追加要望により、以下が確定した:
- 「タスク詳細」= バックオフィスタスク詳細(`/backoffice-tasks/:id`、`BackOfficeTaskDetailPage`)。
- 「二つのボタン」の実体は、`AttendanceMonthConfirmationSection`が独自に描画する
  `ReopenMonthDialog`(締めを取り消す)と、同セクションが呼ぶ`AttendanceMonthReferenceTabs`
  (`MonthlyReferenceView`の「管理者操作」カード)が描画する**同一の**`ReopenMonthDialog`の二重描画。
- 対応範囲を「タスク詳細のバグ修正」から、**申請詳細(`WorkflowRequestDetailPage`)・
  バックオフィスタスク詳細(`BackOfficeTaskDetailPage`)・管理画面の勤怠参照
  (`AttendanceReferencePage`)の3画面で、月次勤怠を表示する部分を同一コンポーネントに統一**する
  ことに拡大する。統一後の月次表示は、当該月内で週次・日次の表示切り替え、Excel出力、CSV出力、
  状態変更(締める/締めを取り消す)ができることを想定挙動とする。

## 背景・目的

現在、月次勤怠を「参照」する実装が3画面それぞれで異なる形になっている
(`AttendanceMonthReferenceTabs`利用/独自タブ実装/CSV・Excel出力や状態変更ボタンの有無が画面ごとに
バラバラ)。これにより表示の不整合(ネストの深さ・操作の重複)が生まれていた。3画面を1つの
共通コンポーネントに統一することで、見た目・操作導線を揃え、今後の機能追加(週次/日次のUI変更等)
も1箇所の修正で3画面に反映されるようにする。

## 現状(As-Is)

月次勤怠の「月次/週次/日次タブ切り替え」表示は、現在3箇所で実装が分かれている。

1. **`frontend/src/pages/attendance/AttendanceReferencePage.tsx`**(管理画面の勤怠参照、
   `/admin/attendance`)
   - `MonthlyReferenceView`(145-246行目)/`WeeklyReferenceView`(248-335行目)/
     `DailyReferenceView`(366-512行目): 月次/週次/日次それぞれの表示本体。`Card(月次勤怠)`+
     `Card(日別の内訳)`、`canReopenMonth && status==='closed'`のときのみ`Card(管理者操作)`
     (`ReopenMonthDialog`)を表示。CSV/Excel出力ボタン・「締める」ボタンは無い。
   - `AttendanceMonthReferenceTabs`(515-561行目): 月次/週次/日次タブの切り替えUIを持つ
     ラッパー。`restrictToYearMonth`を指定して呼ぶことで、対象月固定の閲覧に使う
     (`BackOfficeTaskDetailPage`・`WorkflowRequestSubjectDetail`から共通利用)。
   - `AttendanceReferencePage`本体(570-618行目): 対象社員(`UserPicker`)+タブ切り替えのみ。
     年月を直接指定するUIは無い(前月/次月ボタンのみ、`MonthlyReferenceView`の`navigation`)。
2. **`frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`**(バックオフィスタスク詳細)
   - `AttendanceMonthConfirmationSection`(39-108行目): 上記`AttendanceMonthReferenceTabs`を
     `<div className="rounded-md border border-border p-3">`で囲んで呼び出し(60-62行目)、
     その外側に自前でCSV出力・Excel出力ボタン(63-80行目)と、「締める」
     (`ConfirmActionDialog`、締め済みでない場合)/「締めを取り消す」
     (`ReopenMonthDialog`、締め済みの場合、81-87行目)を並べている。
   - 結果、`AttendanceMonthReferenceTabs`側の「管理者操作」カード(締め済み時)と、この
     `ReopenMonthDialog`が**同一操作を二重に**表示する(今回のバグの直接原因)。
   - `Card(task.title)` → `div(border)` → `AttendanceMonthReferenceTabs` →
     `Card(月次勤怠)`/`Card(日別の内訳)`/`Card(管理者操作)` という4階層のネストにもなっている。
3. **`frontend/src/components/WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail.tsx`**
   (`AttendanceMonthSubjectView`、申請詳細`WorkflowRequestDetailPage`から利用)
   - 63-131行目: `AttendanceMonthReferenceTabs`を使わず、月次/週次/日次タブの切り替え・
     `dateRange`/`weekRange`の算出を**独自に再実装**している(`AttendanceMonthReferenceTabs`と
     ほぼ同一のロジックの重複)。CSV/Excel出力・状態変更(締める/締めを取り消す)ボタンは無い
     (閲覧専用として設計されていた)。
   - `MonthlyReferenceView`等を直接呼んでおり、107行目の
     `<div className="rounded-md border border-border p-3">`ラッパーにより
     `Card(request.title)` → `WorkflowRequestSubjectDetail` → `div(border)` →
     `Card(月次勤怠)`/`Card(日別の内訳)` というネストになっている。

つまり3画面とも「`MonthlyReferenceView`/`WeeklyReferenceView`/`DailyReferenceView`」という
表示本体は共有しているが、それを束ねるタブ切り替え・CSV/Excel出力・状態変更ボタンの持ち方が
3画面バラバラ(共通コンポーネント利用/独自実装/機能の有無)になっている。

### 権限の前提

- `attendance.export`(勤怠出力): CSV/Excel出力APIの認可(`backend/routes/api.php`218-222行目、
  `permission:attendance.export,any`)。スコープは`global`/`group`のみで`self`を含まない
  (`AccessControlCatalog.php`)。
- `attendance.month_reopen`(月次勤怠締め取消): 締め取消APIの認可(同346行目、
  `permission:attendance.month_reopen,any`)。スコープは`global`のみ。
- `backoffice_task.execute`(バックオフィスタスク処理): バックオフィスタスクの担当・処理を行える
  ロール(HR_STAFF・BACKOFFICE_STAFF)に付与されている。
- 月を締める操作(`closeMonth`)のAPI認可は`permission:attendance.update,any`
  (同342-343行目)だが、`attendance.update`は`self`スコープも持つ権限であり
  (`AccessControlCatalog.php`58行目)、フロントエンドの`effective_permissions`は
  スコープ情報を持たない(コード名の配列のみ、`backend/app/Domain/AccessControl/Services/
  EffectiveAccessResolver.php`109行目`permissions()`)。したがって
  `effective_permissions.includes('attendance.update')`だけでは「自分の勤怠を編集できる
  (self)」と「他人の月次勤怠を締められる(any)」を区別できない。

## 仕様検討

### 論点1: 3画面共通の「月次勤怠タブ」コンポーネントをどう構成するか

- 選択肢:
  - A. `AttendanceMonthReferenceTabs`(`AttendanceReferencePage.tsx`)を拡張し、CSV/Excel出力・
    状態変更(締める/締めを取り消す)を**コンポーネント自身に組み込み**、権限で表示を制御する。
    3画面すべてが同じ`AttendanceMonthReferenceTabs`を呼ぶだけにする。
  - B. CSV/Excel出力・状態変更は呼び出し側(3画面それぞれ)に残したまま、`AttendanceMonthReferenceTabs`
    に「これらのボタン群をどこに差し込むか」を`slot`的なpropsで渡せるようにする。
  - C. 現状維持(3画面それぞれが個別に実装する)。
- 決定: A。
- 理由: ユーザー要望が「表示するコンポーネントを合わせる」ことそのものであり、B案のような
  差し込み式propsは結局呼び出し側ごとに異なるJSXを書くことになり「コンポーネントを合わせる」
  ことにならない。Cは今回の要望に反する。Aにより、`BackOfficeTaskDetailPage`の
  `AttendanceMonthConfirmationSection`が独自に持っていたCSV/Excel出力・締める/締めを取り消す
  ボタン(と、それによる二重表示バグ)は丸ごと削除でき、`WorkflowRequestSubjectDetail`の
  独自タブ実装(月次/週次/日次の切り替え・dateRange計算の再実装)も丸ごと削除できる。
- 未確定・要確認事項: なし(確定)。

### 論点2: CSV/Excel出力・状態変更(締める/締めを取り消す)ボタンの表示条件をどう揃えるか

`AttendanceMonthReferenceTabs`に組み込んだ後、申請詳細(申請者本人・都度指定された承認者が
閲覧する画面)でも同じコンポーネントが使われるため、権限のない一般社員が管理者操作ボタンを
見てしまわないようにする必要がある。

- 選択肢:
  - A. CSV/Excel出力は`attendance.export`権限、状態変更(締める/締めを取り消す)は
    `backoffice_task.execute`権限を持つ場合のみ表示する(3画面共通)。
  - B. 状態変更は`attendance.month_reopen`権限(締めを取り消すのみ元々ガードされていた権限)を
    「締める」にも流用し、`backoffice_task.execute`は使わない。
  - C. 画面ごとに表示条件を変える(例: `BackOfficeTaskDetailPage`だけ無条件表示、他の2画面は
    権限チェック)。
- 決定: A。
- 理由: 「背景・目的」の権限の前提のとおり、`attendance.month_reopen`は`global`スコープのみで
  安全だが、そもそも締め取消専用の権限であり「締める」側の権限として意味が合わない(B案は
  権限名とドキュメント上の意味がずれる)。`backoffice_task.execute`はHR_STAFF・
  BACKOFFICE_STAFFロールに付与されている「バックオフィスタスクを処理できるか」を表す権限で、
  月次勤怠の締め/締め取消という管理者操作の実施者として意味が合致し、かつ`self`スコープを
  持たない(一般社員には付与されない)ため、3画面共通でこれを使っても一般社員に誤って
  管理者操作ボタンが見えることはない。Cは「コンポーネントを合わせる」という論点1の決定に反する
  ため採用しない。
  なお、`closeMonth`のAPI自体は`attendance.update,any`でガードされているため、
  `backoffice_task.execute`を持つが`attendance.update`を`any`スコープで持たない
  ロールが万一存在する場合はボタン押下後にAPI側で403になる。現在の`AccessControlCatalog`上の
  ロール構成(HR_STAFF・BACKOFFICE_STAFFは`attendance.manage`または`approval.execute`等と併せて
  月次勤怠操作系権限を持つ想定)ではこの不整合は生じないため、今回はバックエンド権限定義の変更は
  行わない(対象外参照)。
- 未確定・要確認事項: なし(確定)。

### 論点3: `WorkflowRequestSubjectDetail`(申請詳細)の独自タブ実装をどう統一するか

- 選択肢:
  - A. `AttendanceMonthSubjectView`(63-131行目)の月次/週次/日次タブ切り替え・
    `dateRange`/`weekRange`算出ロジックを削除し、`AttendanceMonthReferenceTabs`
    (`userId={subject.user_id}`, `yearMonth={subject.year_month}`)の呼び出しに置き換える。
    差戻し理由の表示(`return_comment`バナー)はこの上に残す。
  - B. `AttendanceMonthSubjectView`は変更せず、`AttendanceMonthReferenceTabs`とは別に
    CSV/Excel出力・状態変更だけを個別に追加する。
- 決定: A。
- 理由: 論点1の決定そのもの。`AttendanceMonthSubjectView`の独自実装は
  `AttendanceMonthReferenceTabs`とほぼ同一ロジックの重複であり、統一によって約50行の重複コードが
  削除できる。Bは統一にならないため不採用。
- 未確定・要確認事項: なし(確定)。

### 論点4: `AttendanceReferencePage`に月別検索UIをどう追加するか

- 選択肢:
  - A. 既存の`YearMonthPicker`(`frontend/src/components/YearMonthPicker/YearMonthPicker.tsx`)を
    `AttendanceMonthReferenceTabs`(統一後の月次ビューの`navigation`)の前月/今月/次月ボタンの
    並びに追加し、直接年月を選ぶとその年月へジャンプする。ただし`restrictToYearMonth`が
    指定されている(=対象月が固定される`BackOfficeTaskDetailPage`・`WorkflowRequestDetailPage`
    経由)場合は表示しない(既存の前月/次月ボタンと同じ非表示条件)。
  - B. `AttendanceReferencePage`本体(社員選択の並び)に年月フィルターを追加する。
  - C. 新規に年月専用の検索ボックスを自作する。
- 決定: A。
- 理由: `MonthlyReferenceView`の`navigation`は既に`restrictToYearMonth === undefined`の場合のみ
  表示される分岐(176行目)を持っており、そこにそのまま追加できる。既存コンポーネント
  (`YearMonthPicker`)を再利用するため実装・見た目ともに`AttendanceExportPage`と一貫性が保てる。
  Bは社員選択と年月選択が同じ並びに来て、月次ビュー内の前月/次月ナビゲーションとUIが二重になる
  ため採用しない。Cは既存コンポーネントの再利用に反する。
- 未確定・要確認事項: なし(確定)。`YearMonthPicker`選択時は前月/次月ボタンと同じ状態
  (`yearMonth`)を更新するだけで、URLへの反映は行わない。

### 論点5: ネストしたカード構造(カードの中のカードの中のカード)の解消

- 選択肢:
  - A. `BackOfficeTaskDetailPage`・`WorkflowRequestSubjectDetail`の両方で、
    `AttendanceMonthReferenceTabs`(統一後は状態変更・CSV/Excel出力ボタンも内包)を囲んでいた
    `<div className="rounded-md border border-border p-3">`ラッパーを削除し、
    `AttendanceMonthReferenceTabs`が生成する`Card`群を外側の`Card(task.title)`/
    `Card(request.title)`の直下に直接配置する。
  - B. 外側の`Card(task.title)`/`Card(request.title)`自体を廃止する。
- 決定: A。
- 理由: 論点1〜3の統一を行うと、CSV/Excel出力・状態変更ボタンも`AttendanceMonthReferenceTabs`
  の内部に移るため、呼び出し側(`BackOfficeTaskDetailPage`・`WorkflowRequestSubjectDetail`)は
  ほぼ`<AttendanceMonthReferenceTabs userId={...} yearMonth={...} />`という1行の呼び出しだけに
  なる。この時点で、その1行を囲んでいた`div.border`ラッパーは見た目上意味を持たなくなるため
  削除する。外側の`Card(task.title)`/`Card(request.title)`はタスク/申請そのもののメタ情報
  (種別・担当者・履歴等)を表示する枠として引き続き必要なため、Bのように廃止すると他の情報
  (dl一覧・履歴・添付ファイル等)の表示場所が失われるため採用しない。
  結果として、`Card(task.title)` → `Card(月次勤怠)`/`Card(日別の内訳)`/`Card(管理者操作)`
  の2階層になり、表示幅も余分な`div.border.p-3`のpadding分広がる。
- 未確定・要確認事項: なし(確定)。

## 仕様確定事項(まとめ)

1. **`AttendanceMonthReferenceTabs`(`frontend/src/pages/attendance/AttendanceReferencePage.tsx`
   515-561行目)を拡張する**:
   - `useAuth`から`user`を取得し、`canExport = effective_permissions.includes('attendance.export')`、
     `canProcessBackOffice = effective_permissions.includes('backoffice_task.execute')`を算出する。
   - 月次タブ(`viewMode === 'month'`)表示中のみ、`MonthlyReferenceView`の下に以下を追加する
     (`MonthlyReferenceView`自体にpropsで渡すか、`AttendanceMonthReferenceTabs`側で並べて表示するかは
     実装時に`MonthlyReferenceView`のCard構成を崩さない形を選ぶ):
     - `canExport`のとき: CSV出力ボタン(`useDownloadAttendanceCsv`、`format: 'generic'`)・
       Excel出力ボタン(`useDownloadAttendanceExcel`)を表示する
       (`AttendanceMonthConfirmationSection`63-80行目と同じ呼び出し方)。
     - `canProcessBackOffice`のとき:
       - `month.status !== 'closed'`ならば「締める」(`ConfirmActionDialog`、
         `AttendanceMonthConfirmationSection`89-102行目と同内容)を表示する。
       - `month.status === 'closed'`ならば既存の`Card(管理者操作)`内の`ReopenMonthDialog`
         (「締めを取り消す」)を表示する(既存の`canReopenMonth`変数は`canProcessBackOffice`に
         置き換える)。
   - `restrictToYearMonth === undefined`の場合(`AttendanceReferencePage`単独ページとして
     開かれた場合)のみ、`navigation`の前月ボタンと今月ボタンの間に`YearMonthPicker`
     (`frontend/src/components/YearMonthPicker/YearMonthPicker.tsx`)を追加し、`value`は現在の
     `yearMonth`、`onChange`で`setYearMonth`する。
2. **`BackOfficeTaskDetailPage.tsx`(`frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`)**:
   - `AttendanceMonthConfirmationSection`(39-108行目)から、CSV出力・Excel出力ボタン
     (63-80行目)、「締める」/「締めを取り消す」(81-103行目)、`canReopenMonth`変数・
     `useDownloadAttendanceCsv`/`useDownloadAttendanceExcel`/`useCloseMonth`の呼び出しを削除する
     (すべて統一後の`AttendanceMonthReferenceTabs`が内包するため)。
   - `AttendanceMonthReferenceTabs`を囲んでいた`<div className="... rounded-md border
     border-border p-3">`(60-62行目)ラッパーを削除し、`AttendanceMonthReferenceTabs`を直接
     配置する。
   - `AttendanceMonthConfirmationSection`は結果的に「見出し+ローディング/エラー処理+
     `AttendanceMonthReferenceTabs`呼び出し」だけの薄いラッパーになる。
   - 「状態を変更する」セクション(196-218行目、`backoffice_tasks.status`変更用の汎用フォーム)は
     変更しない(月次勤怠の状態とは別の系列であり、統一対象ではない)。
3. **`WorkflowRequestSubjectDetail.tsx`
   (`frontend/src/components/WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail.tsx`)**:
   - `AttendanceMonthSubjectView`(63-131行目)の月次/週次/日次タブ切り替えUI・`dateRange`/
     `weekRange`計算(94-127行目相当)を削除し、`<AttendanceMonthReferenceTabs
     userId={subject.user_id} yearMonth={subject.year_month} />`の呼び出しに置き換える。
   - `Badge`+年月表示(82-84行目)・差戻し理由バナー(86-90行目、`return_comment`)は残す。
   - 107行目の`<div className="rounded-md border border-border p-3">`ラッパーを削除する。
4. 3画面とも、統一後は月次タブ表示時に「月次勤怠」「日別の内訳」カードに加え、権限があれば
   CSV/Excel出力ボタン・状態変更(締める/締めを取り消す)が同じ場所・同じ見た目で表示される。
   一般社員(自分の申請を閲覧する場合等)は`attendance.export`・`backoffice_task.execute`を
   持たないため、これらのボタンは表示されない。

## 対象外

- `closeMonth`のバックエンド権限(`permission:attendance.update,any`)を専用の権限
  (例: `attendance.month_close`)に切り出すこと。フロントエンドは`backoffice_task.execute`で
  ガードするため実害はなく、バックエンド権限定義の変更は本changesetの範囲外とする。
- `backoffice_tasks.status`と`attendance_months.status`を連動させるバックエンド改修。
  設計原則6(バックオフィス処理は承認とは別ステータス系列)を維持するため見送る。
- `AttendanceReferencePage`本体(社員選択欄)への年月フィルター追加(論点4選択肢B)。
- 週次・日次ビュー(`WeeklyReferenceView`・`DailyReferenceView`)への年月直接指定UIの追加
  (今回は月次ビューのみ)。
- `AttendanceMonthReferenceTabs`という名称自体の変更(コンポーネント名はそのまま拡張する)。

## ドキュメントへの影響

変更なし。今回の変更はUIレイアウト・操作導線の統一であり、ユースケースの手順・イベント名・
状態遷移(`docs/07-usecases-attendance.md`のUC-A011、`docs/11-usecases-backoffice.md`の
UC-B007)自体に変更はない。

## モック・アセット

なし。

## 実装対象

- `frontend/src/pages/attendance/AttendanceReferencePage.tsx`
  - `AttendanceMonthReferenceTabs`にCSV/Excel出力・状態変更(締める/締めを取り消す)・
    `YearMonthPicker`を追加。
  - 既存の`AttendanceReferencePage.test.tsx`に権限別の表示・月選択の動作確認テストを追加。
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`
  - `AttendanceMonthConfirmationSection`を薄いラッパーに簡素化(重複ボタン削除・ネスト解消)。
  - `BackOfficeTaskDetailPage.test.tsx`・`.stories.tsx`を追随して更新。
- `frontend/src/components/WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail.tsx`
  - `AttendanceMonthSubjectView`を`AttendanceMonthReferenceTabs`利用に置き換え。
  - `WorkflowRequestSubjectDetail.test.tsx`(存在すれば)・
    `WorkflowRequestDetailPage.test.tsx`/`.stories.tsx`を追随して更新。

## 検証方法

```
cd frontend
npm run test -- AttendanceReferencePage BackOfficeTaskDetailPage WorkflowRequestDetailPage WorkflowRequestSubjectDetail
npm run build   # 型チェック含む
```
Storybookで以下を目視確認する:
- `AttendanceReferencePage`: 権限あり/なしでのCSV/Excel出力・締める/締めを取り消すボタンの表示、
  `YearMonthPicker`での月移動。
- `BackOfficeTaskDetailPage`: 締め処理あり/なしの両状態(重複ボタンが出ないこと)。
- `WorkflowRequestDetailPage`(`subject_type='attendance_month'`): 週次/日次タブへの切り替え、
  一般申請者には管理者操作ボタンが出ないこと。

## レビュー履歴

- 初版(バックオフィスタスク詳細内の重複ボタン修正+参照画面への月別検索追加+ネスト解消)。
- ユーザーから重複ボタンの実体特定(管理者操作カード内 / Excel出力と同じ場所)の情報を得て、
  原因を`AttendanceMonthConfirmationSection`と`MonthlyReferenceView`(管理者操作カード)の
  二重描画と特定。修正方針を「表示順序の入れ替え」から「重複描画の削除による一本化」に更新。
- ユーザーから「申請詳細・バックオフィスタスク詳細・管理画面の勤怠参照の3画面で、月次勤怠を
  表示するコンポーネントを合わせてほしい。月内で週次/日次表示、Excel/CSV出力、状態変更が
  できるのが想定挙動」との追加要望を受け、対応範囲を3画面統一に拡大。
  `AttendanceMonthReferenceTabs`をCSV/Excel出力・状態変更を内包する形に拡張し、
  `WorkflowRequestSubjectDetail`の独自タブ実装も統一対象に含めた。権限ガード
  (`attendance.export`・`backoffice_task.execute`)の設計を新規に追加した(論点2)。

## 実装結果

未着手。
