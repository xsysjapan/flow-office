# 14. CSV出力ユースケース

## Excel出力(UC-E001 拡張)

`GET /exports/attendance.xlsx` で、同じ対象月抽出ロジック・権限チェックのまま、勤怠実績を
見た目を整えた`.xlsx`(社員1名・1か月につき「勤怠管理表」1シート)として出力できる
(`ExportController::attendanceExcel()` / `App\Domain\Export\Services\AttendanceExcelBuilder`)。

勤怠管理表はA4縦・1ページに収まり、用紙の水平・垂直方向の中央へ配置する印刷設定とし、上部に対象年月・社員番号・勤務形態・所属部署・
氏名・月次集計、その下に1日から月末までの始業・終業・所定内・時間外・休憩・遅刻・早退・欠勤・
備考と月間合計を配置する。休日行と見出しは薄いグレーで区別し、帳票内の文字色はすべて黒とする。
遅刻・早退は公開済み勤務予定と実績時刻を比較して判定する。社員番号は`users.employee_number`を
優先し、未設定時だけ内部UUIDを表示する。月次集計の有給残日数は、対象月末までに付与され、同日時点で
有効な付与日数から、対象月末までに確定した消化日数を差し引いて算出する。
Excelのファイル名は`勤怠管理表_{社員名}_{YYYY年MM月}.xlsx`とし、社員名に含まれる半角・全角の
空白およびファイル名に使用できない記号は除去する。複数対象のZIP内ファイルにも同じ規則を適用する。
ZIP自体は、単一社員なら`勤怠管理表_{社員名}_{対象期間}.zip`、複数社員なら
`勤怠管理表_{対象期間}.zip`とする。

- 対象社員が1名(または対象月次が0件)の場合: 単一の`.xlsx`をそのまま返す。
  出力履歴は`export.created`イベントに`exportType: 'attendance_xlsx'`として記録する。
- 対象社員が2名以上の場合: 各社員ごとに勤怠管理表1シートを持つ`.xlsx`を生成し、
  1つの`.zip`にまとめて返す(`ExportController::buildAttendanceExcelZip()`)。
  出力履歴は`exportType: 'attendance_xlsx_zip'`として記録する。

## UC-E001: 勤怠CSVを出力する

1. 管理者が対象月を選択する(複数月をまとめて指定できる)
2. 対象社員を絞り込む(複数社員を指定できる。未指定の場合は全社員が対象)
3. 出力フォーマットを選択する(下記「CSV出力フォーマット」参照)
4. CSV出力する
5. 出力履歴を記録する

月次勤怠確認バックオフィスタスクの詳細画面から出力する場合は、タスクの`source_id`に対応する
社員・対象月を自動指定するため、利用者による対象条件の再入力は不要とする。締め前の承認済み・
締め済みのどちらからもCSV・Excelを出力できる。

`year_month`クエリパラメータは配列(`year_month[]=2026-06&year_month[]=2026-07`)で複数月を
指定できる(単一の`year_month=2026-06`形式も後方互換で受け付ける)。複数月×複数社員の
month×userの組み合わせがそのままCSVの行になる。Excel出力(`.xlsx`)も同様に複数月を渡せ、
対象の組み合わせが2件以上ならZIPにまとめて返る(下記「Excel出力」参照)。

### CSV出力フォーマット

`GET /exports/attendance`の`format`クエリパラメータ(省略時`generic`)で、給与計算ソフトの
仕様に合わせた出力形式を選べる(`App\Domain\Export\Services\AttendanceCsv\
AttendanceCsvFormat`実装群、`ExportController::resolveAttendanceCsvFormat()`)。
仕様が公開されている給与計算ソフト向けには専用フォーマットを用意し、仕様が非公開・
取込時にユーザー側で列や区切り文字を設定する方式のソフト向けには汎用フォーマットを
用意する(給与奉行クラウド等、取込時の列マッピングが柔軟なソフトは専用フォーマットを
作らず汎用フォーマットで代替する)。

| format | 内容 | 想定する取込先 |
|---|---|---|
| `generic`(既定) | カンマ区切り・UTF-8・分単位の整数 | 汎用(後方互換。既存の`attendance_{year_month}.csv`) |
| `generic_tsv` | タブ区切り・日本語見出し・時:分のコロン表記 | 弥生給与Next・給与奉行クラウド等、取込時に区切り文字・時刻表記を設定できるソフト |
| `generic_sjis` | `generic`と同じ列・値だがShift-JIS | 文字コードにShift-JISを要求するレガシーなソフト |
| `moneyforward` | マネーフォワードクラウド給与B形式を模した列構成(Version列・小数点時間表記) | マネーフォワードクラウド給与 |
| `freee` | freee人事労務の勤怠サマリー形式を模した列構成(分単位) | freee人事労務 |

いずれのフォーマットも「従業員番号」列には`users.id`(UUID)を出力する(`users`テーブルに
社員番号専用のフィールドが無いため)。取込先ソフト側の社員番号と一致しない場合は、
事前にマッピングを確認する必要がある(フロントエンドの出力画面にもこの注記を表示する)。
`moneyforward`形式は34項目の勤怠項目を平日/所定休日/法定休日別に出力する
(`MoneyForwardAttendanceCsvFormat`)。出勤日数・欠勤日数・所定/所定外/法定外/深夜時間の各項目は
`MonthlyOvertimeCalculator::calculateCategoryTotals()`が算出した実データを使うが、以下は現状の
勤怠計算エンジンに区分ロジック・集計値が無いため0固定としている(将来対応。
.claude/skills/attendance-calc-review参照):
- 遅刻回数・早退回数・遅刻時間・早退時間(`AttendanceCalculator`に遅刻・早退の判定ロジックが無い)
- 休憩時間の内訳5項目(`attendance_breaks`が休憩の区分を持たない)
- 代休取得日数・代休取得時間数(今回のスコープでは集計しない)

また、法定休日の労働は所定内/所定外に分解しない設計(法定休日に「所定」の概念が無いため)
のため、「所定時間(法定休日)」「深夜所定時間(法定休日)」「所定外時間(法定休日)」は常に0になる。

`moneyforward`形式の項目追加前に提出済み/承認済み/締め済みとなった月次勤怠は、
`snapshot_json`に新項目の集計値を持たない(出力時は0扱いになる)。
`attendance:recalculate-month-snapshots`コマンドで、対象月の日次実績(提出時にロックされ
変更されない)から現在の集計ロジックで再計算し、`snapshot_json`を更新できる(差戻し中
(returned)・未提出は対象外。1回限りの手動実行を想定)。

## UC-E002: 経費CSVを出力する

1. 経理担当者が対象期間を選択する
2. 承認済み・支払予定の精算を抽出する
3. CSV出力する(`format`パラメータで`generic`(既定)・`moneyforward`・`freee`を切り替えられる)
4. 出力履歴を記録する

### 経費CSV出力フォーマット

勤怠CSV(UC-E001)と同じ`ExpenseCsvFormat`インターフェースの形で、経費側にも
`GenericExpenseCsvFormat`・`FreeeExpenseCsvFormat`・`MoneyForwardExpenseCsvFormat`の3実装を
用意する(`ExportController::resolveExpenseCsvFormat()`)。`generic`は従来のタスク単位の出力
(後方互換)、`freee`・`moneyforward`は明細(ExpenseItem)単位の仕訳行を出力し、
`expense_categories.account_code`(勘定科目コード)・`tax_category`(税区分)をそのまま
借方勘定科目・税区分として使う(値の妥当性検証・変換は行わず、取込先ソフト側の設定に委ねる)。

### 経費証跡アーカイブExcel

`GET /exports/expenses.xlsx`(`ExportController::expensesExcel()`)は、承認済み・支払予定/完了の
経費精算を対象に、`ExpenseExcelBuilder`で証跡アーカイブExcelを生成する。
- 明細一覧シート: 月次・申請者ごとに1シート(改ページ)。列は
  No/日付/区分/内容/支払先/金額(`reimbursement_amount`)/証憑No/合計。
- 証憑シート: 添付ファイル(`Attachment`, owner_type='expense_item')を貼付する。画像
  (jpeg/png/gif/webp)はそのまま貼付するが、PDFラスタライズに必要なライブラリ(Imagick等)を
  `backend/composer.json`に追加していないため、PDF証憑は画像化せずファイル名のみ記載する
  (フェーズ1の既知の制約)。

生成したExcelは`InternalArchivePublisher`(`ExternalPublisher`実装。詳細は下記)経由でローカル
ストレージへ内部保存し、`internal_archive.created`イベントとしてstored_eventsへ記録する
(冪等性キーは「対象データID+出力種別+実行回数」。`ExportAuditAggregate::idempotencyKeyFor()`)。
外部システムへは送信しない。

### 外部連携の抽象(ExternalPublisher)

勤怠・経費の外部連携出力(CSV/API/内部証跡アーカイブ)は`App\Domain\Export\Contracts\ExternalPublisher`
という共通インターフェース越しに扱う。フェーズ1で実装があるのは
`CsvFilePublisher`(ダウンロード用CSV/TSVをそのまま返す)と
`InternalArchivePublisher`(証跡アーカイブExcelをローカルストレージへ内部保存する)の2つのみ。
freee/MoneyForward等の会計クラウドへ実際にAPI送信する`ExternalApiPublisher`は型のみのスタブ
(呼び出すと例外を投げる)で、実送信ロジックの実装はフェーズ2以降とする。

## 実装上のポイント

- 出力操作は `export.created` イベント(経費の証跡アーカイブは`internal_archive.created`
  イベント)として記録し、誰がいつ何を出力したかを追跡できるようにする。
- 勤怠CSV(`ExportController::attendance()`)・経費CSV(`ExportController::expenses()`)は
  いずれも上記「CSV出力フォーマット」の通り、給与計算・会計ソフトの仕様に応じて`format`
  パラメータで出力形式を切り替えられる。
- 勤怠CSV(UC-E001)は [UC-A009 承認](./07-usecases-attendance.md#uc-a009-承認者が月次勤怠を承認する)
  済み以降であれば出力可能とする(締め前でもバックオフィス確認のためにCSV/帳票を出力できる
  必要があるため)。実装では対象月の `attendance_months` が `approved` または `closed`
  ステータスの社員のみをCSVに含める(未承認・未提出の社員は自動的に除外される)。
  締め([UC-A011](./07-usecases-attendance.md#uc-a011-月次勤怠を締める))自体はCSV出力の
  前提条件ではない。
