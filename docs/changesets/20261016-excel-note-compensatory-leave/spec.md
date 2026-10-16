# 勤怠管理表(Excel)の備考に代休が表示されない不具合の修正

ステータス: 検討中

## 変更要望(原文)
> Excelの備考に代休が反映されていません。追加をお願いします

## 背景・目的
勤怠管理表(Excel)の日別行の備考(K列)に、有給休暇・特別休暇は出るが代休が出ない。画面の勤怠表示と同じく、その日の休暇を
種類・取得単位つきで備考に出す。

## 現状(As-Is)
- 備考は`AttendanceExcelBuilder::dayNote()`(`backend/app/Domain/Export/Services/AttendanceExcelBuilder.php:322-331`)が作る。
  休暇は日次計算の`paid_leave_days > 0`なら「有給休暇」、`special_leave_days > 0`なら「特別休暇」と判定する(1日に1つだけ)。
- 日次計算は代休を日数に数えない(`LeaveCalculationInput`: 「代休(compensatory)は日数・時間休分数に数えない」、
  `docs/07-usecases-attendance.md`の`paid_leave_days`の説明)。そのため代休は備考に出る経路が無い。
- 有給・特別休暇でも、時間休だけの日(`*_leave_days`は0、分数のみ)は備考に出ない。同じ日に複数の休暇
  (午前有給+午後代休など)があっても1つしか出ない。
- 画面(勤怠の日次・週次・月次)は休暇ビュー`attendance_day_leaves`の有効な行(申請中・承認済み)から
  `attendanceDayLeaveLabels()`(`frontend/src/utils/statusLabels.ts:169-176`)で「代休(午前半休)」のように表示する。
  API側は`AttendanceDayResource::leavesOn()`が`AttendanceDayLeaves::activeFor()`を使う。
- 備考の文言を固定しているテストは無い(`AttendanceExportTest.php`)。

## 設計前提の検証
- 正データと派生データ: 休暇の有無は勤怠文脈の休暇ビュー`attendance_day_leaves`(休暇申請のイベントから作るProjection)が持つ。
  日次計算の休暇日数は給与集計用の派生値で、代休を数えない仕様。備考(表示)の判定に集計用の値を流用していたことが原因。
- 定義と実態: `paid_leave_days`・`special_leave_days`は「日数」の集計値で、休暇の種類の判定には足りない(代休・時間休を表せない)。
- 原因の分類: 実装のミス(表示に集計値を流用)。Excelは読み取りだけで、書き込み・イベントの変更は無い。

## 仕様検討

### 論点1: 休暇の判定に使うデータ
- 選択肢:
  - A. 日次計算に代休の値を足す(計算・イベント・集計に影響。代休を日数に数えない仕様と衝突)
  - B. 画面と同じく休暇ビュー(`AttendanceDayLeaves`の有効な行)から判定する
- 決定: B
- 理由: 画面と同じ正の情報源で、代休・時間休・複数休暇を表せる。計算・集計に影響しない。
- 未確定・要確認事項: なし

### 論点2: 備考の文言
- 選択肢:
  - A. 種類だけ(有給休暇・特別休暇・代休)
  - B. 画面と同じく種類+取得単位(例: 有給休暇(全休)・代休(午前半休)・特別休暇(時間休))。複数は「・」で連結
- 決定: B
- 理由: 画面の表示と一致し、半休・時間休の区別がExcelでも分かる。有給・特別の既存の表記(「有給休暇」)は
  「有給休暇(全休)」などに変わる(表記の変更として受け入れ条件に明記)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `AttendanceExcelBuilder::buildReportSheet()`で、対象月の有効な休暇を`AttendanceDayLeaves::activeForRange(user_id, 月初, 月末)`で
  1回だけ取得し、日付(Y-m-d)ごとにまとめる。
- `dayNote()`は日次計算の休暇日数を見ず、その日の休暇の行から「{種類}({単位})」を作り、複数は「・」で連結する。
  種類: paid=有給休暇、special=特別休暇、compensatory=代休。単位: full=全休、am_half=午前半休、pm_half=午後半休、hourly=時間休。
  並び順は休暇ビューの取得順(画面と同じ)。
- 備考の全体は従来どおり「祝日名 / 休暇 / 勤怠日の備考」を「 / 」で連結(空は省く)。勤怠日が無い日でも休暇があれば出す。
- 有効な休暇は申請中・承認済み(差戻し・取消は出さない。`AttendanceDayLeaves`の既存の判定)。
- 種類・単位の文言は定数としてビルダー内(またはExport文脈の小さなクラス)に置く。frontendの文言と同じ。
- テスト(`backend/tests/Feature/Attendance/AttendanceExportTest.php`に追加):
  - 代休(全休)の日の備考に「代休(全休)」
  - 午前有給+午後代休の日に「有給休暇(午前半休)・代休(午後半休)」
  - 時間休の有給の日に「有給休暇(時間休)」
  - 差し戻した代休は出ない
  - 祝日名・勤怠日の備考と「 / 」で連結される

## 受け入れ条件
- 代休を取得した日の勤怠管理表の備考に「代休(全休)」等が表示される。
- 有給・特別休暇は「有給休暇(全休)」「特別休暇(午前半休)」等、画面と同じ表記になる(従来の「有給休暇」から変わる)。
- 時間休・同じ日の複数の休暇も備考に出る。差戻し・取消の休暇は出ない。
- 集計欄・日別の時間の値は変わらない(既存の`AttendanceExportTest`が全てPASS)。
- 追加したテストがPASSする。

## 対象外
- 日次計算・月次集計で代休を日数に数えること。
- 特別休暇の種別名(誕生日休暇など)を備考に出すこと。
- 半休の日の遅刻・早退の判定。
- CSV・外部連携の出力項目。

## ドキュメントへの影響
- `docs/14-usecases-export.md`「Excel出力(UC-E001 拡張)」の備考の説明に、休暇は休暇ビューから種類・取得単位つきで出す旨を追記する。

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/Export/Services/AttendanceExcelBuilder.php`
- `backend/tests/Feature/Attendance/AttendanceExportTest.php`
- `docs/14-usecases-export.md`

## 検証方法
- `cd backend && php artisan test tests/Feature/Attendance/AttendanceExportTest.php`
- `cd backend && php artisan test --filter "Export|Swagger|ExternalApiPublish"`

## レビュー履歴
初版

## 実装結果
未着手
