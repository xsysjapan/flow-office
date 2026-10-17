# pint-format

- ステータス: 完了(2026-10-17 ユーザー決定)

## 変更要望(原文)

> pint違反の整形(AttendanceExportTest.phpなど既存のpint違反を整形する)

## 現状(As-Is)

- `cd backend && vendor/bin/pint --test`はリポジトリ全体で82ファイルが失敗する(このブランチの変更前から)。
- CI(`.github/workflows/`)はpintを実行していない。
- このブランチで触った`tests/Feature/Attendance/AttendanceExportTest.php`は違反が残っている(`ExportController.php`は整形済み)。

## 仕様検討

### 論点1: 範囲
- 選択肢: A) このブランチで触ったファイルだけ(`AttendanceExportTest.php`・`AttendanceExcelBuilder.php`・`PaidLeaveGrant.php`・`ExportController.php`) /
  B) backend全体の82ファイル
- 決定: B(2026-10-17 ユーザー決定)。整形だけのコミットに分けて同じPRに入れる。

### 論点2: 方法
- 決定: `vendor/bin/pint <ファイル>`による機械的な整形だけ。手での書き換えはしない。整形の後に`php artisan test --filter Export`
  (Bなら全テスト)で挙動が変わらないことを確認する。整形だけのコミットに分ける。

## 対象外

- CIへのpintの追加(必要なら別途)。

## 結果

- `vendor/bin/pint`(pint.jsonなし=既定のlaravelプリセット)で82ファイルを整形(195行追加・181行削除)。backend/以外の変更なし。
- `vendor/bin/pint --test`: passed。
- `php artisan test`: 1855件すべてPASS。
