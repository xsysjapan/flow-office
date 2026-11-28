# single-command-entrypoints(段階①: まとめて保存・承認不要の申請などを、入口1回=1つのCommandにする)

ステータス: レビュー中

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
- 決定: `UpdateUserDetails`(UserManagement)を新しく作る。Handler が、変更のあった項目について、外部人事の項目の権限(日付3項目を含む)・最後の有効なシステム管理者の無効化の禁止・入社日 ≤ 退社日(最終状態で判定)を検査し、利用者の集約に今と同じイベント(`UserProfileUpdated`・`UserHireDateSet`・`UserTerminationDateSet`・`UserUsageStartDateSet`・`PaidLeaveAutoGrantEnabledSet`・`SpecialLeaveAutoGrantEnabledSet`)を記録する。最終状態で検査するので、途中の状態のための記録の順序の調整は要らない(集約は日付の前後を検査しない)。
- 外部人事の項目の権限の確認を、項目ごとの Handler(`SetUserHireDate`・`SetUserTerminationDate`・`SetUserUsageStartDate`)にも入れ、新しい Handler と共通の処理にする(今は項目ごとの API でも Controller にしかない)。
- 拒否は項目のキー付きの 422(今と同じキー)。Handler は項目のキーを持つ例外(`ValidationException::withMessages`。前例 `SubmitWorkflowRequestHandler.php:55`・`UpdateWorkStyleHandler.php:31`)を投げる。
- 付与予定の再計算の重複(ユーザーの判断 2026-11-28: 今回あわせて直す):
  - 事実(設計レビューで検証): 入社日・利用開始日・自動付与を1回で変えると、今の画面でも新しい1つの Command でも、付与予定の再計算の Reactor(`RecalculateScheduleOnUserConditionChangedReactor`)が3回動き、付与予定に「取消→作成→取消」のイベントが残る(1回だけ再計算すれば「取消」だけ)。spatie は同じ persist の全イベントを先に `stored_events` に保存してから、1件ずつ Projector → Reactor の順に処理するため、Reactor が ReadModel(`users`)を読むと途中の状態が見える。
  - 決定: Reactor の側で、同じ利用者の集約のストリームに、この Reactor が購読する種類のイベントで、処理中のイベントより版(aggregate_version)の大きいものが既に保存されていれば何もしない。最後の1件のときだけ再計算する(そのときは同じ persist の Projector の更新がすべて反映された状態を読む)。利用者の文脈(UserManagement)は付与予定の文脈が何を購読するかを知らなくてよい(原則15)。
  - Handler が再計算の Command を発行する案(文脈をまたぐ)、「利用者条件が変わった」イベントを足す案(事実の重複・データ移行)は採らない。
- 既存の項目ごとの API(`PATCH /users/{id}`・`PUT …/hire-date` 等)は残す(各1つのCommand)。
- 未確定・要確認事項: なし

### 論点2: ロール詳細のまとめて保存
- 決定: `UpdateRoleDefinition`(AccessControl)を新しく作る。Handler が、名前・説明・状態・Permission・Feature のうち変わったものを検査し、ロールの集約に今と同じイベント(`RoleUpdated`・`RolePermissionsChanged`・`RoleFeaturesChanged`)を記録する。
  - 検査: システムのロールの無効化の禁止、Permission・Feature の存在、システム管理者から `user.manage` を外すことの禁止。Permission・Feature の変更は「保存の前か後のどちらかでロールが有効」なら可(無効化と同時、無効から有効へ戻すのと同時のどちらも通る。今の画面で通る「無効のロールを有効に戻しつつ Permission を変える」を後退させない)。
  - 記録の順序: 有効にする変更は最初に、無効にする変更は最後に記録する(イベントの並びが、どの時点でも「有効なロールの Permission の変更」になるように)。
- グループへの Feature の同期は、今と同じ `GroupFeatureSyncService` を Handler から呼ぶ(変えない)。Handler から他の Handler を直接呼ぶ作りは、ロールの割当の3つの Handler と運用コマンドも使っており、仕組みを分けないよう本変更では触らない(別の候補)。
- 既存の3つの API は残す。
- 未確定・要確認事項: なし

### 論点3: 経費の明細のまとめて登録
- 決定: `AddExpenseItems`(ExpenseClaim)を新しく作る。Handler が明細ごとに今の `AddExpenseItem` と同じ検査をし、経費精算の集約に `ExpenseItemAdded` を明細ごとに記録する(1つでも拒否なら全体を戻す。今と同じ)。
- 検証のエラーのキーを `items.{i}.{項目}` にする(行の添字付き)。形式の検証は Controller で `items.*.{項目}` のルールにする(添字は自動で付く)。業務の拒否(区分の有効性・属性の検証など)は、Handler が `items.{i}.{項目}` のキーで投げる。画面は `fieldErrors` の `ofRow` で行の下に出す(変更セット`20261126-field-errors-near-fields`で行に出せず残した件の解消)。
- 応答は今と同じ(入力の順の明細の配列)。添付は今のまま明細ごとに別の API(1つのCommand)。
- 未確定・要確認事項: なし

### 論点4: 週の残業の振分
- 決定: `AllocateWeeklyOvertime`(Attendance。週1つ)を新しく作る。Handler が日ごとの検査(今の `AllocateAttendanceWeeklyOvertimeHandler` と同じ)をして日の集約に `AttendanceWeeklyOvertimeAllocated` を記録し、最後に週40時間超の検査(`WeeklyOvertimeCalculator`)を行う。超えたら例外で全体を戻す(今と同じ全部か無しか)。
- 日の集約への記録は Handler の中で直接行う(同じ文脈。原則17の「まとめて保存は1つのCommand」、前例 `WeeklyOvertimeAutoAllocator`)。日ごとの Command を Handler の中で dispatch しない。日ごとの上限は前の振分の ReadModel を読むので、日ごとに順に persist し、週の検査はその後に行う。同じ日が入力に重複したら 422(`allocations.*.attendance_day_id` を distinct にする)。
- 応答に、GET の週の残業と同じく `prescribed_work_days`・`scheduled_prescribed_work_minutes` を含める(今は欠けている。同じ形にそろえる)。
- 既存の日ごとの Command `AllocateAttendanceWeeklyOvertime` は、他の入口が使っていなければ削除する(実装時に grep)。
- 未確定・要確認事項: なし

### 論点5: 休暇・振替休日の申請の入口
- ユーザーの判断(2026-11-28): 「呼び出し側で判断してください。実装は設定に応じてエラーにしてください。」
- 決定:
  - 入口を2つに分け、どちらを呼ぶかは呼び出し側(画面・API の利用者)が全体の設定(公開の設定 `PublicSystemSettingController` の `*_requires_approval`)を見て決める。Controller は承認の要否で分岐しない。
    - 承認不要の申請: `POST /{paid-leave,special-leave,compensatory-leave,shift-swap}/requests`。`Request{X}` を1つ dispatch する。
    - 承認が必要な申請(ワークフロー): ワークフローの下書きの作成(`DraftWorkflowRequest` を1つ)。既存の汎用のワークフローの API が休暇の種類を受けるならそれを使い、受けなければ休暇ごとに入口を新設する(実装時に確かめ、実装結果に記録)。
  - 各 Handler が設定に応じて拒否する:
    - `Request{X}`(利用者の操作): 承認が必要な設定なら 422「{休暇}は承認が必要です。申請から承認者を指定して申請してください。」。不要なら、申請を persist した後に、承認の処理(今の `Approve{X}` の Handler の処理を共通化したもの)で集約を取り出し直して承認を persist する(1つの Handler・1トランザクションで2回 persist。記録の順序と連鎖は今の「2つの Command を順に」と同じ)。承認者は入力が無ければ申請者本人。
    - ワークフロー経由(`DraftWorkflowRequest` の連鎖から来る `Request{X}`): 有給・特別休暇・代休は `viaReactor=true`、振替休日は `workflowRequestId !== null` で見分け、承認の要否を見ない(今どおり)。
    - ワークフローの下書き(休暇・振替休日の種類): 承認不要の設定なら 422「{休暇}は承認が不要です。直接申請してください。」。この判定はワークフローの Handler で行う(休暇の種類の設定を読む)。
  - 判定クラス `App\Domain\Leave\Support\LeaveApprovalRequirement`: 休暇の種類(有給・特別休暇・代休・振替休日)を受け、全体の設定を読んで要否を返す。Handler 同士で同じ判定を使う。
  - 承認者の必須の検証(`approver_user_id`)は、入口が分かれるので、承認が必要な入口でだけ必須にする(設定を読まない)。
- frontend: 申請の画面(有給・特別休暇・代休・振替休日)が公開の設定を見て、承認不要なら直接の申請、必要ならワークフローの下書きの API を呼ぶ。
- 未確定・要確認事項: なし

### 論点7: ロールの複製
- 事実(設計レビュー): `AccessControlController::cloneRole`(:175-185)は `CreateRole` と `ChangeRolePermissions` を `DB::transaction` 無しで並べ、途中で失敗すると権限の無いロールが残る。
- 決定: `CloneRole`(AccessControl)を1つの Command にし、作成と権限の設定を1つの Handler で記録する。
- 未確定・要確認事項: なし

### 論点6: 付与予定の再判定
- 決定: `ReassessScheduleEntry`(PaidLeaveSchedule。付与予定の行1つ)を新しく作る。Handler が、組の相手の判定(集約の `pairedEntryId`)と判定の計画(`PaidLeaveScheduleAssessmentPlanner`)を行い、行と相手(あれば)に `PaidLeaveScheduleAssessmentRecorded` を記録する。応答は今と同じ。
- 既存の `RunAttendanceRateAssessment`(運用コマンドの一括の判定も使う)は残す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend:
  - 新しい Command と Handler: `UpdateUserDetails`・`UpdateRoleDefinition`・`CloneRole`・`AddExpenseItems`・`AllocateWeeklyOvertime`・`ReassessScheduleEntry`(名前は実装時に衝突を確かめる)。登録は `config/domain.php` の command_handlers。
  - 判定クラス `LeaveApprovalRequirement`。4つの `Request{X}` の Handler に、設定に応じた拒否と承認不要の自動承認(2回の persist)を入れ、`Approve{X}` の処理を共通化する。ワークフローの下書きの Handler に、休暇・振替休日の種類で承認不要の設定なら拒否を入れる。
  - 付与予定の再計算の Reactor の重複の抑止(論点1)。外部人事の権限の確認を項目ごとの日付の Handler にも入れる。
  - 6つの入口の Controller は、検証(形式)・認可・1つの Command の dispatch・応答だけにする。
  - 新しいイベントは作らない。
  - `docs/03-architecture.md` 3.10 の「既存の例外」から、本変更で是正したものを外す。
- frontend:
  - 経費の明細のまとめて登録の 422 を `ofRow('items', i, key)` で行の下に出す(`ExpenseItemsTable` に行のエラーを渡す)。
  - 休暇・振替休日の申請の画面: 公開の設定で入口を選ぶ(論点5)。
  - ほかは API の形が変わらないので変更なし(週の残業の応答に項目が増えるだけ)。
- テスト(`domain-test`):
  - 各 Command の Feature テスト: 今の Feature テストを回帰として通す。ただし次は書き直す(入口の作りが変わるため):
    - `UserDetailsBulkUpdateTest`: Command ごとに Handler を差し替えて途中の失敗を作るテスト(:117-134・:251-301)は、1つの Handler の中の失敗で作り直す。日付の記録の順序を確かめるテスト(:152-182)は、最終状態の検査のテストにする。
    - `HalfDayLeavePrescribedMinutesTest.php:59`: 承認が必要な既定の設定で `RequestSpecialLeave` を利用者の操作として出しているので、設定を承認不要にするか、ワークフロー経由にする。
    - `ReadModelRebuildAfterDeletionScenarioTest.php:106`: `AllocateAttendanceWeeklyOvertime` を削除するなら新しい Command に置き換える。
    - 休暇4種の申請のテストで、承認が必要な設定で `POST /…/requests` を呼んでワークフローを作っているものは、ワークフローの入口に書き直す。
  - 追加: 利用者詳細で3項目を同時に変えたとき付与予定のイベントが「取消」だけ(論点1)・項目ごとの日付の API でも外部人事の項目が拒否される、ロールの無効化と Permission の変更を同時に送って通る・無効から有効へ戻すのと同時も通る、ロールの複製が途中で失敗すると何も残らない、経費の明細の 422 のキーが `items.{i}.{項目}`、週の残業の応答の項目と同じ日の重複の拒否、承認不要の設定で直接の申請が申請と承認を記録する(記録の順序が今と同じ)・承認が必要な設定で直接の申請が 422・承認不要の設定でワークフローの下書きが 422・ワークフロー経由(`viaReactor`・振替休日は `workflowRequestId`)は今どおり。
  - 入口1回=1つの Command: CommandBus の入れ子の深さを数えるテスト用の部品(`$this->app->instance()` で差し込む)を作り、深さ0の dispatch が1回であることを各入口のテストで確かめる。
  - 作り直し: 代表のシナリオの後に ReadModel を作り直して同じ状態。

## 受け入れ条件
- 対象の入口(利用者詳細・ロール詳細・ロールの複製・経費の明細のまとめて登録・週の残業の振分・休暇と振替休日の申請・付与予定の再判定)が、1回の呼び出しで1つの Command だけを dispatch する。業務の判定(承認の要否を含む)が Controller に無い。
- 休暇・振替休日は、設定と違う入口を呼ぶと 422。画面は設定で入口を選ぶので、利用者から見た動きは今と同じ。
- 利用者詳細で付与予定の条件を同時に変えても、付与予定に余分なイベントが残らない。
- 画面の動き・API の応答は今と同じ(経費の明細の 422 のキー、週の残業の応答の項目、休暇の入口の分離を除く)。
- 上のテストと backend・frontend の単体テスト全件が通る。変えた画面を通る E2E の spec が通る。

## 対象外
- 原則17の他の入口(設計レビューで発見。`docs/03-architecture.md` 3.10 に追記する): `UserController::store`(利用者の作成と所属の追加)、`CompanyCalendarController::store`・`regenerateYear`、`DeviceAdminController::bootstrapRegisterKey`、`AttendanceController::approveMonth`・`returnMonth`(ワークフローの有無で入口を選ぶ。論点5と同じ形)
- `GroupFeatureSyncService` が Handler から他の Handler を直接呼ぶ作り(ロールの割当の Handler・運用コマンドも使う)。別の候補
- 段階②(共通の一括操作の仕組み: 申請の一括取消・承認の一括・バックオフィスの担当の一括変更・一括完了・有給の一括付与・休暇の手動付与・備品の一括操作)・段階③(期間の休暇の申請)
- 付与ルール・付与表(有給・特別休暇)の直接の DB 書き込み(イベントが無い。原則1)。別の候補。
- ワークフローの Reactor(`ApproveWorkflowRequestOnPaidLeaveRequestApprovedReactor` 等)が `workflow_requests` を直接読む件(原則15の観点)。別の候補。
- 経費の明細と添付が別のトランザクションである件

## ドキュメントへの影響
- `docs/03-architecture.md` 3.10(既存の例外の更新・追記)
- `docs/09-usecases-paid-leave.md` ほか休暇・振替休日のユースケース: 申請の入口が設定で分かれ、設定と違う入口は拒否されること
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
- 2026-11-28 初版。
- 2026-11-28 実装前の独立設計レビュー(general-purpose。試験的なテストで検証)を反映:
  - 高: 承認の要否で Controller が入口を選ぶ案は原則17の文言に反する → ユーザーの判断「呼び出し側で判断し、実装は設定に応じてエラー」で論点5を作り直した。振替休日は申請の前に承認の処理が動かない → 申請と承認を2回の persist にした(記録の順序も今と同じ)。無効から有効へ戻しつつ Permission を変えると後退 → 前後のどちらかで有効なら可に。
  - 中: 付与予定の余分なイベントは今も出ている → ユーザーの判断で今回直す(Reactor の側で最後の1件だけ)。グループへの Feature の同期は仕組みを分けないため変えない。書き直すテストを列挙。見落とした入口(ロールの複製は本変更に含め、ほかは対象外に記録)。外部人事の確認を項目ごとの Handler にも。
  - 低: 項目のキー付きの拒否の前例、週の振分の順序と重複、再判定の planner、入口1回の検証の方法。

## 実装結果
未着手
