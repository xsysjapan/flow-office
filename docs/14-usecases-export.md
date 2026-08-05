# 14. CSV出力ユースケース

## Excel出力(UC-E001 拡張)

`GET /exports/attendance.xlsx` で、同じ対象月抽出ロジック・権限チェックのまま、勤怠実績を
見た目を整えた`.xlsx`(月次サマリシート+日別明細シートの2シート構成)として出力できる
(`ExportController::attendanceExcel()` / `App\Domain\Export\Services\AttendanceExcelBuilder`)。

- 対象社員が1名(または対象月次が0件)の場合: 単一の`.xlsx`をそのまま返す。
  出力履歴は`export.created`イベントに`exportType: 'attendance_xlsx'`として記録する。
- 対象社員が2名以上の場合: 各社員ごとに月次サマリ+日別明細を持つ`.xlsx`を生成し、
  1つの`.zip`にまとめて返す(`ExportController::buildAttendanceExcelZip()`)。
  出力履歴は`exportType: 'attendance_xlsx_zip'`として記録する。

## UC-E001: 勤怠CSVを出力する

1. 管理者が対象月を選択する(複数月をまとめて指定できる)
2. 対象社員を絞り込む(複数社員を指定できる。未指定の場合は全社員が対象)
3. 出力フォーマットを選択する(下記「CSV出力フォーマット」参照)
4. CSV出力する
5. 出力履歴を記録する

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
`moneyforward`形式の出勤日数・欠勤日数・遅刻早退日数は、現状`attendance_months.snapshot_json`
に日数の集計値を持たないため0固定としている。

## UC-E002: 経費CSVを出力する

1. 経理担当者が対象期間を選択する
2. 承認済み・支払予定の精算を抽出する
3. CSV出力する
4. 出力履歴を記録する

## 実装上のポイント

- 出力操作は `export.created` イベントとして記録し、誰がいつ何を出力したかを追跡できるようにする。
- 勤怠CSV(`ExportController::attendance()`)は上記「CSV出力フォーマット」の通り、
  給与計算ソフトの仕様に応じて`format`パラメータで出力形式を切り替えられる。
  経費CSV(`expenses()`)は現時点では固定フォーマットのみで、フォーマット切り替えは
  未対応(後続フェーズとする)。
- 勤怠CSV(UC-E001)は [UC-A009 承認](./07-usecases-attendance.md#uc-a009-承認者が月次勤怠を承認する)
  済み以降であれば出力可能とする(締め前でもバックオフィス確認のためにCSV/帳票を出力できる
  必要があるため)。実装では対象月の `attendance_months` が `approved` または `closed`
  ステータスの社員のみをCSVに含める(未承認・未提出の社員は自動的に除外される)。
  締め([UC-A011](./07-usecases-attendance.md#uc-a011-月次勤怠を締める))自体はCSV出力の
  前提条件ではない。
