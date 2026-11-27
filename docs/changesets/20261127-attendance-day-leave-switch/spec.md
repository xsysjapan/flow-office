# attendance-day-leave-switch(勤怠の日の保存で休暇を切り替える操作を、1回のCommandとイベントの連鎖で整合させる)

ステータス: 検討中

## 変更要望(原文)
> 案Cで、日の勤怠の保存も同じトランザクションに含めてください
> すみません。やっぱりAPI的に整合させてほしいですが、他に集約を跨ぐトランザクションを実装はありますか？
> Bで実装できないか確認をお願いします。勤怠の保存で申請不要なユーザーが休暇を切り替えるシナリオですね。申請が必要なユーザーは取り消しと申請で2アクションの想定です
> 今後のためにルールとしてAPI呼び出しは基本的に一コマンドのみ処理することと、改めてコレオグラフィーの整合性で実装できないか試すことをお願いします

## 背景・目的
勤怠の日の画面は「日の勤怠の保存」「元の休暇の申請の取消」「新しい休暇の申請」を別々の API で順に送っている。途中で拒否されると(入力の不足、残数不足・締め済み・重複)、前の分だけが確定し、承認済みの休暇が黙って消える(変更セット`20261127-form-error-visibility-fixes`の論点4)。
承認が不要な休暇への切替を、勤怠の保存1回(1つのCommand)で行い、休暇の取消・申請はイベントの連鎖(コレオグラフィー、原則15)で同じトランザクションにする。新しい設計原則17(API の1回の呼び出しは1つのCommand)の最初の適用になる。

## 現状(As-Is)(調査 2026-11-27、investigator)
- 画面: `frontend/src/pages/attendance/AttendanceDayPage.tsx`
  - 日の編集(`DayEditForm`)・作成(`DayCreateForm`)の保存が成功した後に、休暇の欄(`useDayLeaveController.apply()` :735-756)が、元の休暇の取消(`Cancel{X}LeaveRequest` の API)と新しい休暇の申請(`Request{X}Leave` の API)を順に送る。
- 勤怠の文脈:
  - 更新 `PUT /api/attendance/days/{day}` → `AttendanceController::updateDay`(:433-468)→ `EditAttendanceDay` → `EditAttendanceDayHandler`。締めの判定 → 時刻・休憩・遅刻早退の検証 → `AttendanceDayAggregate::edit()->persist()`(`attendance_day.edited`)→ 標準休憩の補完 → 日次の計算(`attendance_day.calculated`)。
  - 作成 `POST /api/attendance/days` → `storeDay`(:385-422)→ `CreateAttendanceDay`(`attendance_day.created`)。
  - `attendance_day.edited` は休暇(有給・特別・代休)の種類・ID を持たない(`leaveSegments` は遅刻・早退の区間で休暇とは別)。休暇の文脈の出勤率の Projector(`Leave/Projectors/AbstractLeaveAttendanceRateProjector.php:104`)も購読している。
  - 休暇ビュー `attendance_day_leaves`(勤怠の文脈のテーブル): 日と社員から有効な休暇(`leave_kind`・`leave_request_id`・`unit`・`minutes`)を引ける(`Attendance/Support/AttendanceDayLeaves.php:19-26`)。
- 休暇の文脈(有給・特別休暇・代休):
  - 申請 `Request{X}Leave`・承認 `Approve{X}LeaveRequest`・取消 `Cancel{X}LeaveRequest`。
  - **承認の要否の判定は Controller にある**: `PaidLeaveController.php:569`・`SpecialLeaveController.php:338`・`CompensatoryLeaveController.php:169` が `SystemSetting::current()->{kind}_requires_approval`(休暇の種類ごとの全体の設定。利用者ごとの設定は無い)を見て、不要なら `DB::transaction` の中で申請→承認の2つのCommandを発行する(原則17の既存の例外)。
  - 冪等: `viaReactor=true` の申請・取消は、既に目的の状態なら何もしない(`RequestPaidLeaveHandler.php:37-39`、`CancelPaidLeaveRequestHandler.php:30-32`)。取消は本人・管理者の確認を `viaReactor` のとき省く。
- 既存の連鎖(休暇→勤怠): `Attendance/Reactors/AttendanceDayOn{Paid,Special,Compensatory}LeaveRequestReactor.php` が、休暇の申請・承認・取消のイベントを受けて `ApplyLeaveToAttendanceDay`・`RecalculateAttendanceDayForLeave`・`ReleaseLeaveFromAttendanceDay` を発行する(勤怠の日の集約を取り出し直して記録する)。重複・衝突の判定は勤怠の側(`LeaveDayConflictPolicy.php:30-44`)、締めの判定は `AttendanceEditGuard::assertMutable`。
- 仕組み(spatie laravel-event-sourcing 7.15):
  - `CommandBus::dispatch` は `DB::transaction` で包む。Reactor が発行する Command は入れ子のトランザクション。
  - Projector・Reactor はすべて同期。1つのイベントごとに Projector → Reactor の順に動く。例外は捕まえず(`catch_exceptions=false`)、起点の Command のトランザクションまで戻って全体が取り消される。
  - そのため、同じトランザクションの中で取消をすると、休暇ビューが先に取消に更新され、続く同じ日の申請は重複と判定されない(仕組みからの推論。通しのテストは無い)。
- 業務の拒否 `DomainRuleException` は 422(`{"message": ...}`、項目のキーは無い。`bootstrap/app.php:75-79`)。

## 設計前提の検証
- 正データと派生データ: 勤怠の日(`attendance_days` 等)は勤怠の文脈、休暇の申請は休暇の文脈、休暇ビューは勤怠の文脈の派生。どの正データも1か所にあり、本変更で二重にしない。
- イベントの意味: 新しく作る勤怠のイベントは「勤怠の日の保存で、利用者が休暇の切替を指示した」という勤怠の画面の操作の事実を表す。休暇の申請そのもの(休暇の文脈の事実)は、休暇の文脈が自分のイベントで記録する。
- 原則との関係: 原則15(文脈間はイベントで)・原則17(入口は1つのCommand)・原則9(業務の判定を入口に置かない)・原則14(申請と業務の分離。承認不要の休暇はワークフローを経ない)。
- 横展開: 同じく「勤怠の画面から休暇を操作する」入口が他にあるかを実装時に確かめる(MCP・個人端末等)。

## 仕様検討

### 論点1: 対象(承認が不要な休暇への切替)
- 決定:
  - 対象は「切り替える先の休暇の種類が、全体の設定で承認不要」のとき(承認の要否は休暇の種類ごとの全体の設定で、利用者ごとの設定は無い)。元の休暇の取消は、元の休暇の種類の承認の要否に関わらず行う(取消は承認を要しない)。
  - 切り替える先が承認の必要な種類のときは、勤怠の保存では切り替えない。画面は休暇の欄で切替を選ばせず、「承認が必要な休暇は、休暇の申請の画面から申請してください。」と案内し、取消と申請は別々の2つの操作にする(ユーザーの想定)。API に送られてきた場合は 422 で拒否する。
  - 「休暇なし」への切替(取消だけ)は、承認の要否に関わらず対象にする。
- 未確定・要確認事項: 「承認が不要なユーザー」を、上の「休暇の種類の全体の設定で承認不要」と解釈した。ユーザーに確認する。

### 論点2: 入口のCommandと勤怠のイベント
- 選択肢:
  - A. 既存の `attendance_day.edited` に休暇の切替の項目を足す。
  - B. 既存の `EditAttendanceDay`・`CreateAttendanceDay` に任意の `leaveSwitch` を足し、Handler が日の編集のイベントに続けて、休暇の切替の指示のイベントを別に記録する。
- 決定: B。
- 理由: A は `attendance_day.edited` を購読する既存の Projector(勤怠・休暇の出勤率)と作り直しに影響する。B なら既存のイベントは変わらず、休暇の切替を使わない保存も今と同じ。入口の Command は1つ(原則17)。
- 勤怠のイベント(勤怠の日の集約に記録する。2つに分ける):
  1. `attendance_day.leave_release_requested`(元の休暇の取消の指示): `userId`・`workDate`・`leaveKind`・`leaveRequestId`・`initiatedByUserId`。元の休暇は、勤怠の文脈の休暇ビューから引く(自文脈のテーブル)。
  2. `attendance_day.leave_application_requested`(新しい休暇の申請の指示): `userId`・`workDate`・`leaveKind`・`unit`(全日・午前・午後・時間)・`hours`・`specialLeaveTypeId`・`reason`・`initiatedByUserId`。
- 2つに分ける理由: 同じイベントを購読する Reactor の実行順に依存しない(docs/03 3.10「Reactorの独立性」)。取消の指示のイベントの連鎖(取消→休暇ビューの更新→日次の再計算)が終わってから、申請の指示のイベントを記録するので、取消が必ず申請より先に反映される。
- 未確定・要確認事項: なし

### 論点3: 休暇の文脈のReactor
- 決定: 有給・特別休暇・代休のそれぞれに、勤怠のイベントを購読する Reactor を作る(例: `PaidLeaveRequestOnAttendanceDayReactor`)。
  - `leave_release_requested` で、`leaveKind` が自分の種類なら `Cancel{X}LeaveRequest(leaveRequestId, viaReactor: true, initiatedByUserId)` を発行する。
  - `leave_application_requested` で、`leaveKind` が自分の種類なら、承認不要の申請の Command(論点4)を `viaReactor: true` で発行する。申請の ID は、勤怠のイベントの ID から決まる値にする(同じイベントを2回処理しても同じ申請になる。冪等)。
  - 自分の種類でなければ何もしない。
- 拒否(残数不足・締め済み・重複・承認が必要な種類)は休暇・勤怠の文脈の Command の例外で表し、起点の勤怠の保存まで戻って全体が取り消される(勤怠の日の編集・休暇の取消・申請のどれも残らない)。
- 未確定・要確認事項: なし

### 論点4: 承認不要の判定と自動の承認をドメインへ移す
- 選択肢:
  - A. Reactor の中で「申請→承認」の2つのCommandを発行する。
  - B. 休暇の文脈に「承認不要の申請」を1つのCommandとして作る(Handler が承認の要否の判定クラスで不要を確かめ、申請と承認のイベントを記録する)。既存の Controller の「申請→承認」もこのCommandに置き換える。
- 決定: B。
- 理由: 原則9・17(業務の判定を入口に置かない、入口は1つのCommand)。Controller・Reactor・将来の MCP のどこから来ても同じ判定になる。既存の例外(3つの休暇の Controller)の是正も兼ねる。
- 判定クラス: `App\Domain\Leave\Support\LeaveApprovalRequirement`(仮。名前は実装時に既存と衝突しないことを確かめる)。休暇の種類を受け、全体の設定を読んで要否を返す。承認が必要な種類で承認不要の申請のCommandが来たら `DomainRuleException`。
- 記録するイベントは今と同じ(申請・承認)。新しい種類のイベントは作らない(既存の連鎖・Projector・作り直しをそのまま使う)。
- シフト交換の Controller の同じ例外は本変更の対象外(別の是正の変更セット)。
- 未確定・要確認事項: なし

### 論点5: 勤怠の日の集約への戻り
- 事実: 休暇の申請・取消のイベントを受けた既存の Reactor が、同じ勤怠の日の集約を取り出し直してイベントを記録する(版が進む)。勤怠の Handler が手元に持つ集約の実体は古くなり、続けて記録すると版の不一致で失敗する(spatie の同時実行の確認)。
- 決定: 勤怠の Handler は、日の編集・標準休憩の補完・日次の計算をすべて記録した後で、取消の指示のイベントを記録する。その後、集約を取り出し直してから申請の指示のイベントを記録する。最後の日次の計算は、既存の Reactor(休暇の反映で再計算)に任せる。
- 未確定・要確認事項: なし

### 論点6: 画面
- 決定:
  - 日の編集・作成の保存を1回の API に送る(本文に `leave_switch`。変えないときは送らない)。休暇の取消・申請の API を画面から呼ばない。
  - 切り替える先の休暇の種類が承認の必要なものは、休暇の欄で選べなくし(理由を表示)、休暇の申請の画面へのリンクを出す。承認の要否は、画面がすでに使っている設定の取得方法で得る(無ければ設定の API の応答に含める)。
  - 422 は項目のエラー(`fieldErrors`)とまとめての表示に出す。休暇の拒否(キーの無い 422)はまとめての表示に出る。
  - 変更セット`20261127-form-error-visibility-fixes`の論点4(入力の不足を先に確かめる)は、送信前の確認として残る。
- 未確定・要確認事項: なし

### 論点7: 作り直し(リビルド)
- 決定: 新しい2つの勤怠のイベントは、勤怠・休暇のどの ReadModel も変えない(指示の記録のみ)。作り直しでは Reactor が走らないが、その結果(休暇の取消・申請・承認、勤怠の日の再計算)はそれぞれのイベントとして記録済みなので、作り直しで同じ状態になる。`config/event-sourcing.php` に別名を登録し、`events:export-history-types` で画面の履歴の型を更新する(履歴の表示名を付ける)。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
(論点1の確認の後に、実装者向けに確定する。下は現時点の案)
- backend:
  - `EditAttendanceDay`・`CreateAttendanceDay` に任意の `leaveSwitch`(`toKind`: none・paid・special・compensatory、`unit`、`hours`、`specialLeaveTypeId`、`reason`)。API の本文 `leave_switch` を検証して渡す。
  - 勤怠の Handler: 論点5の順で、休暇ビューの有効な休暇と `leaveSwitch` を比べ、変わる場合だけ `leave_release_requested`(元があれば)・`leave_application_requested`(先が none 以外)を記録する。切り替える先が承認の必要な種類なら 422(この判定は論点4の判定クラスを休暇の文脈から呼ぶのではなく、休暇の文脈の Command の拒否で表す。勤怠の Handler は判定しない)。
  - 休暇の3つの文脈: 承認不要の申請のCommand(論点4)と、勤怠のイベントを購読する Reactor(論点3)。既存の3つの Controller の申請は、承認不要のときこのCommandを1つ発行する形にする。
  - イベントの別名の登録、履歴の型の書き出し。
- frontend: 論点6。
- テスト(`domain-test`):
  - 業務シナリオテスト(`tests/Feature/Attendance/` または `tests/Feature/Scenarios/`): 承認不要の設定で、有給の日を特別休暇・代休・休暇なしへ切り替えて保存→勤怠の日・元の休暇(取消)・新しい休暇(承認済み)・残数・休暇ビュー・日次の計算がそろう。
  - 拒否で全体が戻る: 特別休暇の残数不足・締め済みの月・同じ日の別の休暇・切り替える先が承認の必要な種類→422、勤怠の日の編集も休暇もワークフローも変わらない。
  - 冪等: 勤怠のイベントを2回処理しても申請は1件。
  - 作り直し: シナリオの後に ReadModel を空から作り直して同じ状態。
  - 既存の3つの休暇の申請の API(承認不要の設定)が、今と同じ結果になる(承認不要の申請のCommandへの置き換えの回帰)。
  - 画面: 保存が1回の API になる、承認の必要な種類を選べない、422 の表示。

## 受け入れ条件
- 承認不要の休暇への切替(休暇なしを含む)を、勤怠の日の保存1回で行え、途中で拒否されると勤怠の日・休暇・残数・ワークフローのどれも変わらない。
- 承認の必要な休暇へは、勤怠の日の画面からは切り替えられず、案内が出る。
- 休暇の3つの Controller は、承認不要の申請で1つのCommandだけを発行する(原則17の既存の例外から外れる)。
- 上のテストが通り、backend・frontend の単体テスト全件が通る。変えた画面を通る E2E の spec が通る。

## 対象外
- 承認の必要な休暇の切替を1回の操作にすること(取消と申請の2つの操作のまま)
- シフト交換・まとめて保存・一括操作の Controller の是正(原則17の既存の例外。別の変更セット)
- 利用者ごとの承認の要否の設定

## ドキュメントへの影響
- `docs/03-architecture.md` 3.10: 連携の向きに「勤怠→休暇申請(勤怠の日の保存での休暇の切替の指示)」を加える。既存の例外から休暇の3つの Controller を外す。
- `docs/07`(勤怠の日の編集)・`docs/09`(休暇の申請)の該当のユースケース: 勤怠の日の保存で承認不要の休暇を切り替えられること。
- `docs/17-events.md`: 新しい2つのイベント。

## モック・アセット
なし

## 実装対象
- backend: `app/Domain/Attendance`(Command・Handler・イベント・集約)、`app/Domain/PaidLeave`・`SpecialLeave`・`CompensatoryLeave`(承認不要の申請のCommand・Reactor)、`app/Domain/Leave/Support`(判定クラス)、`app/Http/Controllers/Api/AttendanceController.php`・3つの休暇の Controller、`config/event-sourcing.php`、テスト
- frontend: `pages/attendance/AttendanceDayPage.tsx`、`api/attendance.ts`(本文の型)、生成物の履歴の型

## 検証方法
- backend: CI と同じ条件で全件(`cp .env.example .env && php artisan key:generate && php artisan test`、後で `.env` と DB を消す)
- frontend: tsc、単体テスト全件
- E2E: 勤怠の日・休暇を通る spec(scenario-02・03・06 ほか grep で特定)

## レビュー履歴
初版

## 実装結果
未着手
