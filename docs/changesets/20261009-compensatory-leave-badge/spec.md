# 代休を取得した勤怠日に代休バッジが表示されない不具合の修正

ステータス: 完了

## 変更要望(原文)
> 代休を取得した時に対象の勤怠に第九のバッジがでません。原因を調べて対応してください。

(「第九」は「代休」の誤変換)

## 背景・目的
代休を申請した日の勤怠行・日次画面に、有給休暇・特別休暇と同様の休暇バッジ(例:
「代休(全休)」)が表示されず、全休の日は「退勤済み」等のstatusベースの表示になっていた。
休暇日であることが勤怠画面上で判別できるようにする。

## 現状(As-Is)
- バックエンドは代休申請時点で対象日の`attendance_days.work_type`を
  `compensatory_leave_{full|am_half|pm_half|hourly}`に設定する
  (`backend/app/Domain/CompensatoryLeave/Handlers/RequestCompensatoryLeaveHandler.php`、
  接頭辞は`backend/app/Domain/CompensatoryLeave/Support/CompensatoryLeaveWorkType.php`)。
  全休では有給・特別休暇と同じく`status`を`clocked_out`にする。
- フロントの休暇ラベル判定`leaveWorkTypeLabel`
  (`frontend/src/utils/statusLabels.ts`)は`paid_leave_`/`special_leave_`接頭辞のみを
  判定しており、`compensatory_leave_`を扱っていなかった。**これが原因**。
- このラベル判定は`attendanceDayDisplayLabel`/`attendanceRowDisplayLabel`経由で
  以下の全画面から共通利用されている:
  `AttendanceDayRow`(週次・月次)、`AttendanceDayPage`(日次)、
  `TodayAttendancePanel`(今日の勤怠)、`AttendanceReferencePage`(勤怠参照)。

## 仕様検討

### 論点1: 修正箇所
- 選択肢:
  - A. 共通のラベル判定`leaveWorkTypeLabel`に`compensatory_leave_`接頭辞を追加する
    (全画面に一括で反映される)
  - B. 各画面で代休申請一覧を別途取得してバッジを出し分ける(画面ごとの実装・追加API
    呼び出しが必要になり、有給・特別休暇と表示ロジックが分かれる)
- 決定: A
- 理由: 有給・特別休暇と同じ`work_type`接頭辞方式でバックエンドが既に値を返しており、
  共通関数の1か所の修正で全画面に同じ表示が揃うため。
- 未確定・要確認事項: なし

### 論点2: 表示文言
- 選択肢:
  - A. 「代休(全休)」「代休(午前半休)」等、有給・特別休暇と同じ「種別(取得単位)」形式
  - B. 単に「代休」
- 決定: A
- 理由: 有給休暇・特別休暇の既存表示形式と揃え、半休・時間休も判別できるようにするため。
  取得単位の文言は既存の`paidLeaveTypeLabels`を再利用する(代休の取得単位は
  バックエンドでも`PaidLeaveType`の値を再利用している)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- `work_type`が`compensatory_leave_`で始まる勤怠日は、`代休(<取得単位ラベル>)`を
  tone=`info`のバッジで表示する(取得単位ラベルは`paidLeaveTypeLabels`、未知の値は
  接頭辞を除いた値をそのまま表示)。
- 判定順は有給 → 特別休暇 → 代休(接頭辞は互いに重複しないため順序による差異はない)。

## 受け入れ条件
- `work_type=compensatory_leave_full`の勤怠日が週次・月次の勤怠行で「代休(全休)」と
  表示される。
- `attendanceDayDisplayLabel`が`compensatory_leave_full`/`compensatory_leave_am_half`に
  対してそれぞれ「代休(全休)」「代休(午前半休)」(tone=`info`)を返す。
- 既存の有給・特別休暇・通常勤務の表示が変わらない(関連テストが全てPASSする)。

## 対象外
- バックエンドの`work_type`設定ロジック・勤怠計算(`AttendanceCalculator`)の変更
- 代休申請画面・代休残高表示の変更
- 半休・時間休の代休バッジの見た目の調整(既存の有給・特別休暇と同じ扱いのまま)

## ドキュメントへの影響
変更なし(表示ラベルの不具合修正であり、ユースケース・テーブル定義・イベントに変更はない)。

## モック・アセット
なし

## 実装対象
- `frontend/src/utils/statusLabels.ts`(`leaveWorkTypeLabel`に代休接頭辞を追加)
- `frontend/src/utils/statusLabels.test.ts`(代休の全休・午前半休のケースを追加)
- `frontend/src/components/AttendanceDayRow/AttendanceDayRow.test.tsx`(代休全休の行表示ケースを追加)

## 検証方法
- `cd frontend && npx vitest run --project=unit src/utils/statusLabels.test.ts src/components/AttendanceDayRow src/pages/attendance`
- `cd frontend && npm run lint`

## レビュー履歴
- 2026-10-09 初版。実装が変更セット作成・ユーザーレビューより先に行われたため
  (CLAUDE.mdの変更セット・委譲ルールの適用漏れ)、実装後に事後記録として作成した。
  ユーザーから実装内容で問題ない旨の確認を得て完了とした。

## 実装結果
- コミット: `2a69688`(main)
- 変更ファイル: 「実装対象」の3ファイル
- テスト: 上記vitest 8ファイル / 107 passed・2 skipped(skipは既存)
- lint: 今回の変更ファイルに警告なし(既存の無関係ファイルの警告のみ)
- 受け入れ条件: 3項目とも上記テスト(`statusLabels.test.ts`・`AttendanceDayRow.test.tsx`の
  追加ケースと既存ケース)で確認済み
