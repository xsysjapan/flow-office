# 月次勤怠表示コンポーネントの統一(申請詳細・バックオフィスタスク詳細・勤怠参照画面)

ステータス: 完了

## 変更要望(原文)

> 勤怠差し戻し後の再提出ボタンがタスク詳細で二つ表示されています。管理画面の参照機能と整合性を合わせてください。また、参照画面については月別で検索・参照できるようにして画面表示をタスク詳細と合わせてください。
> 加えてタスク詳細だとカードの中のカードの中のカードのようになっていて、領域が狭いです。もう少し広く表示されるように見直しをお願いします

> 月次勤怠の表示にあたり、申請詳細、バックオフィスタスク詳細、管理画面の勤怠参照で表示するコンポーネントを合わせてください。当該月内で週次、日次を表示し、Excel出力、CSV出力、状態変更ができるのが想定の挙動です。

> 締めを取り消す権限を専用で設計しているはずなので確認してください。誰でも締めを取り消せるわけではなく、確定状態も取り消せる権限が決まっていたと思います。

> 確定する権限は一般社員ですが、確定を取り消す権限は確定可能とは分けてください。一般社員は自身の勤怠を確定できるが、確定の取り消しは自分でできる場合もあるが、基本的には上長が行うので、個別の権限として管理したいです。

> 逆に上長は確定できないという運用もできるようにしたいです。申請も申請取り下げができるようにして、連動させたいです。

> すみません、申請の取り下げ権限とボタンの追加のみで良いです。申請を取り下げると自動的に提出済みを解消します。上長は差し戻しができるはずなのでそれで問題ないです

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

**ユーザー指摘により修正**: 「締めを取り消す」には既に専用権限`attendance.month_reopen`
(月次勤怠締め取消、`global`スコープ限定)が設計されており、「誰でも締めを取り消せるわけではない」
ことを保証する意図で作られている(`docs/07-usecases-attendance.md` UC-A011「救済コマンド(管理者
専用)」)。この専用権限を他の権限に置き換えたり流用したりせず、そのまま使う。「締める」
(まだ締めていない月を確定する操作)には対応する専用権限が無いため、別途権限を選定する。

- 選択肢:
  - A. 「締めを取り消す」は既存の`attendance.month_reopen`権限のまま変更しない
    (`canReopenMonth`変数・条件は現状の`AttendanceReferencePage.tsx`160行目・239行目のロジックを
    そのまま維持する)。「締める」とCSV/Excel出力は別の権限で判定する
    (「締める」は`backoffice_task.execute`、CSV/Excel出力は`attendance.export`)。
  - B.(前回誤って決定した案)「締める」「締めを取り消す」を両方`backoffice_task.execute`に
    一本化し、`attendance.month_reopen`は使わない。
  - C. 画面ごとに表示条件を変える(例: `BackOfficeTaskDetailPage`だけ無条件表示、他の2画面は
    権限チェック)。
- 決定: A。
- 理由: `attendance.month_reopen`は「締め済み(確定済み)の月次勤怠を取り消せるか」という、
  他の権限とは意味が異なる専用の権限として既に設計・運用されている(締めた後のデータは
  日次実績のロック解除を伴う不可逆性の高い操作のため、`backoffice_task.execute`を持つ全員より
  さらに狭い範囲に限定する意図があったはず)。Bのように統一してしまうと、
  `backoffice_task.execute`を持つがまだ`attendance.month_reopen`を付与されていない
  ロール・ユーザーにも締め取消ができてしまい、権限設計を弱めてしまう。ユーザー指摘の通り
  「確定状態も取り消せる権限が決まっていた」ことを尊重し、Aを採用する。Cは論点1の
  「コンポーネントを合わせる」という決定に反するため不採用。
  なお、「締める」(未確定→確定)には対応する専用権限が存在しない
  (`AccessControlCatalog.php`には`attendance.month_reopen`のみが「月次勤怠の確定状態を
  変更する」系の専用権限として存在し、「締める」用の専用権限は無い)。`backoffice_task.execute`は
  HR_STAFF・BACKOFFICE_STAFFロールに付与されている「バックオフィスタスクを処理できるか」を表す
  権限で、月次勤怠の締めという管理者操作の実施者として意味が合致し、かつ`self`スコープを
  持たない(一般社員には付与されない)ため、CSV/Excel出力(`attendance.export`)と合わせて
  3画面共通でこれを使っても一般社員に誤って管理者操作ボタンが見えることはない。
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

### 論点6: 「提出取消(取り下げ)」(申請の取消と連動した月次勤怠の未提出化)を専用権限で管理する

ユーザーからの追加要望により判明した現状、および要望のスコープ確定:
- 月次勤怠申請(`subject_type = 'attendance_month'`)の`workflow_request`を「取消」すると、
  `AttendanceMonthCancelOnWorkflowRequestCancelledReactor`が対象の`attendance_months`を
  `not_submitted`へ戻す(`CancelSubmittedAttendanceMonth`コマンド)。これが実質的な
  「提出取消(取り下げ)」機能であり、既に実装・連動済み(取り下げれば自動的に未提出に戻る、
  という点はそのまま維持する)。
- 取消(取り下げ)の実行権限は`CancelWorkflowRequestHandler`27-28行目
  (`workflowRequest->applicant_user_id !== $command->cancelledByUserId`)による
  **申請者本人のみ**というハードコードのownershipチェックのみで、専用の権限チェックが無い。
  ここに専用権限のチェックを追加する(**申請者本人が取り下げる**ケースのみを対象とする)。
- **ユーザーからの明確化により、上長・HR等による代理取消(申請者本人以外が取り消す経路)は
  今回は作らない**。上長は既存の「差し戻す」(`returnMonth`/`returnRequest`、
  `approval.execute`)で同等の目的(申請者に月次勤怠をやり直させる)を果たせるため、
  別に代理取消の経路を新設する必要はない。
- 併せて確認した2点は現状のままで要件を満たすため変更不要と確定した:
  - 承認(`approve`)を上長ロールから外す運用は、既存の`approval.execute`権限の付与有無で
    既に可能。
  - 上長が部下の代わりに提出する(代理提出)機能はそもそも存在しない。

- 選択肢:
  - A. 新権限`attendance.submission_revoke`(スコープ`self`のみ)を追加する。
    `CancelWorkflowRequestHandler`のownership判定(申請者本人のみ、という制約自体は変更しない)
    に加えて、`subject_type === 'attendance_month'`の場合は本人が`attendance.submission_revoke`
    (self)を持つことも必須にする。他のsubject_type(経費精算・有給等)は権限チェックを追加せず、
    従来通り申請者本人であれば取消可能なまま変更しない。
  - B. スコープに`global`/`group`も含めておき、将来上長・HRが代理取消できるように拡張余地を
    残す。
  - C. 権限チェックは追加せず、申請者本人のみのまま(現状維持)。
- 決定: A。
- 理由: ユーザーの最新の要望により、代理取消の経路自体が不要と明確化されたため、
  実際に使われないスコープ(`global`/`group`)を持つ権限を定義するのは過剰設計になる
  (Bは不採用。CLAUDE.mdの「その場で使わない将来のための抽象化を避ける」方針にも反する)。
  Cはユーザー要望(「取消権限を個別に管理したい」)に反するため不採用。
  権限判定は`EffectiveAccessResolver::hasPermission($user, 'attendance.submission_revoke',
  null, $callerUserId)`(selfスコープなので対象は常に呼び出し本人)を用いる。
- 未確定・要確認事項: なし(確定)。

## 仕様確定事項(まとめ)

1. **`AttendanceMonthReferenceTabs`(`frontend/src/pages/attendance/AttendanceReferencePage.tsx`
   515-561行目)を拡張する**:
   - `useAuth`から`user`を取得し、`canExport = effective_permissions.includes('attendance.export')`、
     `canCloseMonth = effective_permissions.includes('backoffice_task.execute')`、
     既存の`canReopenMonth = effective_permissions.includes('attendance.month_reopen')`
     (160行目、変更しない)の3つを算出する。「締める」と「締めを取り消す」で権限チェックを
     分けるのは、`attendance.month_reopen`が確定済み状態を取り消す専用権限として設計されており、
     `backoffice_task.execute`を持つ全員に自動的に締め取消権限を広げてはならないため
     (論点2参照)。
   - 月次タブ(`viewMode === 'month'`)表示中のみ、`MonthlyReferenceView`の下に以下を追加する
     (`MonthlyReferenceView`自体にpropsで渡すか、`AttendanceMonthReferenceTabs`側で並べて表示するかは
     実装時に`MonthlyReferenceView`のCard構成を崩さない形を選ぶ):
     - `canExport`のとき: CSV出力ボタン(`useDownloadAttendanceCsv`、`format: 'generic'`)・
       Excel出力ボタン(`useDownloadAttendanceExcel`)を表示する
       (`AttendanceMonthConfirmationSection`63-80行目と同じ呼び出し方)。
     - `month.status !== 'closed' && canCloseMonth`のとき:「締める」
       (`ConfirmActionDialog`、`AttendanceMonthConfirmationSection`89-102行目と同内容)を表示する。
     - `month.status === 'closed' && canReopenMonth`のとき: 既存の`Card(管理者操作)`内の
       `ReopenMonthDialog`(「締めを取り消す」)を表示する(既存条件・変数名は変更しない)。
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
   一般社員(自分の申請を閲覧する場合等)は`attendance.export`・`backoffice_task.execute`・
   `attendance.month_reopen`のいずれも持たないため、これらのボタンは表示されない。
   `attendance.month_reopen`は「締めを取り消す」専用のまま維持し、`backoffice_task.execute`を
   持つだけでは「締めを取り消す」ボタンは表示されない(論点2)。
5. **新権限`attendance.submission_revoke`を追加する**
   (`backend/app/Domain/AccessControl/AccessControlCatalog.php`、名称例:「月次勤怠提出取下げ」、
   スコープ`['self']`のみ)。`access-control:sync`相当のコマンドで`permissions`テーブルへ反映する
   (既存の権限追加手順と同じ)。
6. **`backend/app/Domain/Workflow/Handlers/CancelWorkflowRequestHandler.php`に権限チェックを
   追加する**:
   - 現行のownership判定(27-28行目、申請者本人のみ)はそのまま維持する
     (代理取消は作らない)。
   - `$workflowRequest->subject_type === 'attendance_month'`の場合に限り、追加で
     `EffectiveAccessResolver::hasPermission($user, 'attendance.submission_revoke', null,
     $command->cancelledByUserId)`(selfスコープ、対象は常に本人)を満たさなければ
     `DomainRuleException`を投げるようにする。
   - `attendance_month`以外の`subject_type`(経費精算・有給等)には権限チェックを追加せず、
     従来通り申請者本人であれば取消可能なまま変更しない。
   - `CancelWorkflowRequest`コマンド自体には`subject_type`が含まれていないため、
     `WorkflowRequest`モデルから取得する(既に25行目で取得済みの`$workflowRequest`から
     `subject_type`を参照できる)。
7. **フロントエンド`WorkflowRequestDetailPage.tsx`の「取消」表示条件を拡張する**
   (159-183行目付近):
   - 現行`isApplicant && ['draft', 'submitted', 'returned'].includes(request.status)`に、
     `request.subject_type === 'attendance_month'`の場合のみ追加で
     `effective_permissions.includes('attendance.submission_revoke')`を要求する
     (他のsubject_typeは現行の表示条件のまま変更しない)。
   - `WorkflowRequestListPage.tsx`の一覧・「まとめて取消」(`isWorkflowRequestCancellable`)にも
     同様に、`subject_type === 'attendance_month'`の行にはこの権限チェックを反映する
     (一覧APIのレスポンス(`subject_type`)から判定可能であり、`effective_permissions`は
     すでに`useAuth`経由でフロント側にあるため、追加のAPI変更は不要)。

## 対象外

- 申請者本人以外(上長・HR等)による代理取消の経路。上長は既存の「差し戻す」で同等の目的を
  果たせるため作らない(論点6)。
- `attendance.submission_revoke`への`global`/`group`スコープの追加。代理取消を作らない
  ため`self`のみで十分(論点6)。
- `closeMonth`のバックエンド権限(`permission:attendance.update,any`)を専用の権限
  (例: `attendance.month_close`)に切り出すこと。フロントエンドは`backoffice_task.execute`で
  ガードするため実害はなく、バックエンド権限定義の変更は本changesetの範囲外とする。
- `backoffice_tasks.status`と`attendance_months.status`を連動させるバックエンド改修。
  設計原則6(バックオフィス処理は承認とは別ステータス系列)を維持するため見送る。
- `AttendanceReferencePage`本体(社員選択欄)への年月フィルター追加(論点4選択肢B)。
- 週次・日次ビュー(`WeeklyReferenceView`・`DailyReferenceView`)への年月直接指定UIの追加
  (今回は月次ビューのみ)。
- `AttendanceMonthReferenceTabs`という名称自体の変更(コンポーネント名はそのまま拡張する)。
- 承認(`approval.execute`)権限の見直し・「代理提出」機能の新設。既存の設計で要件を満たすため
  変更しない(論点6参照)。
- `attendance_month`以外の`subject_type`(経費精算・有給・特別休暇・振替休日・代休)への
  `attendance.submission_revoke`と同種の権限ガードの追加。今回は`attendance_month`のみを
  対象とする(他のsubject_typeの取消は現状のまま、申請者本人であれば無条件に取消可能)。

## ドキュメントへの影響

- `docs/07-usecases-attendance.md`: UC-A010(月次勤怠申請の取消)に、`attendance.submission_revoke`
  権限を持つ申請者本人のみが取り下げられる旨(上長等による代理取消は無い旨も明記)を追記する
  (`attendance.month_reopen`が同章に明記されているのと同じ書き方に揃える)。
- 上記以外(UC-A011・UC-B007等)は変更なし。

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
- `frontend/src/pages/workflow/WorkflowRequestDetailPage.tsx`
  - 「取消」ボタンの表示条件に、`subject_type === 'attendance_month'`の場合のみ
    `attendance.submission_revoke`権限チェックを追加(仕様確定事項7)。
  - `WorkflowRequestDetailPage.test.tsx`に権限あり/なしのテストケースを追加。
- `frontend/src/pages/workflow/WorkflowRequestListPage.tsx`
  - 「まとめて取消」の選択可否(`isWorkflowRequestCancellable`相当)にも同様の権限チェックを反映。
  - `WorkflowRequestListPage.test.tsx`にテストケースを追加。
- `backend/app/Domain/AccessControl/AccessControlCatalog.php`
  - `attendance.submission_revoke`(スコープ`self`のみ)を追加。
- `backend/app/Domain/Workflow/Handlers/CancelWorkflowRequestHandler.php`
  - 既存のownership判定(申請者本人のみ)はそのまま維持し、
    `subject_type === 'attendance_month'`の場合に限り`attendance.submission_revoke`
    (selfスコープ)保有チェックを追加する(仕様確定事項6)。
  - `EffectiveAccessResolver`をコンストラクタインジェクションで受け取る。
- `backend/tests/Feature/Workflow/`または`backend/tests/Feature/Attendance/`
  - `attendance.submission_revoke`を持つ申請者本人による取消(成功)・持たない申請者本人による
    取消(403/DomainRuleException)・他のsubject_type(経費精算等)は権限に関わらず取消可能
    (非破壊確認)の3パターンをFeatureテストで追加する。
  - `backend/tests/Feature/AccessControl/PermissionCatalogIntegrationTest.php`等、権限一覧を
    網羅的に検証している既存テストに`attendance.submission_revoke`が反映されるか確認する。

## 検証方法

```
cd frontend
npm run test -- AttendanceReferencePage BackOfficeTaskDetailPage WorkflowRequestDetailPage WorkflowRequestSubjectDetail WorkflowRequestListPage
npm run build   # 型チェック含む

cd ../backend
php artisan test --filter=Workflow
php artisan test --filter=AccessControl
```
Storybookで以下を目視確認する:
- `AttendanceReferencePage`: 権限あり/なしでのCSV/Excel出力・締める/締めを取り消すボタンの表示、
  `YearMonthPicker`での月移動。
- `BackOfficeTaskDetailPage`: 締め処理あり/なしの両状態(重複ボタンが出ないこと)。
- `WorkflowRequestDetailPage`(`subject_type='attendance_month'`): 週次/日次タブへの切り替え、
  一般申請者には管理者操作ボタンが出ないこと、`attendance.submission_revoke`を持たない申請者
  本人には「取消」ボタンが出ないこと。

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
- ユーザーから「締めを取り消す権限は専用で設計されているはず。誰でも締めを取り消せるわけでは
  なく、確定状態を取り消せる権限が決まっていた」との指摘を受け、論点2を修正。前回誤って
  「締める」「締めを取り消す」を両方`backoffice_task.execute`に一本化する案にしていたが、
  既存の専用権限`attendance.month_reopen`(月次勤怠締め取消、`global`スコープ限定)を
  「締めを取り消す」の判定にそのまま残し(`canReopenMonth`変数・条件は変更しない)、
  「締める」(専用権限が存在しない操作)にのみ`backoffice_task.execute`を新たに使う形に修正した。
- ユーザーから「一般社員は自身の勤怠を確定できるが、確定の取り消しは自分でできる場合もあるが
  基本的には上長が行うので、個別の権限として管理したい」との要望。確認の結果、「確定」は
  提出(`attendance.update`, self)、「確定の取り消し」は申請(`workflow_request`)の「取消」と
  連動する既存の`CancelSubmittedAttendanceMonth`(月次勤怠を`not_submitted`へ戻す)を指すと判明。
  現状は申請者本人のみ取消可能(専用権限なし、上長による代理取消の経路も無し)だったため、
  論点6として新設。新権限`attendance.submission_revoke`(スコープ`global`/`group`/`self`)を
  追加し、`CancelWorkflowRequestHandler`のownership判定を
  「申請者本人」または「`attendance_month`型かつ本権限保有」に拡張する方針とした。
  併せて確認した「承認権限を上長から外す運用」「上長の代理提出」は、いずれも既存の設計
  (`approval.execute`権限・代理提出機能が存在しない)で要件を満たすため変更不要と確定した。
- ユーザーから「申請の取り下げ権限とボタンの追加のみで良い。取り下げると自動的に提出済みを
  解消する(既存動作のまま)。上長は差し戻しができるので、代理取消は不要」との明確化を受け、
  論点6を簡素化。上長・HR等による代理取消の経路は作らないことに決定し、
  `attendance.submission_revoke`のスコープを`global`/`group`/`self`から`self`のみに縮小、
  `CancelWorkflowRequestHandler`の変更も「ownership判定の拡張」ではなく「既存のownership判定に
  加えてattendance_month型の場合のみ本権限保有を追加要求する」形に修正した。
  フロントエンドの表示条件・実装対象・検証方法もこれに合わせて修正した。

## 実装結果

実装済み。コミット(ブランチ`claude/attendance-resubmit-button-fix-f9h66r`):

- `d402ae0` 月次勤怠表示を3画面で統一(申請詳細・バックオフィスタスク詳細・勤怠参照)
  - `frontend/src/pages/attendance/AttendanceReferencePage.tsx`:
    `AttendanceMonthReferenceTabs`(`MonthlyReferenceView`)にCSV/Excel出力
    (`attendance.export`)・締める(`backoffice_task.execute`)・締めを取り消す
    (既存の`attendance.month_reopen`、変更なし)・`YearMonthPicker`による月別検索を追加。
  - `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`:
    `AttendanceMonthConfirmationSection`を「見出し+ローディング/エラー処理+
    `AttendanceMonthReferenceTabs`呼び出し」だけの薄いラッパーに簡素化。これにより
    「締めを取り消す」ボタンの二重描画バグを解消し、余分な`div.border`ラッパーも削除。
  - `frontend/src/components/WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail.tsx`:
    `AttendanceMonthSubjectView`の独自タブ実装を`AttendanceMonthReferenceTabs`呼び出しに
    置き換え。
  - `frontend/src/pages/workflow/WorkflowRequestDetailPage.tsx`・`WorkflowRequestListPage.tsx`:
    「取消」の表示条件に、`subject_type === 'attendance_month'`の場合のみ
    `attendance.submission_revoke`権限チェックを追加。
  - テスト: `AttendanceReferencePage.test.tsx`(締める/CSV/Excel出力のテスト追加)・
    `BackOfficeTaskDetailPage.test.tsx`(重複ボタン解消に伴う修正)・
    `WorkflowRequestDetailPage.test.tsx`・`WorkflowRequestListPage.test.tsx`
    (権限あり/なしのテスト追加)を更新。
- `2506ba5` 月次勤怠の申請取消(取り下げ)に専用権限`attendance.submission_revoke`を追加
  - `backend/app/Domain/AccessControl/AccessControlCatalog.php`: 新権限
    `attendance.submission_revoke`(「月次勤怠提出取下げ」、スコープ`['self']`のみ)を追加。
  - `backend/app/Domain/Workflow/Handlers/CancelWorkflowRequestHandler.php`:
    既存のownership判定(申請者本人のみ)は維持し、`subject_type === 'attendance_month'`の
    場合のみ本権限保有を追加要求(`EffectiveAccessResolver::hasPermission`)。他の
    subject_typeは変更なし。
  - `backend/tests/TestCase.php`: `grantSelfPermission()`ヘルパーを追加。
  - `backend/tests/Feature/Workflow/AttendanceMonthCancelPermissionTest.php`(新規):
    権限あり本人取消成功・権限なし本人取消失敗(422)・他subject_type(経費精算)は
    権限不問で取消可能、の3パターンを検証。
  - `backend/tests/Feature/Attendance/AttendanceFlowTest.php`・
    `backend/tests/Feature/AccessControl/PermissionCatalogIntegrationTest.php`:
    新権限追加に伴う既存テストの非破壊修正。
- 追加コミット: ユーザーから「勤怠参照画面のイメージが違う。年月選択を上部に配置し月別で
  表示、コンポーネントまるごと承認画面と共通化してほしい。週次/日次の挙動もExcel出力等の
  要素も他画面と同じにしたい」との指摘を受け、`AttendanceReferencePage`本体を修正。
  - 画面上部(社員選択の隣)に前月/次月ボタン+`YearMonthPicker`+今月ボタンを配置し、
    URL(`?user=&yearMonth=`)で状態を保持するようにした。月次/週次/日次の切り替えボタンは
    ページ本体からは削除し、選択した社員・年月をそのまま`AttendanceMonthReferenceTabs`
    (承認画面`ApprovalDetailPanel`→`WorkflowRequestSubjectDetail`、バックオフィスタスク詳細と
    完全に同一のコンポーネント)に渡すだけにした。これにより週次/日次への切り替え・
    月内制限ナビゲーション・CSV/Excel出力・状態変更の挙動が他画面と完全に一致する。
  - `MonthlyReferenceView`の`restrictToYearMonth`(オプショナル、未指定時のみ前月/次月ボタンと
    `YearMonthPicker`を内部に表示する分岐)は、この変更で全呼び出し元が常に年月を渡す形に
    統一されたため到達不能コードになった。`yearMonth`必須のシンプルな props
    (`initialYearMonth`・`restrictToYearMonth`を`yearMonth`に統合)に整理し、内部の
    重複ナビゲーションUIを削除した。
  - `AttendanceReferencePage.test.tsx`: 週次・日次に切り替えた際の初期表示が「今週/今日」
    ではなく「選択中の年月の最初の週/1日目」になる(他画面と同じ挙動)ことに合わせて
    既存テストの期待値を更新した。

テスト結果:
- フロントエンド: `npm test`(vitest)全体 801 passed / 2 skipped / 6 failed
  (失敗6件は`ApprovalsPage.test.tsx`・`ApprovalDetailPanel.test.tsx`の既存の
  テスト間干渉によるもので、変更前のmainブランチでも同一の失敗が再現することを確認済み
  (本changesetとは無関係)。
- バックエンド: `php artisan test --filter=Workflow`(57件)・`--filter=AccessControl`
  (8件)・`AttendanceFlowTest`(18件)いずれも成功。
- `npx tsc --noEmit`・`npm run lint`(oxlint)ともにエラーなし。
