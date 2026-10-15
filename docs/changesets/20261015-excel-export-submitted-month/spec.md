# 承認待ち(提出済み)の月次勤怠でExcel勤怠管理表がタイトルだけになる不具合の修正

ステータス: 検討中

## 変更要望(原文)
> Excelの集計表もおかしくなっています。履歴を調べて対応をお願いします。

(確認のやり取り)
> 月次で出力される単票形式(31日分の行付き)のはずがタイトルしか表示されていない
> (承認待ちの提出済み作業報告書のWeb画面)
> 修正方針: 提出済み(承認待ち)も出力可

## 背景・目的
承認者が承認待ちの月次勤怠(申請詳細画面)で「Excel出力」を押すと、31日分の行がある勤怠管理表ではなく
タイトルだけのシートがダウンロードされる。承認前に勤怠管理表で内容を確認できるようにする。

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
- 原因の分類: 実装のミス(画面共通化でボタンを出す条件と、APIの抽出条件が食い違った)。設計原則に関わる誤りはない。

## 仕様検討

### 論点1: Excelの出力対象に提出済みを含めるか
- 選択肢:
  - A. Excelだけ提出済み(submitted)も対象にする。CSV・外部連携は承認済み・締め済みのまま。
  - B. Excel・CSVとも提出済みを対象にする(給与連携に未承認の月が混ざる)。
  - C. 承認前は出力させない(ボタンを無効化し、APIは0件ならエラー)。
- 決定: A
- 理由: ユーザー決定(2026-10-15)。承認者が承認前にExcelで確認できる。給与連携は承認後のデータだけという前提を崩さない。
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
  - B. 画面ごとに条件を変える(パラメータを追加)
- 決定: A
- 理由: Excelは目視確認・提出用の帳票で、一括出力でも承認待ちの月を確認できて困らない。パラメータを増やすより単純。
  CSVは変えないため給与連携に影響はない。
- 未確定・要確認事項: なし

### 論点4: 承認待ちの月のCSV出力ボタン
- 選択肢:
  - A. 変えない(押すとヘッダーだけのCSVになる)
  - B. 承認済み・締め済みでない月はCSV出力ボタンを無効にし、「CSVは承認後に出力できます」と表示する
- 決定: B
- 理由: Excelと同じ「押すと空のファイルになる」混乱を防ぐ。APIの条件は変えない(論点1)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend: `ExportController`のExcel出力(`attendanceExcel`、単一・ZIPとも)だけ、出力対象の月次を
  `status IN (submitted, approved, closed)`で抽出する。CSV(`attendance`)・外部連携(`attendanceExternalPublish`)は
  現行どおり`approved, closed`。`resolveAttendanceMonths`に対象状態を引数で渡す形にし、抽出条件の定数は
  `AttendanceMonthStatus`の値を使う。
- 対象0件のときの挙動(タイトルだけのシート)は変えない。
- frontend: `AttendanceReferencePage.tsx`の「出力」カードで、月次の状態が`approved`・`closed`以外のときCSV出力ボタンを
  `disabled`にし、カード内に「CSVは承認後に出力できます。」を表示する。Excel出力ボタンは状態によらず押せる(現行どおり)。
- テスト(Feature、`backend/tests/Feature/Attendance/AttendanceExportTest.php`に追加):
  - 提出済みの月のExcelに31日分の日別行と集計(スナップショット由来)が出る
  - 差戻し・未提出の月はExcelの対象外(タイトルだけ)
  - 提出済みの月はCSVの対象外(現行どおり)
- テスト(frontend、`AttendanceReferencePage`のテスト): 提出済みの月でCSV出力が無効+案内文、Excel出力は有効。承認済みでは両方有効。

## 受け入れ条件
- 承認待ち(提出済み)の月次勤怠の申請詳細画面で「Excel出力」を押すと、31日分の行と集計を持つ勤怠管理表がダウンロードされる。
- 承認済み・締め済みの月のExcel・CSVの出力内容は変わらない(既存の`AttendanceExportTest`が全てPASS)。
- 提出済みの月はCSV・外部連携の対象にならない(テストでPASS)。
- 承認待ちの月の「出力」カードでCSV出力ボタンが無効になり、理由が表示される。
- backend・frontendの関連テストが全てPASSする。

## 対象外
- 有給の0.5の件(ユーザー指示で保留)。
- 休暇の作り直し(論点18)前の本番データに起因する値の差。
- 承認前のExcelに「未承認」等の表示を入れること。
- 対象0件のときに空シートではなくエラーを返すこと。
- 有給残日数の算出で取消済みの消化を除外していない・複数付与にまたがる消化が漏れる既存の問題(調査で判明。別の変更セットで扱う)。
- 半休の日の遅刻・早退の判定。

## ドキュメントへの影響
- `docs/14-usecases-export.md`: 「Excel出力(UC-E001 拡張)」の「同じ対象月抽出ロジック」を、Excelは提出済み・承認済み・締め済み、
  CSV・外部連携は承認済み・締め済みと書き分ける。`:35-36`の記述に、承認待ちの月はExcelだけ出力でき、CSVは承認後である旨を追記する。
- `docs/07-usecases-attendance.md`: 変更なし(給与計算用CSV/帳票は承認済み以降の記述のままで矛盾しない)。

## モック・アセット
なし

## 実装対象
- `backend/app/Http/Controllers/Api/ExportController.php`
- `backend/tests/Feature/Attendance/AttendanceExportTest.php`
- `frontend/src/pages/attendance/AttendanceReferencePage.tsx`(と同ディレクトリの既存テスト)
- `docs/14-usecases-export.md`

## 検証方法
- `cd backend && php artisan test tests/Feature/Attendance/AttendanceExportTest.php`
- `cd backend && php artisan test --filter Export`
- `cd frontend && npx vitest run src/pages/attendance/AttendanceReferencePage` と `npm run lint`・`npx tsc --noEmit`

## レビュー履歴
初版

## 実装結果
未着手
