# 日次勤怠の編集で休暇設定(work_type)が消えないようにする

ステータス: レビュー中

## 変更要望(原文)
> 代休バッジは表示されましたが、おそらく代休が設定されていた日の労働時間を更新した際に
> ラベルが取れてしまったような気がします。代休だけでなく有給や、特別休暇もですが、
> 当該日の労働時間を編集しても休暇設定が無効にならないように対応をお願いします。

## 背景・目的
有給・特別休暇・代休を申請した日の勤怠(出退勤時刻・休憩等)を編集すると、
`attendance_days.work_type`の休暇値が消え、休暇バッジや休暇日数の集計
(`AttendanceCalculator`が`work_type`を参照)から外れてしまう。休暇の設定は休暇申請・
取消でのみ変化するようにし、労働時間の編集では変わらないようにする。

## 現状(As-Is)
- 休暇申請時に各Handlerが`work_type`を`paid_leave_*`/`special_leave_*`/
  `compensatory_leave_*`に設定する(`backend/app/Domain/PaidLeave/Handlers/RequestPaidLeaveHandler.php:188`、
  `SpecialLeave/Handlers/RequestSpecialLeaveHandler.php:165`、
  `CompensatoryLeave/Handlers/RequestCompensatoryLeaveHandler.php:154`)。取消時はnullに戻す。
- **原因1(フロント)**: 日次画面の保存で、休暇が設定されている日は`work_type: null`を送る
  (`frontend/src/pages/attendance/AttendanceDayPage.tsx:729`
  `leaveController.kind === 'none' ? workType || null : null`)。
- **原因2(バックエンド)**: 日次編集API(`backend/app/Http/Controllers/Api/AttendanceController.php`
  の更新処理)は`work_type`未送信も`?? null`でnull扱いし、`EditAttendanceDayHandler.php:83`→
  `AttendanceDayEdited`→`AttendanceDayProjector.php:56`で無条件に上書きする。
  `work_location_type`には「送信されたか」フラグ(`EditAttendanceDay::$workLocationTypeProvided`)が
  あるが、`work_type`には無い。
- 同じく`EditAttendanceDay`を使う一括パターン入力(`GeneratePatternAttendanceDaysHandler.php:114`)も
  `workType: null`を渡すため、休暇日に適用すると休暇が消える。
- 打刻同期(`AttendanceDayPunchSyncer`)は`work_type`を触らないため影響なし。
- 日次編集で休暇の`work_type`が保持されることを検証するテストは無い。

## 仕様検討

### 論点1: どこで休暇設定を守るか
- 選択肢:
  - A. フロントのみ修正(休暇日は既存の`work_type`を送り直す)。変更は最小だが、一括パターン
    入力・API・MCP経由の編集では引き続き消える。
  - B. `work_location_type`と同じ「送信されたか」フラグを`work_type`にも導入し、フロントは
    休暇日にキーを送らない。一括パターン入力やnullを明示送信するクライアントでは消える。
  - C. バックエンドの`EditAttendanceDayHandler`で、既存の`work_type`が休暇値の場合は
    リクエストの`work_type`に関わらず既存値を引き継いでイベントに記録する。
- 決定: C
- 理由: 休暇の設定は休暇ドメイン(申請・取消)の責務であり、勤怠編集から変更できるべきではない
  (CLAUDE.md原則9・14)。Handlerで守れば、日次画面・一括パターン入力・API・MCPのどの入口からの
  編集でも共通に効く。イベント(`AttendanceDayEdited`)に引き継いだ値を記録するので、
  Projectionの再生成でも編集後に休暇値が消えない。
- 未確定・要確認事項: なし

### 論点2: 休暇日の編集で休暇以外の`work_type`が送られた場合
- 選択肢:
  - A. 黙って既存の休暇値を維持する(送られた値は無視)
  - B. 422エラーで拒否する
- 決定: A
- 理由: 現行の日次画面は休暇日に`null`を送っており、Bにすると労働時間の編集自体ができなくなる。
  休暇を外したい場合は休暇申請の取消(既存の操作)を使う。
- 未確定・要確認事項: なし

### 論点3: フロントの送信値
- 選択肢:
  - A. 変更しない(バックエンドで守られるため)
  - B. 休暇日は既存の`day.work_type`を送るよう修正する
- 決定: A
- 理由: バックエンドが正しく維持するので、フロント側で値を合わせる必要はない。変更範囲を
  最小にする。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- 休暇値の判定: `work_type`が`paid_leave_`/`special_leave_`/`compensatory_leave_`の
  いずれかで始まる値を「休暇の`work_type`」とする。判定は既存の
  `SpecialLeaveWorkType::isSpecialLeaveWorkType`・`CompensatoryLeaveWorkType::isCompensatoryLeaveWorkType`
  と同様の静的メソッドを有給側(`App\Models\PaidLeaveType`に`isPaidLeaveWorkType`)にも追加し、
  3つを組み合わせて判定する。
- `EditAttendanceDayHandler`: 編集対象の`attendance_days.work_type`(編集前の値)が休暇値の場合、
  `AttendanceDayAggregate::edit()`へ渡す`workType`は編集前の値とする(コマンドの`workType`は
  無視)。休暇値でない場合は従来どおりコマンドの`workType`を使う。
- 上記により、日次編集API・一括パターン入力など`EditAttendanceDay`を使う全経路で休暇値が維持される。
- 休暇申請の取消で`work_type`がnullに戻った後の編集は、従来どおりコマンドの値が反映される。
- `status`・出退勤時刻・休憩・備考・`leave_segments`等、`work_type`以外の扱いは変えない。

## 受け入れ条件
- 有給・特別休暇・代休(全休・半休)を申請した日を日次編集API(`work_type: null`または未送信)で
  出退勤時刻を変更しても、`work_type`が申請時の休暇値のまま残り、出退勤時刻は更新される。
- 休暇日に休暇以外の`work_type`(例: 通常勤務を表す値)を送っても休暇値が維持される。
- 休暇が設定されていない日は、従来どおり送った`work_type`が反映される。
- 一括パターン入力を休暇日に適用しても休暇値が維持される。
- 既存のbackend/frontendテストが全てPASSする。

## 対象外
- 休暇申請・取消が`attendance_days`をイベントを経由せず直接更新している問題
  (Projection再生成時に休暇値が失われる。原則1・2に関わる既存の別問題として別途検討)
- 日次画面の休暇指定UI(`LeaveKind`)・送信値の変更
- `AttendanceDayPage.test.tsx`の既存`it.skip`テストの解消
- 勤怠の新規作成(`CreateAttendanceDay`)経路(休暇申請時点で勤怠日の行が作られるため、
  休暇日は常に編集経路になる)

## ドキュメントへの影響
- `docs/07-usecases-attendance.md`: 日次勤怠の編集のユースケースに「休暇が設定されている日の
  `work_type`は勤怠編集では変更されず、休暇申請の取消でのみ解除される」旨を1文追記する
  (該当UCを実装時に特定して追記)。
- その他は変更なし。

## モック・アセット
なし

## 実装対象
- `backend/app/Models/PaidLeaveType.php`(`isPaidLeaveWorkType`追加)
- `backend/app/Domain/Attendance/Handlers/EditAttendanceDayHandler.php`(休暇値の維持)
- `backend/tests/Feature/...`(日次編集・一括パターン入力で休暇値が維持されるテストを追加。
  既存の日次編集テストのファイルに追加する)
- `docs/07-usecases-attendance.md`(上記追記)

## 検証方法
- `cd backend && php artisan test`(追加テスト+既存テスト全件)
- `cd backend && vendor/bin/pint --test`(該当ファイル)

## レビュー履歴
- 2026-10-09 初版

## 実装結果
未着手
