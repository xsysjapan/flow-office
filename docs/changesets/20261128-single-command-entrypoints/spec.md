# single-command-entrypoints(段階①: まとめて保存・承認不要の申請などを、入口1回=1つのCommandにする)

ステータス: 検討中

## 変更要望(原文)
> まとめて保存系の一括操作についてもAPIを用意して処理してください。時間がかかるものは非同期で動くようにしてください。

ユーザーの回答(2026-11-28):
- 非同期は、IDを指定せず条件で数千件を処理するような一括処理の場合だけ。現状の処理はすべて同期でよい。対象は画面で選んで決める。
- 期間の休暇の申請は1つの申請として、全部か無しか(段階③)。
- 進め方: ①まとめて保存と承認不要の申請を1つのCommandに → ②共通の一括操作の仕組みと、一括の取消・承認・担当変更・完了・付与・備品の移行 → ③期間の申請。

## 背景・目的
設計原則17(API の1回の呼び出しは1つのCommand。集約・文脈をまたぐ変更は同期 Reactor の連鎖で整合。業務の判定を入口に置かない)の既存の例外(`docs/03-architecture.md` 3.10)のうち、まとめて保存と承認不要の申請などを是正する。

## 現状(As-Is)(調査 2026-11-28、investigator)
| # | 入口 | 今 Controller で行っていること |
|---|---|---|
| 1 | `PUT /users/{user}/details`(`UserController::updateDetails` :114-220) | 項目の権限(外部人事)・入社日と退社日の最終状態の検査・dispatch の順序の決定を Controller で行い、`UpdateUserProfile`・`SetUserHireDate`・`SetUserTerminationDate`・`SetUserUsageStartDate`・`SetPaidLeaveAutoGrantEnabled`・`SetSpecialLeaveAutoGrantEnabled`(最大6)を `DB::transaction` で並べる。日付3項目の外部人事の確認は Controller にしかない。 |
| 2 | `PUT /admin/access-control/roles/{role}/definition`(`AccessControlController::updateRoleDefinition` :128-170) | `UpdateRole`・`ChangeRolePermissions`・`ChangeRoleFeatures` を並べる。`ChangeRoleFeaturesHandler` は `GroupFeatureSyncService` 経由で `AssignFeatureToGroupHandler`・`RemoveFeatureFromGroupHandler` を直接呼ぶ(CommandBus も Reactor も通らない)。無効化と Permission の変更を同時に送ると、無効化の後の変更が「有効なRoleが存在しません。」で失敗する(順序依存)。 |
| 3 | `POST /expense-claims/{claim}/items/bulk`(`ExpenseClaimController::bulkAddItems` :166-187) | 明細ごとに検証して `AddExpenseItem` を件数分並べる。集約は経費精算1件。検証のエラーのキーに行の添字が付かない(`category_id` 等)。応答は入力の順の明細の配列(画面は順序で添付を対応付ける)。 |
| 4 | `PUT /attendance/weeks/{start}/overtime-allocations`(`AttendanceController::allocateWeekOvertime` :241-279) | 日ごとに `AllocateAttendanceWeeklyOvertime` を並べ、最後に週40時間超の検査(`WeeklyOvertimeCalculator::calculateWeek`)を Controller で行う(超えたら全体を戻す)。 |
| 5 | 休暇・振替休日の申請 `POST /{paid-leave,special-leave,compensatory-leave,shift-swap}/requests` | 承認の要否(`SystemSetting::*_requires_approval`)を Controller が読み、不要なら `Request{X}` と `Approve{X}(approvedByUserId: null)` を `DB::transaction` で並べる。承認が必要なら `DraftWorkflowRequest`(1つ)。承認不要のときの承認者は Controller が決める(入力が無ければ申請者本人)。 |
| 6 | `POST /paid-leave/schedule-entries/{id}/reassess`(`PaidLeaveScheduleController::reassess` :190-229) | 組の相手(同じ付与予定日・継続勤務月数が12か月違う行)を Controller が集約から読み、判定の計画(`PaidLeaveScheduleAssessmentPlanner`)を Controller で作って `RunAttendanceRateAssessment` を2回並べる。 |

- 前例: Handler で設定を読んで自動承認する(`SubmitAttendanceMonthHandler.php:65-70`、`SubmitExpenseClaimHandler.php:53-57`)。1つのCommandに配列を渡す(`UpdateCompanyCalendarDays`)。
- 同期 Reactor は、Handler が集約に記録したイベントごとに、Projector の後に動く(1つの集約に3つのイベントを記録すると、それぞれを購読する Reactor が3回動く)。

## 設計前提の検証
- 正データと派生データ: どの件も記録するイベントと正データは今と同じ。入口と Handler の分担だけを変える。
- 原則9・17: 業務の判定(権限・最終状態・順序・承認の要否・週の上限・組の判定)を Controller から Handler・判定クラスへ移す。
- 横展開: 段階②(一括操作)・③(期間の申請)は別の変更セット。付与ルール・付与表の直接の DB 書き込み(イベントが無い。原則1)と、ワークフローの Reactor が他の文脈のテーブルを読む件(原則15)は、本変更の対象外として記録する(下の「対象外」)。

## 仕様検討

### 論点1: 利用者詳細のまとめて保存
- 決定: `UpdateUserDetails`(UserManagement)を新しく作る。Handler が、変更のあった項目について、外部人事の項目の権限(日付3項目を含む。今は Controller にしかない)・最後の有効なシステム管理者の無効化の禁止・入社日 ≤ 退社日(最終状態で判定)を検査し、利用者の集約に今と同じイベント(`UserProfileUpdated`・`UserHireDateSet`・`UserTerminationDateSet`・`UserUsageStartDateSet`・`PaidLeaveAutoGrantEnabledSet`・`SpecialLeaveAutoGrantEnabledSet`)を記録する。最終状態で検査するので、途中の状態のための dispatch の順序の調整は要らない(集約は最終状態だけを見る)。
- 拒否は項目のキー付きの 422(今と同じキー)。業務の検査を Handler に置くため、Handler は項目のキーを持つ例外(既存の `ValidationException::withMessages` の作り)を投げる。
- 付与予定の再計算の Reactor(`RecalculateScheduleOnUserConditionChangedReactor`。入社日・利用開始日・有給の自動付与を購読)は、1回の保存で最大3回動く。再計算は今の状態との差分なので最後には同じ結果になるが、途中の状態で付与予定を作って取り消すイベントが増えうる。
  - 決定: 実装の最初に、3項目を同時に変えたときに付与予定のイベントが余分に増えるかをテストで確かめる。増える場合は、Handler がイベントを「入社日・利用開始日・自動付与」の順ではなく、まとめて記録した後に Reactor が1回だけ再計算するよう、Reactor の購読を「同じ集約の同じ persist の最後の利用者条件のイベント」に限る(イベントのメタデータに、1回の Command の中での位置を持たせる)。増えない場合は今の Reactor のまま。
- 既存の項目ごとの API(`PATCH /users/{id}`・`PUT …/hire-date` 等)は残す(各1つのCommand)。
- 未確定・要確認事項: なし

### 論点2: ロール詳細のまとめて保存
- 決定: `UpdateRoleDefinition`(AccessControl)を新しく作る。Handler が、名前・説明・状態・Permission・Feature のうち変わったものを検査し、ロールの集約に今と同じイベント(`RoleUpdated`・`RolePermissionsChanged`・`RoleFeaturesChanged`)を記録する。
  - 検査: システムのロールの無効化の禁止、Permission・Feature の存在、システム管理者から `user.manage` を外すことの禁止。Permission・Feature の変更は「保存の前にロールが有効」なら可(無効化と同時でもよい)。イベントは Permission・Feature の変更を先に、状態の変更を最後に記録する(今の順序依存を無くす)。
- グループへの Feature の同期: Handler から他の Handler を直接呼ぶのをやめ、`RoleFeaturesChanged` を購読する同期 Reactor(AccessControl の文脈の中)で、対象のグループごとに `AssignFeatureToGroup`・`RemoveFeatureFromGroup` の Command を発行する(冪等: 既に目的の状態なら何もしない)。既存の `ChangeRoleFeatures` の API からの変更も同じ Reactor で同期される。
- 既存の3つの API は残す。
- 未確定・要確認事項: なし

### 論点3: 経費の明細のまとめて登録
- 決定: `AddExpenseItems`(ExpenseClaim)を新しく作る。Handler が明細ごとに今の `AddExpenseItem` と同じ検査をし、経費精算の集約に `ExpenseItemAdded` を明細ごとに記録する(1つでも拒否なら全体を戻す。今と同じ)。
- 検証のエラーのキーを `items.{i}.{項目}` にする(行の添字付き)。画面は `fieldErrors` の `ofRow` で行の下に出す(変更セット`20261126-field-errors-near-fields`で行に出せず残した件の解消)。
- 応答は今と同じ(入力の順の明細の配列)。添付は今のまま明細ごとに別の API(1つのCommand)。
- 未確定・要確認事項: なし

### 論点4: 週の残業の振分
- 決定: `AllocateWeeklyOvertime`(Attendance。週1つ)を新しく作る。Handler が日ごとの検査(今の `AllocateAttendanceWeeklyOvertimeHandler` と同じ)をして日の集約に `AttendanceWeeklyOvertimeAllocated` を記録し、最後に週40時間超の検査(`WeeklyOvertimeCalculator`)を行う。超えたら例外で全体を戻す(今と同じ全部か無しか)。
- 日の集約への記録は Handler の中で直接行う(同じ文脈。`WeeklyOvertimeAutoAllocator` の前例)。日ごとの Command を Handler の中で dispatch しない。
- 応答に、GET の週の残業と同じく `prescribed_work_days`・`scheduled_prescribed_work_minutes` を含める(今は欠けている。同じ形にそろえる)。
- 既存の日ごとの Command `AllocateAttendanceWeeklyOvertime` は、他の入口が使っていなければ削除する(実装時に grep)。
- 未確定・要確認事項: なし

### 論点5: 休暇・振替休日の承認不要の申請
- 決定: 承認の要否の判定を各 `Request{X}` の Handler に移す。
  - 判定クラス `App\Domain\Leave\Support\LeaveApprovalRequirement`(仮。既存の名前と衝突しないことを確かめる): 休暇の種類(有給・特別休暇・代休・振替休日)を受け、全体の設定を読んで要否を返す。
  - `Request{X}` の Handler: 利用者の操作(`viaReactor=false`)で承認が不要なら、申請のイベントに続けて承認のイベント(今の `Approve{X}` の Handler と同じ処理。`approvedByUserId=null`)を同じ集約に記録する。承認者は、入力が無ければ申請者本人(今の Controller と同じ)を Handler で決める。承認が必要なら、利用者の操作としては受け付けない(承認が必要なときの入口はワークフローの下書き `DraftWorkflowRequest` で、その連鎖から `viaReactor=true` で来る)。
  - Controller は、承認の要否で入口の Command を選ぶだけ(要: `DraftWorkflowRequest`、不要: `Request{X}`)。どちらも1つ。※ この選択に設定を読むのは「入口の振り分け」であって業務の判定ではないが、二重の判定にならないよう、Controller は判定クラスを呼び、Handler も同じ判定クラスで確かめる。
- `Approve{X}` の Handler の処理(承認者の確認・冪等・特別休暇の要付与の確認・振替休日の勤務予定の入れ替え)は、`Request{X}` の Handler から共通の処理として呼ぶ(重複させない)。
- 記録するイベントと連鎖(残数の消化の確定・勤怠の休暇の反映)は今と同じ。
- 未確定・要確認事項: なし

### 論点6: 付与予定の再判定
- 決定: `ReassessScheduleEntry`(PaidLeaveSchedule。付与予定の行1つ)を新しく作る。Handler が、組の相手の判定(集約の `pairedEntryId`)と判定の計画(`PaidLeaveScheduleAssessmentPlanner`)を行い、行と相手(あれば)に `PaidLeaveScheduleAssessmentRecorded` を記録する。応答は今と同じ。
- 既存の `RunAttendanceRateAssessment`(運用コマンドの一括の判定も使う)は残す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend:
  - 新しい Command と Handler: `UpdateUserDetails`・`UpdateRoleDefinition`・`AddExpenseItems`・`AllocateWeeklyOvertime`・`ReassessScheduleEntry`(名前は実装時に衝突を確かめる)。登録は `config/domain.php` の command_handlers。
  - 判定クラス `LeaveApprovalRequirement`。4つの `Request{X}` の Handler に承認不要の自動承認を入れ、`Approve{X}` の処理を共通化する。
  - グループへの Feature の同期の Reactor(論点2)。
  - 6つの入口の Controller は、検証(形式)・認可・1つの Command の dispatch・応答だけにする。
  - 新しいイベントは作らない(論点1の付与予定の再計算の確認の結果、メタデータを足す場合を除く)。
  - `docs/03-architecture.md` 3.10 の「既存の例外」から、本変更で是正したものを外す。
- frontend:
  - 経費の明細のまとめて登録の 422 を `ofRow('items', i, key)` で行の下に出す(`ExpenseItemsTable` に行のエラーを渡す)。
  - ほかは API の形が変わらないので変更なし(週の残業の応答に項目が増えるだけ)。
- テスト(`domain-test`):
  - 各 Command の Feature テスト: 今の Feature テスト(`UserDetailsBulkUpdateTest`・`RoleDefinitionBulkUpdateTest`・`ExpenseClaimFlowTest`・`WeeklyOvertimeCalculationTest`・休暇4種の申請のテスト・`PaidLeaveScheduleControllerTest`)が、入口を変えた後もそのまま通る(回帰)。
  - 追加: 利用者詳細で3項目を同時に変えたときの付与予定のイベント(論点1)、ロールの無効化と Permission の変更を同時に送って通る、Feature の同期が Reactor で行われる(既存の `ChangeRoleFeatures` の API も)、経費の明細の 422 のキーが `items.{i}.{項目}`、週の残業の応答の項目、承認不要の申請で申請と承認のイベントが1つの Command で記録される・承認が必要な設定で `Request{X}` を利用者の操作として送ると拒否される・ワークフロー経由(`viaReactor`)は今どおり。
  - Controller が1つの Command だけを dispatch することを、各入口のテストで CommandBus の dispatch の回数で確かめる。
  - 作り直し: 代表のシナリオの後に ReadModel を作り直して同じ状態。

## 受け入れ条件
- 6つの入口が、1回の呼び出しで1つの Command だけを dispatch する。業務の判定が Controller に無い。
- 画面の動き・API の応答は今と同じ(経費の明細の 422 のキーと週の残業の応答の項目の追加を除く)。
- 上のテストと backend・frontend の単体テスト全件が通る。変えた画面を通る E2E の spec が通る。

## 対象外
- 段階②(共通の一括操作の仕組み: 申請の一括取消・承認の一括・バックオフィスの担当の一括変更・一括完了・有給の一括付与・休暇の手動付与・備品の一括操作)・段階③(期間の休暇の申請)
- 付与ルール・付与表(有給・特別休暇)の直接の DB 書き込み(イベントが無い。原則1)。別の候補。
- ワークフローの Reactor(`ApproveWorkflowRequestOnPaidLeaveRequestApprovedReactor` 等)が `workflow_requests` を直接読む件(原則15の観点)。別の候補。
- 経費の明細と添付が別のトランザクションである件

## ドキュメントへの影響
- `docs/03-architecture.md` 3.10(既存の例外の更新)
- `docs/17-events.md`: 変更なし(新しいイベントを作らない。論点1でメタデータを足す場合はその旨)
- 各ユースケースの docs: 振る舞いが変わらないので変更なし

## モック・アセット
なし

## 実装対象
- `backend/app/Domain/{UserManagement,AccessControl,ExpenseClaim,Attendance,PaidLeave,SpecialLeave,CompensatoryLeave,ShiftSwap,PaidLeaveSchedule,Leave}`、`backend/app/Http/Controllers/Api/` の6つの Controller、`backend/config/domain.php`、テスト
- `frontend/src/pages/expense/ExpenseClaimNewPage.tsx`・`components/ExpenseItemsTable`

## 検証方法
- backend: CI と同じ条件で全件(`cp .env.example .env && php artisan key:generate && php artisan test`、後で `.env` と DB を消す)
- frontend: tsc、単体テスト全件
- E2E: 変えた画面を通る spec(利用者・ロール: scenario-10・99、経費: scenario-04・09、週の勤怠: scenario-02、休暇: scenario-03・15、振替休日・付与予定: grep で特定)

## レビュー履歴
初版

## 実装結果
未着手
