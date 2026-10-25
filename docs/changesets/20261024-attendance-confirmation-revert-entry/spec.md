# 月次勤怠画面から「勤怠確定取消依頼」を作成する導線の追加

ステータス: 完了

## 変更要望(原文)

> 提出済み（承認済み）の勤怠を未提出済みに戻す方法を教えてください。
> 以前に実装の依頼をしたのですが画面でのボタンなどが見つかりません。
> もし未実装であれば実装をお願いします。

(回答で、UC-A018の機能は`docs/changesets/20260829-attendance-confirmation-revert-ui/`で
実装済みだが、月次勤怠画面からの導線が無く気づきにくいことを説明し、
「承認済みの月の月次勤怠画面に『確定取消を依頼する』ボタンを置き、申請作成画面を
種別・対象年月入力済みで開く」案を提示した。)

> はい、変更セットを作成してください

## 背景・目的

承認済みの月次勤怠を未提出に戻す手段(UC-A018)は、汎用申請→承認→バックオフィス処理として
実装済みだが、社員が普段見る月次勤怠画面には何の案内もない。申請種別プルダウンの中から
「勤怠確定取消依頼」を探す必要があり、利用者が手段に気づけていない。月次勤怠画面から
申請作成までの導線を追加し、対象年月の入力ミスも防ぐ。

## 現状(As-Is)

- `docs/07-usecases-attendance.md` UC-A018: 対象社員が「勤怠確定取消依頼」を申請(対象年月・
  取消理由)→承認→バックオフィスタスク自動生成→バックオフィス担当者が確定取消を実行し、
  `attendance_months.status`を`approved`から`not_submitted`へ戻す。
- `backend/database/seeders/RequestTypeSeeder.php` 89-99行目: 申請種別
  `attendance_confirmation_revert`。`form_schema`は`target_year_month`(text、必須)・
  `reason`(text、必須)。
- `frontend/src/pages/workflow/WorkflowRequestNewPage.tsx`: 申請種別・タイトル・
  `form_schema`の各項目・承認者を全て空の状態から入力する。URLクエリによる初期値の受け取りは
  無い。
- `frontend/src/pages/attendance/AttendanceMonthDetailPage.tsx`(`/attendance/months/:yearMonth`、
  ログインユーザー本人の月次勤怠): ステータスバッジ・差戻し理由・集計・日別内訳を表示する。
  `approved`/`closed`の月に「なぜ編集できないか・どうすれば直せるか」の案内は無い。
- `frontend/src/pages/backOffice/BackOfficeTaskDetailPage.tsx`の
  `AttendanceConfirmationRevertSection`は、申請の`form_data.target_year_month`と申請者IDから
  対象月を解決する。`target_year_month`が`YYYY-MM`形式でないと、対象月の取得が失敗するか空になり、
  「確定を取り消す」ボタンが何も表示されずに消える(79・94行目)。そのため確定を取り消せない。
- 既存の初期値受け渡しの前例: 月次勤怠画面の選択モード→各休暇申請画面への`?dates=`
  (`AttendanceSelectionActionBar`)。受け取り側の`MyPaidLeavePage.tsx`54-73行目は、フォームを
  子コンポーネントに分け、`useState(() => 初期値)`の遅延初期化でクエリ値を入れている。
- `GET /request-types`は権限不要で有効な種別を全て返す(申請権限`eligible_role_codes`では
  絞り込まない)。申請権限のチェックは保存時だけ
  (`DraftWorkflowRequestHandler.php`67-69行目)。フロントエンドのログインユーザー情報には
  ロールコードが無い。
- 申請機能は機能フラグ`workflow.requests`で出し分けている(`adminNavGroups.ts`141行目)。
- 申請者は常にログイン本人になる(`WorkflowRequestController.php`421行目)。確定取消の画面は
  申請者IDから対象月を解決する。

## 設計前提の検証

- 正データと派生データ: 今回はフロントエンドの導線(画面遷移と入力欄の初期値)だけを追加する。
  データは新しく作らず、書き込み経路も変えない。申請は既存の`POST /workflow-requests`から
  作られ、確定取消は既存の`RevertApprovedAttendanceMonth`(Command→`stored_events`)で実行される。
- 定義と実態: `target_year_month`は`form_schema`上は自由テキストだが、実際の読み手
  (`AttendanceConfirmationRevertSection`)は`YYYY-MM`形式を前提にしている。今回の導線では
  `YYYY-MM`を自動で入れるので、このずれによる失敗を導線経由では起こさない。自由入力の項目定義
  そのもの(手入力時の形式チェック無し)は直さない(対象外に記載)。
- 原因の分類: 不具合ではなく導線の欠落(機能はあるが見つけられない)。

## 仕様検討

### 論点1: 導線をどこに置くか

- 選択肢:
  - A. 月次勤怠詳細画面(`AttendanceMonthDetailPage`)の、ステータスが`approved`のときだけ
    案内とボタンを出す。
  - B. 月次一覧(`AttendanceMonthsPage`)の各行にも置く。
  - C. 日次画面(`AttendanceDayPage`)のロック表示にも置く。
- 決定: A。
- 理由: 承認済みの月の実績を直したいと思った社員が最初に見るのは月次勤怠詳細画面で、
  ステータスバッジもそこにある。B・Cは同じ導線の重複になり、画面ごとの表示条件の維持コストが
  増える。日次画面から月次画面へは既存の遷移で行ける。
- 未確定・要確認事項: なし。

### 論点2: どのステータスで表示するか

- 選択肢:
  - A. `approved`のみ。
  - B. `approved`と`closed`。`closed`では「締めの取消は管理者のみ」という案内文だけを出す。
- 決定: A。
- 理由: UC-A018の対象は`approved`のみ。`closed`の場合は管理者による締め取消(UC-A017)が先に
  必要で、社員が自分で始められる手続きではない。`closed`の月にこのボタンを出すと、承認後に
  バックオフィスが実行しても失敗する申請を作らせてしまう。`submitted`(承認前)は承認者の
  差戻し・申請取消で戻せるため、この導線の対象外。
- 未確定・要確認事項: なし。

### 論点3: 申請作成画面への初期値の渡し方

- 選択肢:
  - A. `WorkflowRequestNewPage`がURLクエリ`?type=<申請種別コード>&<form_schemaのkey>=<値>`を
    受け取り、申請種別と該当する`form_schema`項目に初期値を入れる。汎用の仕組みにして、
    特定の申請種別コードを画面にハードコードしない。
  - B. 勤怠確定取消依頼専用の申請作成画面を新しく作る。
  - C. 月次勤怠画面のダイアログで取消理由・承認者を入力させ、その場で申請を作成・提出する。
- 決定: A。
- 理由: 既存の汎用申請フロー(前回の変更セットの論点1)とバックエンドAPIをそのまま使え、
  変更が最小で済む。前例の`?dates=`と同じ「URLクエリで初期値を渡し、画面側で入れる」方式。
  B・Cは申請作成UIが二重になり、承認者選択などのフォームを重複して持つことになる。
- 初期値の詳細:
  - `type`が有効な申請種別一覧(`useRequestTypes()`)に無い場合は無視し、従来どおり未選択から
    始める。
  - `form_schema`の項目は、`type`が有効なときだけ、schemaに存在するkeyのクエリ値を入れる。
    schemaに無いkeyは無視する。
  - タイトルは`?title=`で受け取る。
  - 初期化はフォームを子コンポーネント(例: `WorkflowRequestNewForm`)に分け、親で種別一覧の
    取得完了を待ってから描画する。子の`useState`の遅延初期化で初期値を決める(前例
    `MyPaidLeavePage`と同じ)。`useEffect`での後追い代入はしない。再取得で値が上書きされる
    ことも起きない。
  - クエリ値は形式を検証せず、文字列のまま入れる。保存時の検証は既存どおりバックエンドが行う。
    date型の項目も同じで、`DatePicker`が解釈できない値なら未入力として表示される。
  - 初期値はあくまで初期値で、利用者は申請種別を含め自由に書き換えられる。申請種別を
    変えると、既存の挙動どおり`form_schema`の入力値はクリアされる。
- 未確定・要確認事項: なし。

### 論点4: 申請種別が無効化・未登録の場合のボタン表示

- 選択肢:
  - A. `useRequestTypes()`の結果に`attendance_confirmation_revert`がある場合だけボタンを出す。
  - B. 常に出す。種別が無い場合、遷移先で未選択のまま始まる。
- 決定: A。
- 追加条件: 機能フラグ`workflow.requests`が有効(または`effective_features`が未定義)の
  場合だけ表示する。無効のときは`useRequestTypes`の取得自体も行わない。`useRequestTypes`に
  `enabled`引数(既定true)を追加する。既存の呼び出しは変更不要。
- 申請権限(`eligible_role_codes`)による出し分けはしない。フロントエンドで本人のロールコードを
  取得できず、既存の申請作成画面も申請権限の無い種別を一覧に出して保存時にエラーにしているため、
  同じ挙動に揃える(対象外に記載)。
- 理由: 申請種別はマスタで無効化できる(設計原則8の考え方に沿い、運用側で制御できる)。
  無効化されている手続きに誘導しない。`useRequestTypes`は申請画面と同じクエリキーなので
  キャッシュが再利用され、追加コストは小さい。
- 未確定・要確認事項: なし。

## 仕様確定事項(まとめ)

1. **`WorkflowRequestNewPage`**: `useSearchParams`でクエリを読み、申請種別一覧の取得完了後に
   1回だけ初期値を入れる。
   - `type`: 有効な申請種別のcodeと一致すれば`requestTypeCode`に入れる。一致しなければ無視。
   - `title`: あれば`title`に入れる。
   - その他のクエリ: `type`が有効なときだけ、その種別の`form_schema`に存在するkeyの値を
     `formValues`に入れる。
   - 入力後に利用者が値を変えた場合、クエリで上書きし直さない(初期化は1回だけ)。
   - 実装方式: 種別一覧の取得完了後に描画する子コンポーネントで、`useState`の遅延初期化を使う
     (論点3)。
2. **`useRequestTypes(includeInactive = false, enabled = true)`**: `useQuery`の`enabled`に渡す。
3. **`AttendanceMonthDetailPage`**: 次の3条件を全て満たす場合だけ表示する。
   - `month?.status === 'approved'`
   - 機能フラグ`workflow.requests`が有効(または`effective_features`が未定義)
   - `useRequestTypes()`にcode `attendance_confirmation_revert`が含まれる

   表示場所は、月次勤怠カード内(差戻し理由の
   表示と同じ位置)に案内ブロックを表示する。
   - 文言: 「この月は承認済みのため編集できません。日次実績の修正が必要な場合は、勤怠確定取消依頼を
     申請してください（承認後、人事部が確定を取り消すと未提出に戻ります）。」
   - ボタン「確定取消を依頼する」: `<Link>`で
     `/requests/new?type=attendance_confirmation_revert&target_year_month=<yearMonth>&title=<種別名>（<yearMonth>）`
     に遷移する(クエリ値は`URLSearchParams`でエンコードする)。`title`の種別名部分は固定文字列に
     せず、取得した申請種別の`name`を使い、`${name}（${yearMonth}）`とする。
   - 配置は差戻し理由ブロックと同じ位置。色は警告色ではなく`rounded-md border border-border bg-muted p-3 text-sm`
     とする(承認済みは異常ではないため)。
4. 業務ルール・バックエンドの変更は無いため、`domain-test`のテスト追加は対象外。

## 受け入れ条件

- 承認済み(`approved`)の月の月次勤怠詳細画面に、案内文と「確定取消を依頼する」ボタンが表示される。
- `not_submitted`・`submitted`・`returned`・`closed`の月や、レコードが無い月には表示されない。
- `attendance_confirmation_revert`が有効な申請種別一覧に無い場合は表示されない。
- 機能フラグ`workflow.requests`が無効の利用者には表示されない。
- ボタンを押すと申請作成画面が開き、申請種別「勤怠確定取消依頼」・タイトル・対象年月
  (`YYYY-MM`)が入力済みになっている。利用者が入力するのは取消理由と承認者だけ。
- 申請作成画面をクエリ無しで開いたときの挙動は従来と変わらない。不正な`type`は無視される。
- 関連テスト(下記の検証方法)と、型チェック・lintがPASSする。

## 対象外

- 申請作成後(承認・バックオフィス処理)のUIの変更。前回の変更セットで実装済み。
- `closed`の月の締め取消(UC-A017)の導線。
- 同じ月に勤怠確定取消依頼がすでに進行中かどうかの判定・表示(二重申請の防止)。
- `target_year_month`の項目定義を年月専用の入力型に変えること、およびバックエンドでの
  形式チェックの追加。
- 月次一覧・日次画面への導線追加(論点1)。
- 申請権限(`eligible_role_codes`)によるボタンの出し分け。既存の申請作成画面と同じく、保存時の
  エラーで扱う(論点4)。
- 代理申請の扱い(既知の制約): docs/07 UC-A018 手順1には「または代理で人事部」とある。しかし
  申請者は常にログイン本人で、確定取消の画面は申請者IDから対象月を解決する。そのため、人事部が
  代理で申請すると人事部担当者自身の月を探してしまう。今回の導線は本人申請だけなので影響しない。
  docsとの食い違いは別の変更で扱う。
- 確定取消の処理側(`RevertApprovedAttendanceMonthHandler`)の検証強化。現在は申請が存在するか
  だけを見ており、次の点を検証していない。別の変更で扱う。
  - 申請の状態・種別
  - 申請者と月の持ち主の一致
  - 対象年月の一致
- 他人の月次勤怠を見る画面(勤怠参照画面等)への導線追加。

## ドキュメントへの影響

- `docs/07-usecases-attendance.md` UC-A018 手順1に、「月次勤怠画面(承認済みの月)の
  『確定取消を依頼する』から、申請種別・対象年月が入力済みの申請作成画面を開ける」旨を1文追記する。
- `docs/10-usecases-workflow.md`: 申請作成画面がURLクエリで初期値を受け取れることを追記する
  (UC-W002の該当箇所)。

## モック・アセット

なし(既存の差戻し理由ブロックと同じ配置のため)。

## 実装対象

- `frontend/src/pages/workflow/WorkflowRequestNewPage.tsx`: クエリ初期値の取り込み。
- `frontend/src/pages/workflow/WorkflowRequestNewPage.test.tsx`: クエリ有り(種別・タイトル・
  form項目が入る)/不正な`type`(無視される)/schemaに無いkey(無視される)のテストを追加。
- `frontend/src/pages/attendance/AttendanceMonthDetailPage.tsx`: 案内ブロックとボタン。
- `frontend/src/hooks/useRequestTypes.ts`: `enabled`引数を追加。
- `frontend/src/pages/attendance/AttendanceMonthDetailPage.test.tsx`:
  - 前提: `beforeEach`で`requestTypesApi.fetchRequestTypes`をspyし、既定では
    `attendance_confirmation_revert`を含めて返す。
  - 追加するテスト: `approved`で表示されリンク先が正しいこと、`submitted`/`closed`で
    非表示、申請種別が無い場合に非表示、`workflow.requests`が無効の場合に非表示。
- `frontend/src/pages/attendance/AttendanceMonthDetailPage.stories.tsx`: `withSeeded`で
  `['request-types', false]`に`attendance_confirmation_revert`を含めて投入する。既存の
  `Approved` storyで案内が表示されることを確認する(storyの新規追加はしない)。
- `docs/07-usecases-attendance.md`・`docs/10-usecases-workflow.md`: 上記の追記。

## 検証方法

```
cd frontend
npx tsc -p tsconfig.app.json --noEmit
npm run lint
npm test -- src/pages/workflow/WorkflowRequestNewPage.test.tsx src/pages/attendance/AttendanceMonthDetailPage.test.tsx
```

## レビュー履歴

- 初版作成。
- 2026-10-24 独立設計レビュー(general-purposeエージェント)。must-fixは無し。指摘への対応:
  - 対応した指摘:
    - 初期化方式を、子コンポーネントの`useState`遅延初期化に固定(論点3)。
    - テスト・storyの`fetchRequestTypes`モックと`request-types`の事前投入を実装対象に明記。
    - 機能フラグ`workflow.requests`の表示条件と、`useRequestTypes`の`enabled`引数を追加(論点4)。
    - 配色を具体的なクラスで指定。
    - 現状の説明(形式外の年月のときの実際の挙動)を修正。
    - クエリ値は形式を検証しないと明記。
    - タイトルは種別名`name`から組み立てる。
  - 対象外として記録した指摘:
    - 申請権限による出し分け(既存の申請作成画面と同じ挙動)。
    - 代理申請とdocsの食い違い(既知の制約)。
    - 確定取消の処理側の検証強化(別の変更)。

## 実装結果

実装済み(implementerに委譲し、委譲元が差分とテストを確認した)。

変更ファイル:
- `frontend/src/hooks/useRequestTypes.ts`
- `frontend/src/pages/workflow/WorkflowRequestNewPage.tsx`・`.test.tsx`
- `frontend/src/pages/attendance/AttendanceMonthDetailPage.tsx`・`.test.tsx`・`.stories.tsx`
- `docs/07-usecases-attendance.md`(UC-A018 手順1)・`docs/10-usecases-workflow.md`(UC-W002)

仕様からの差分:
- 「機能フラグ無効」のテストは、テストファイルが`useAuth`を`vi.mock`しているため、AuthContext.Providerではなく`vi.hoisted`の可変参照でユーザーを差し替えた。

テスト結果:
- `npx tsc -p tsconfig.app.json --noEmit`: エラーなし。
- `npm run lint`: exit 0。警告は既存ファイルのみ。
- `npm test -- src/pages/workflow src/pages/attendance/AttendanceMonthDetailPage.test.tsx src/pages/backOffice`: 8ファイル・93件PASS。
  - 新規テストは、WorkflowRequestNewPageの3件とAttendanceMonthDetailPageの4件。

受け入れ条件との対応:
- 表示条件(approvedのみ・種別無効時・機能フラグ無効時): AttendanceMonthDetailPageの新規テスト4件。
- 初期値の受け渡し、不正なtypeの無視、schemaに無いkeyの無視: WorkflowRequestNewPageの新規テスト3件。
- クエリ無しでの従来挙動: 既存テストがPASS。

既知の制約:
- form_schemaのkeyが`type`・`title`と同名の場合、そのkeyはクエリで初期値を受け取れない(`type`・`title`を予約として扱う)。現在の申請種別に該当するkeyは無い。
