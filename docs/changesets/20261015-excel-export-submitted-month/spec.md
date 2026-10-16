# 承認待ち(提出済み)の月次勤怠でExcel勤怠管理表がタイトルだけになる不具合の修正

ステータス: 完了

## 変更要望(原文)
> Excelの集計表もおかしくなっています。履歴を調べて対応をお願いします。

(確認のやり取り)
> 月次で出力される単票形式(31日分の行付き)のはずがタイトルしか表示されていない
> (承認待ちの提出済み作業報告書のWeb画面)
> 修正方針: 提出済み(承認待ち)も出力可
> (2026-10-16 実装後)CSVもExcelと同じ条件にしてください。未承認で出力できても問題ないです。

## 背景・目的
承認者が承認待ちの月次勤怠(申請詳細画面)で「Excel出力」を押すと、31日分の行がある勤怠管理表ではなく
タイトルだけのシートがダウンロードされる。承認前に勤怠管理表で内容を確認できるようにする。
対象者は「出力」カードが表示される勤怠出力権限(`attendance.export`。現行は人事・管理者)を持つ利用者で、一般の承認者への
出力ボタンの開放は今回扱わない。

## 現状(As-Is)
- 出力対象の月次は`ExportController::resolveAttendanceMonths()`(`backend/app/Http/Controllers/Api/ExportController.php:683-693`)が
  `status IN (approved, closed)`で抽出する。CSV(`:62`)・Excel(`:107`)・外部連携(`:296`)で共通。この条件は2026-08-04
  (`78d594e2`「月次締め処理をバックオフィスタスク駆動のフローに変更」)から変わっていない。
- 対象が0件のとき、Excelは`AttendanceExcelBuilder::buildEmpty()`でタイトル(`YYYY年M月度 勤怠管理表`)だけのシートを返す
  (`ExportController.php:110-113`)。
- 2026-08-29の`d402ae06`「月次勤怠表示を3画面で統一(申請詳細・バックオフィスタスク詳細・勤怠参照)」で、申請詳細画面の月次勤怠
  (`WorkflowRequestSubjectDetail.tsx` → `AttendanceMonthReferenceTabs`)にも「出力」カード(CSV出力・Excel出力。
  `AttendanceReferencePage.tsx:232-252`、表示条件は`attendance.export`権限のみ)が出るようになった。承認待ち(submitted)の月でも
  ボタンが出るが、APIは承認済み・締め済みしか対象にしないため、0件=タイトルだけのシートになる。
- 本日のマージ(`961c313`、PR #115)の前後で、承認済みの月のExcel出力は全セル同じ(新規データでの比較で確認)。今回の不具合は
  本日のマージによる退行ではない。
- 提出時に`SubmitAttendanceMonthHandler::buildSnapshot()`(`:92-103`)が`snapshot_json`を記録するため、提出済みの月にも
  Excelの集計欄が使う値はそろっている。
- docs: `docs/14-usecases-export.md`「Excel出力(UC-E001 拡張)」は「同じ対象月抽出ロジック」、同`:35-36`は「締め前の承認済み・
  締め済みのどちらからもCSV・Excelを出力できる」。

## 設計前提の検証
- 正データと派生データ: Excelは読み取りだけ(日次勤怠・日次計算・月次の`snapshot_json`・付与/消化)。書き込みは出力履歴の
  `export.created`イベントだけで、今回は変えない。正データの二重化はない。
- 定義と実態: 「出力対象=承認済み・締め済み」は給与連携(CSV・外部連携)の前提としては正しい。Excelは目視確認・提出用の帳票
  (`AttendanceExportPage.tsx:177`)で、承認前の確認にも使われる実態に対し、CSVと同じ抽出条件を共有していることがずれの原因。
- 提出済み以降は日次がロックされる(`AttendanceEditGuard`)ため、提出済みの月の日別行はスナップショットと同じ時点の内容。
  スナップショットは提出時に必ず記録される(`SubmitAttendanceMonthHandler.php:58,63`→`AttendanceMonthProjector.php:27-39`)。
  ただしビルダーは`snapshot_json ?? []`で読むため、移行データでnullの行があれば集計欄が0になる。本番で提出済み・承認済みの
  `snapshot_json`がnullの行が無いことをリリース後に確認する(検証方法)。
- 差戻し(returned)ではスナップショットは消えない(`AttendanceMonthProjector.php:52-58`)。差戻し中の月を対象外にするのは状態で判定する。
- 原因の分類: 実装のミス(画面共通化でボタンを出す条件と、APIの抽出条件が食い違った)。設計原則に関わる誤りはない。

## 仕様検討

### 論点1: Excelの出力対象に提出済みを含めるか
- 選択肢:
  - A. Excelだけ提出済み(submitted)も対象にする。CSV・外部連携は承認済み・締め済みのまま。
  - B. Excel・CSVとも提出済みを対象にする(給与連携に未承認の月が混ざる)。
  - C. 承認前は出力させない(ボタンを無効化し、APIは0件ならエラー)。
- 決定: A(2026-10-15)→ 2026-10-16 にユーザー決定で変更: CSVもExcelと同じ条件(月次勤怠の画面から1か月単位で出力する
  ときだけ提出済みも対象)。外部連携(freee等へのAPI送信)と一括出力は承認済み・締め済みのまま。
- 理由: ユーザー決定。「未承認で出力できても問題ない」(2026-10-16)。1か月単位の画面ではExcel・CSVを同じ条件にそろえる。
  外部連携は外部システムへのデータ送信で、ユーザーの指示の対象(画面のCSV)ではないため変えない。
- 未確定・要確認事項: なし

### 論点2: Excelの対象に含める状態の範囲
- 選択肢:
  - A. submitted・approved・closed(差戻し returned・未提出 not_submitted は含めない)
  - B. 全状態
- 決定: A
- 理由: 提出済み以降はスナップショットがあり、承認者が見る対象。差戻し・未提出はスナップショットが確定値でなく、承認者の確認対象でもない。
- 未確定・要確認事項: なし

### 論点3: 管理画面の一括出力(勤怠出力画面・複数社員/複数月のZIP)にも適用するか
- 選択肢:
  - A. 同じAPIなので一括出力のExcelにも提出済みの月が含まれる
  - B. Excel APIに明示的なパラメータ(`include_submitted`)を足し、月次勤怠の「出力」カード(社員1名×1か月)からだけ渡す。
    一括出力は現行どおり承認済み・締め済みのみ
  - C. Aに加え、未承認の月のシート・ファイル名に「未承認」を表示する
- 決定: B
- 理由: 一括出力のExcelは締め工程の帳票(`docs/07-usecases-attendance.md:245,256`)として外部へ提出され得る。未承認の月が
  区別なく混ざると、差戻し前の古い版が出回り、同じ条件のCSVと件数も合わなくなる(設計レビューの指摘)。承認前の確認は
  1か月単位の画面で足りる。
  (初版はAだったが、設計レビューを受けてBに変更。レビュー履歴参照)
- 未確定・要確認事項: なし

### 論点4: 出力できない状態の月の出力ボタン
- 選択肢:
  - A. 変えない(押すと空のファイルになる)
  - B. CSVだけ、承認済み・締め済みでない月で無効にする(Excelは状態によらず押せる)
  - C. CSV・Excelそれぞれ、APIが対象にする状態のときだけ押せるようにし、それ以外は無効にして理由を表示する
- 決定: C(2026-10-16の論点1の変更により、CSV・Excelの押せる状態は同じ submitted・approved・closed になる)
- 理由: 差戻し中・未提出(申請取消・確定取消の後も月の行は残る)の月でも「出力」カードは出るため、Bでは同じ「空のファイル」が
  Excelで残る(設計レビューの指摘)。押せる状態はbackendの抽出条件と同じ値をフロントエンドの定数1か所にまとめる。
  (初版はB。レビュー履歴参照)
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend(`ExportController`):
  - (2026-10-16追加)`GET /exports/attendance`(CSV)にも同じ`include_submitted`を追加し、Excelと同じ抽出条件にする。
    外部連携(`attendanceExternalPublish`)は受け付けない・変えない。
  - `GET /exports/attendance.xlsx`に任意のクエリ`include_submitted`(boolean、既定false)を追加する。trueのとき出力対象の月次を
    `status IN (submitted, approved, closed)`、falseまたは未指定なら現行どおり`approved, closed`で抽出する。単一・ZIPとも同じ。
  - 外部連携(`attendanceExternalPublish`)は変えない(`include_submitted`は受け付けない)。(初版はCSVも変えない決定だったが2026-10-16に変更)
  - `resolveAttendanceMonths`は対象状態の配列を引数で受け取る。状態の値は`AttendanceMonthStatus`の定数を使う。
  - 出力履歴(`export.created`)の`filters`には検証済みの入力をそのまま残す(`include_submitted`も記録される)。
  - OpenAPI属性(`ExportController.php:100-102`付近)に`include_submitted`を追加し、`:100-102`・`:675-677`のdocblockの
    対象状態の記述を更新する。
  - 対象0件のときの挙動(タイトルだけのシート)は変えない。
- frontend:
  - `api/exports.ts`の`downloadAttendanceExcel`のフィルタ型に`include_submitted?: boolean`を追加し、trueのときクエリに付ける。
  - 押せる状態の定数を1か所(`utils/statusLabels.ts`または`api/types.ts`の月次状態の近く)に置く:
    Excel・CSV(1か月単位)とも`submitted`・`approved`・`closed`(2026-10-16にCSVもそろえたため定数は1つにまとめる)。
  - `AttendanceReferencePage.tsx`の「出力」カード: Excel出力・CSV出力とも`include_submitted: true`を付けて呼ぶ。月次の状態が
    定数に含まれないときは両方のボタンを`disabled`にし、カード内に「提出後に出力できます。」を表示する。
  - `api/exports.ts`の`downloadAttendanceCsv`にも`include_submitted?: boolean`(trueのときだけクエリに`include_submitted=1`)。
    勤怠出力画面(`AttendanceExportPage.tsx`)のCSV呼び出しには付けない。
  - 勤怠出力画面(`AttendanceExportPage.tsx`)の呼び出しは変えない(`include_submitted`を付けない)。
- テスト(Feature、`backend/tests/Feature/Attendance/AttendanceExportTest.php`に追加):
  - `include_submitted=1`で提出済みの月のExcelに31日分の日別行と集計(スナップショット由来)が出る
  - `include_submitted`無しでは提出済みの月はExcelの対象外(タイトルだけ。一括出力の現行どおり)
  - `include_submitted=1`でも、スナップショットを持つ差戻し中の月と未提出の月は対象外
  - (2026-10-16)CSVも`include_submitted=1`で提出済みの月が出力され、差戻し・未提出は対象外。`include_submitted`無しでは
    提出済みは対象外(既存テスト`AttendanceExportTest.php:178-193`のまま)。外部連携は`include_submitted`を付けても提出済みを含めない。
- テスト(frontend、`AttendanceReferencePage.test.tsx`): 提出済み・承認済み・締め済み=両方有効・案内文なし、
  差戻し・未提出=両方無効+「提出後に出力できます。」、Excel・CSV出力の呼び出しに`include_submitted: true`が付く。
  `api/exports.test.ts`にCSVのクエリ組み立てを追加。

## 受け入れ条件
- 勤怠出力権限を持つ利用者が、承認待ち(提出済み)の月次勤怠の申請詳細画面で「Excel出力」を押すと、31日分の行と集計を持つ
  勤怠管理表がダウンロードされる。
- 勤怠出力画面の一括出力(Excel・CSV)の対象は変わらない(承認済み・締め済みのみ)。
- 差戻し中・未提出の月では、出力ボタンが無効になり理由が表示される。
- 承認済み・締め済みの月のExcel・CSVの出力内容は変わらない(既存の`AttendanceExportTest`が全てPASS)。
- 提出済みの月はCSV・外部連携の対象にならない(テストでPASS)。
- 承認待ちの月の「出力」カードでCSV出力も押せ、提出済みの月のCSVが出力される(2026-10-16変更)。
- 本番で提出済み・承認済みの`snapshot_json`がnullの月次が無い(2026-10-16 ユーザー確認: 0件)。
- backend・frontendの関連テストが全てPASSする。

## 対象外
- 有給の0.5の件(ユーザー指示で保留)。
- 休暇の作り直し(論点18)前の本番データに起因する値の差。
- 承認前のExcelに「未承認」等の表示を入れること。
- 対象0件のときに空シートではなくエラーを返すこと。
- 有給残日数の算出で取消済みの消化を除外していない・複数付与にまたがる消化が漏れる既存の問題(調査で判明。別の変更セットで扱う)。
- 半休の日の遅刻・早退の判定。
- 一般の承認者(勤怠出力権限なし)への出力ボタンの開放。
- 出力APIで勤怠出力権限の範囲(group)によって対象社員を絞っていない既存の問題(別の変更セットで扱う)。

## ドキュメントへの影響
- `docs/14-usecases-export.md`: 「Excel出力(UC-E001 拡張)」(`:3-7`)に`include_submitted`(月次勤怠の画面から1か月単位で
  出力するときだけ提出済みも対象)を追記する。`:35-36`に、承認待ちの月はExcelだけ出力でき、CSVは承認後である旨と、出力できない
  状態ではボタンが無効になる旨を追記する。`:130-134`の対象状態の記述も合わせる。
- `docs/07-usecases-attendance.md:256`: 給与計算用CSV/帳票(一括出力)は承認済み以降、承認前の確認用に月次勤怠の画面から
  提出済みの月のExcelを出力できる旨を書き分ける。

## モック・アセット
なし

## 実装対象
- `backend/app/Http/Controllers/Api/ExportController.php`
- `backend/tests/Feature/Attendance/AttendanceExportTest.php`
- `frontend/src/api/exports.ts`、押せる状態の定数(`frontend/src/utils/statusLabels.ts`等)
- `frontend/src/pages/attendance/AttendanceReferencePage.tsx`・`AttendanceReferencePage.test.tsx`
- `frontend/src/pages/attendance/AttendanceExportPage.tsx`(`:80`のコメント・`:111`の案内文の対象状態の記述のみ確認・更新)
- `docs/14-usecases-export.md`、`docs/07-usecases-attendance.md`

## 検証方法
- `cd backend && php artisan test tests/Feature/Attendance/AttendanceExportTest.php`
- `cd backend && php artisan test --filter Export`
- `cd frontend && npx vitest run src/pages/attendance/AttendanceReferencePage` と `npm run lint`・`npx tsc --noEmit`
- リリース後、本番DBで`SELECT COUNT(*) FROM attendance_months WHERE status IN ('submitted','approved','closed') AND snapshot_json IS NULL`
  が0であること(ユーザーに依頼)

## レビュー履歴
- 初版(2026-10-15)
- 2026-10-15 ユーザーが承認(「実装をお願いします」)。実装中に更新
- 2026-10-16 実装後のユーザー指示「CSVもExcelと同じ条件にしてください。未承認で出力できても問題ないです。」で論点1・4・
  仕様確定事項・受け入れ条件を変更(CSVにもinclude_submitted、押せる状態の定数を1つに、案内文を1種類に)。外部連携・一括出力は
  変えない。設計前提は覆っていない。本番のsnapshot_jsonがnullの月次は0件(ユーザー確認)。
- 2026-10-15 独立設計レビュー(general-purpose)の指摘を反映:
  - major: 論点4はExcelも状態で無効化しないと差戻し・未提出で同じ不具合が残る → 決定をB→Cに変更。
  - major: 論点3で一括ZIPに未承認の月が無表示で混ざる(締め工程の帳票、CSVと件数不一致) → 決定をA→Bに変更
    (`include_submitted`パラメータを1か月単位の画面からだけ渡す)。docs/07:256も更新対象に追加。
  - minor: 対象者(勤怠出力権限を持つ利用者)を背景・受け入れ条件に明記。権限の範囲(group)の既存問題を対象外に明記。
  - minor: docblock・OpenAPI・AttendanceExportPageのコメント/案内文・docs/14の他の箇所を実装対象・ドキュメントへの影響に追加。
  - minor: スナップショットがnullの行の確認を検証方法に追加。差戻し中(スナップショットあり)の月が対象外のテストを追加。
  - 前提(提出時のスナップショット・日次ロック・ビルダーの状態非依存・呼び出し元3か所)は覆っていないため作り直しは不要。

## 実装結果
- backend・docs(implementer委譲、コミット「Excel勤怠管理表の出力でinclude_submittedのとき承認待ちの月も対象にする」):
  `ExportController.php`(`include_submitted`、`resolveAttendanceMonths`に対象状態を引数で渡す、OpenAPI・docblock)、
  `AttendanceExportTest.php`(4件追加。15→19件)、`docs/14-usecases-export.md`、`docs/07-usecases-attendance.md`。
  クエリの`"true"`/`"false"`はLaravelのbooleanルールが受けないため検証前に真偽値へ正規化(フロントエンドは`1`を送る)。
- frontend(implementer委譲、コミット「承認待ちの月の出力カード…」): `api/exports.ts`(`AttendanceExcelExportFilters`)、
  `hooks/useAttendance.ts`、`utils/statusLabels.ts`(押せる状態の定数2つ)、`AttendanceReferencePage.tsx`(Excelに
  `include_submitted: true`、状態でボタン無効化+案内文)、テスト(`AttendanceReferencePage.test.tsx`に6件、`api/exports.test.ts`新規3件)。
  `AttendanceExportPage.tsx`は一括出力の記述と矛盾しないため変更なし。
- 検証(委譲元が実行):
  - `php artisan test tests/Feature/Attendance/AttendanceExportTest.php`: 19件PASS
  - `php artisan test --filter "Export|Swagger"`: 50件PASS
  - `npx vitest run --project=unit src/pages/attendance/AttendanceReferencePage src/api`: 4ファイル24件PASS
  - `npx tsc -b`・`npm run lint`: エラーなし
  - storybookプロジェクトの`AttendanceReferencePage.stories.tsx > No Employee Selected`が失敗(Routerのdecorator無し)。
    今回の変更を外した状態でも同じく失敗する既存の不具合で、対象外。
- 受け入れ条件: 本番の`snapshot_json`がnullの月次は0件(2026-10-16 ユーザー確認)。
- 2026-10-16 CSVもExcelと同じ条件にする変更(implementer委譲、コミット「勤怠CSVも月次勤怠の画面からは承認待ちの月を出力できるようにする」):
  `ExportController.php`(`validateAttendanceDownloadRequest`・`resolveExportableStatuses`をCSV・Excelで共有。外部連携は不変)、
  `AttendanceExportTest.php`(CSVの1件追加、20件)、`AttendanceExternalApiPublishTest.php`(外部連携は`include_submitted`を
  無視する1件追加)、frontend(押せる状態の定数を`ATTENDANCE_MONTH_EXPORTABLE_STATUSES`に統合、CSVにも`include_submitted`、
  案内文を「提出後に出力できます。」に統一、テスト更新・`exports.test.ts`にCSV3件)、docs/14・docs/07。
  - 検証(委譲元が実行): `php artisan test --filter "Export|Swagger|ExternalApiPublish"` 59件PASS、
    `npx vitest run --project=unit src/pages/attendance/AttendanceReferencePage src/api` 27件PASS、`npx tsc -b`・`npm run lint` エラーなし。
- 整合チェック: 「ドキュメントへの影響」のdocs/14・docs/07は更新済み(git差分で確認)。受け入れ条件は各テストと本番確認で充足。
  未確定・要確認事項は全論点で空。
