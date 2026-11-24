# leave-conflict-and-docs(差異チェックの取消済み有給の誤警告と、有給の消化一覧・社員の手動作成の仕様書の食い違い)

ステータス: 完了

## 変更要望(原文)
変更候補の判断 `docs/changesets/candidates-20261124.md` の #15(A・B・C)。調査結果をもとに、ユーザーが次のように回答した(2026-11-24)。
- A「申請中も警告する」
- B「今のまま含める」
- C「残して docs を直す」

## A. 勤怠報告の取込の差異チェック(不具合)
- 原因: `AttendanceDifferenceDetector.php:80-84` の `LEAVE_CONFLICT` は、出勤時刻のある日に有給の消化の行があるかを、`is_cancelled` を見ずに調べている。
  - 有給の消化の行は、取り消すと削除されずに `is_cancelled=true` で残る。そのため、取り消した有給の日に出勤した報告で、誤って警告が出る。
  - 特別休暇の行は、取り消すと削除されるので、この問題は無い。
- 決定:
  - 取り消した有給(`is_cancelled=true`)は、衝突として扱わない。
  - 申請中(`is_confirmed=false`)も、確定済みも、警告する。警告の文を書き分ける。
    - 確定済み: 「有給休暇の取得日と勤務時間が重なっています。」
    - 申請中: 「申請中の有給休暇と勤務時間が重なっています。」
  - 特別休暇も同じく書き分ける(「特別休暇」)。今の文は実装に合わせて確かめる。
  - 重さは今のまま警告(warning)。`docs/26:209` の例(REJECTED・「有給申請と勤務時間が重複」)を、実装と今回の決定に合わせて直す。
- テスト:
  - 判定は単体テストで、`detect()` を直接呼ぶ(`tests/Unit/AttendanceImport/`)。次を確かめる。
    - 取消済みの有給: 警告しない。
    - 申請中・確定済みの有給: 警告し、文が違う。
    - 出勤時刻の無い日: 警告しない。
    - 特別休暇。
  - プレビュー API(`AttendanceImportPreviewTest`)で、取消済みの有給の日の警告が出ないことを1件確かめる。
  - 修正の前に、取消済みのケースが失敗することを確かめる。
- 影響範囲: プレビュー API(`AttendanceImportPreviewController`)だけ。MCP の `preview_attendance_import` はこの API を呼ぶ(docs/26)。

## B. 社員ごとの有給の消化の一覧(仕様の明記)
- 決定: 今の動き(申請中も `request_status` 付きで返し、取り消したものは返さない)を正とする。docs/09 UC-P009 と、API の説明(OpenAPI の summary・description)に書く。
- テスト: Feature テストで次を確かめる。既存のテストがあれば足す。
  - 申請中の行が `request_status=submitted` で返る。
  - 取り消した行は返らない。

## C. 社員の手動作成(仕様書を直す)
- 決定: 社員一覧からの手動作成(`POST /users`、権限 `user.create`、`user.created_manually`)を、正式な機能として残す。
  - 用途: 外部連携(MS365同期・SSO)を使わない利用者(協力会社社員など)。
- docs を直す箇所:
  - `docs/15-usecases-admin.md:22-24`(UC-M001 の「手動で作成するフローは無い」)
  - `docs/06-usecases-auth.md:155`
  - `docs/13-usecases-notification.md:63`
  - 手動作成を作成の経路の1つとして書く(権限、入力項目、所属の ALL_USERS への追加、タイムゾーンは記録時の既定値)。

## 対象外
- 差異チェックのほかの規則
- 消化の一覧の画面の変更

## 実装結果
- A:
  - 取消済みの有給を除外した。確定済みと申請中で、警告の文を書き分けた(特別休暇も同じ)。
  - 単体テスト8件を足した(`tests/Unit/AttendanceImport/AttendanceDifferenceDetectorLeaveConflictTest.php`)。修正前は、取消済みの2件が失敗することを確かめた。プレビュー API にも1件足した。
  - docs/26 の例を、警告(warning)と新しい文に直した。
  - 挙動の変化: 同じ日に有給と特別休暇の両方があると、警告が2件になる(以前は1件)。
  - mcp に古い文を前提にした箇所は無い。
- B: 申請中は `submitted` で返り、取消済みは返らないことを Feature テストで確かめた。OpenAPI と docs/09 UC-P009 に明記した。
- C: docs/15・docs/06・docs/13 に、手動作成(権限・入力・所属・タイムゾーン)を作成の経路として書いた。

