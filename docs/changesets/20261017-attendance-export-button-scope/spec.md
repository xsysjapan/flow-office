# attendance-export-button-scope

- ステータス: レビュー待ち

## 変更要望(原文)

> 出力ボタンの出し分け: グループ外の社員の月次勤怠では、グループスコープの利用者にCSV/Excel出力ボタンを出さない。

## 現状(As-Is)

- `frontend/src/pages/attendance/AttendanceReferencePage.tsx:170`: `canExport = user.effective_permissions.includes('attendance.export')`。
  `effective_permissions`は権限コードの一覧だけでスコープを持たないため、グループスコープの利用者にもグループ外の社員の出力カードが出る。
  押すと変更セット`20261017-attendance-export-group-scope`の判定で403になる。
- 出力カードは`MonthlyReferenceView`(勤怠参照・申請詳細・バックオフィスタスク詳細の3画面で共通)にあり、
  データは`GET /attendance/months/{yearMonth}?user_id=`(`AttendanceController::month`)から取る。
- 出力範囲の判定は`ExportController::attendanceExportableUserIds()`(private)にある。

## 仕様検討

### 論点1: 判定をどこで行うか
- 選択肢: A) backendが月次勤怠の取得結果に「この社員を出力できるか」を載せる / B) フロントが許可グループとメンバーを別途取得して判定する
- 決定: A
- 理由: 判定の正はbackend(出力APIの403と同じ規則)。フロントで規則を複製しない(原則9の考え方)。Bは取得が増え、子孫グループの展開も複製になる。

### 論点2: 判定の共有
- 決定: `ExportController`のprivateメソッド2つ(許可社員IDの一覧・範囲外の403)の判定部分を、
  新設の`App\Domain\Export\Services\AttendanceExportScope`に移す。
  - `allowedUserIds(User $user): ?Collection`(null=制限なし。中身は現行の`attendanceExportableUserIds`と同じ)
  - `canExport(User $user, string $userId): bool`(`attendance.export`を持たなければfalse。globalならtrue。それ以外は`allowedUserIds`に含まれるか)
  `ExportController`はこのサービスを使う(挙動は変えない)。`AttendanceController::month`もこのサービスを使う。
- 理由: 出力APIとボタンの判定を1か所にし、ずれないようにする。

### 論点3: レスポンスの形
- 決定: `GET /attendance/months/{yearMonth}`のレスポンスの最上位に`can_export: boolean`を追加する
  (`days`・`schedule`・`month`と同じ階層。対象社員=解決後の`$userId`について判定)。`AttendanceMonthResource`には入れない
  (一覧など他の用途で毎回判定が走るのを避ける)。
- 本人の月: `attendance.export`のスコープ規則どおり(本人がグループに属さなければfalse)。変更セット`20261017-attendance-export-group-scope`の決定と同じ。

### 論点4: フロントの扱い
- 決定: `api/attendance.ts`の`fetchMonth`の戻り値型に`can_export?: boolean`を追加し、`MonthlyReferenceView`の`canExport`を
  `data?.can_export ?? false`に置き換える(`effective_permissions`の`attendance.export`は見なくなる)。
- 型を任意(`?`)にする理由: 既存の多数のテスト・storyのモック(`fetchMonth`)を出力に関係ないものまで書き換えずに済ませる。
  未設定はfalse(ボタンを出さない=安全側)。出力ボタンを検証しているテストのモックには`can_export: true`を足す。

## 対象外

- 管理画面の勤怠出力画面(複数社員・複数月)は`user_id`省略で範囲内に絞られるため変えない。
- 締める・締め取消のボタン(`canCloseMonth`・`canReopenMonth`)は今回変えない。

## ドキュメントへの影響

- docs/14-usecases-export.md の「勤怠出力の権限と出力対象の社員」節に、月次勤怠の出力カードは`can_export`で表示を決める旨を1行追記。
- 月次勤怠APIのレスポンスの記述(docs/07 等で`days`・`schedule`を説明している箇所)があれば`can_export`を追記。

## テスト

- backend(`tests/Feature/Attendance/AttendanceExportTest.php`または月次勤怠APIのFeatureテスト): 
  global=true、group範囲内の社員=true、group範囲外の社員=false(閲覧自体は管理者/承認者として可能な前提を作る)、
  `attendance.export`なし=false。
- 既存の出力テスト(`--filter Export`)がサービスへの移動後も全件通る。
- frontend(`AttendanceReferencePage.test.tsx`): `can_export: false`のとき出力カードが出ない、`true`のとき出る。
  既存の出力ボタンのテスト(勤怠参照・バックオフィスタスク詳細)はモックに`can_export: true`を足して通す。
- `npx vitest run --project=unit`・`npx tsc -b --noEmit`が通る。
