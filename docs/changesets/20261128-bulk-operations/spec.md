# bulk-operations(段階②: 一括操作を、入口1回=1つのCommandで、全部か無しかで処理する)

ステータス: 実装中

## 変更要望(原文)
> まとめて保存系の一括操作についてもAPIを用意して処理してください。時間がかかるものは非同期で動くようにしてください。
> 同様に修正をお願いします。問題があれば聞いてください

ユーザーの回答(2026-11-28):
- 非同期は、IDを指定せず条件で数千件を処理するような一括処理の場合だけ。現状の一括操作はすべて同期。対象は画面で選んで決める。
- 一括操作で一部の対象だけ失敗したときは、1件でも失敗したら全部戻す。

## 背景・目的
設計原則17の既存の例外のうち一括操作を是正する。画面が1件ずつ API を呼んで一括にしているものは、専用の API(1つの Command)に置き換える。結果は全部か無しか: どれか1件でも失敗したら何も確定させず、失敗した対象と理由の一覧を返す(利用者は対象を選び直してやり直す)。

## 現状(As-Is)(調査 2026-11-28、investigator)
| 一括操作 | 今の作り | 1件の処理 | 認可・本人確認 | 今の失敗の扱い |
|---|---|---|---|---|
| バックオフィスの一括完了 | `POST /backoffice-tasks/bulk-complete`(`BackOfficeTaskController::bulkComplete` :130-169)。Controller で件ごとに `ChangeBackOfficeTaskStatus` を `DB::transaction` で並べる。上限100 | `ChangeBackOfficeTaskStatusHandler` | 担当者本人(Controller で検査)。`permission:backoffice_task.execute,any` | 全部戻す |
| バックオフィスの担当の一括変更 | 画面が1件ずつ `POST /backoffice-tasks/{id}/assign`(`BackOfficeTaskListPage.tsx:302-321`、`Promise.allSettled`) | `AssignBackOfficeTask` | 同上 | 件ごと |
| 申請の一括取消 | 画面が1件ずつ `POST /workflow-requests/{id}/cancel`(`WorkflowRequestListPage.tsx:294-313`) | `CancelWorkflowRequest`(申請者本人。月次勤怠は `attendance.submission_revoke`) | Handler | 件ごと |
| 承認の一括 | 画面が件ごとに詳細を取得して、種類ごとの承認の API(月次勤怠・経費・汎用)を呼ぶ(`ApprovalsPage.tsx:265-282`、`useApprovals.ts:15-23`) | `ApproveWorkflowRequest` ほか(提出済みのみ・指定の承認者のみ) | `permission:approval.execute`、Handler | 件ごと |
| 休暇の手動付与(有給・特別休暇・代休) | 画面が利用者ごとに `POST /{paid-leave,special-leave,compensatory-leave}/grants`(`lib/bulkGrant.ts` の `runBulkGrant`)。グループの指定は画面でメンバーに展開(`GrantTargetPicker.tsx:53-56`) | `GrantPaidLeave`・`GrantSpecialLeave`・`GrantCompensatoryLeave`(代休は付与 ID を Controller で毎回採番するため、再送で二重付与になる) | `permission:leave.manage,any` | 件ごと |
| 付与予定の一括付与 | `POST /paid-leave/schedule-entries/bulk-grant`(`PaidLeaveScheduleController::bulkGrant` :332-397)。Controller で利用者ごとに `ApplyScheduledGrants` | 付与 ID は行の ID から決まる(再実行で二重にならない) | `permission:leave.manage,any` | 件ごと(組の片方だけの選択は全体を拒否) |
| 備品の一括操作(貸出・返却・貸与・移設) | `POST /assets/bulk`(`AssetBulkOperationController::store` :43-98)。Controller で資産ごとに `LendAsset`・`ReturnAsset`・`RelocateAsset`。トランザクション無し | 各 Handler | ルートに権限なし。`backoffice_lend`・`relocate` は Controller で `asset.manage`、`self_loan` は借用者=本人。**他人の貸出の返却(`return`)の `asset.manage` の検査が無い**(冒頭のコメントは必須と書いている。単件の返却の API も同じく見当たらない) | 件ごと |

- 画面の部品: `BulkActionBar`・`BulkActionDialog`(`BulkActionResult{succeededIds, failures}`)。手動付与の3画面は独自の結果表示(`BulkGrantResult`)。
- 既存の「一括操作」の集約はカレンダーの一括操作(`CalendarBulkOperationAggregate`)だけで、Handler が対象の集約へ直接記録する。
- テスト: backend の一括のテストはバックオフィスの一括完了・備品・付与予定だけ。申請の一括取消・承認の一括・手動付与は画面のテストだけ(E2E なし)。

## 設計前提の検証
- 正データと派生データ: 記録するイベントは各1件の処理と同じ。一括操作の記録用の新しい集約・イベントは作らない(全部か無しかなので、成否の内訳を正データとして残す必要がない。各件のイベントが残る)。
- 原則17: 入口は1つの Command。Handler が各件を、その文脈の1件の処理(共通の処理のクラス)で行い、1つのトランザクションにする。
- 原則15: 一括操作は、それぞれ対象の文脈の中の Command にする(文脈をまたぐ一括は作らない)。承認の一括は、対象の種類によって承認の入口が違う(月次勤怠・経費・汎用)ので、論点4で扱う。
- 横展開: 7つの一括操作すべて。

## 仕様検討

### 論点1: 全部か無しかの処理と、失敗の一覧
- 決定:
  - 一括の Handler は、各件を1件の処理と同じ検査・記録で順に行う。各件はセーブポイント(入れ子のトランザクション)の中で行い、失敗したら理由を集めて次の件へ進む(すべての失敗を一度に返すため)。
  - 1件でも失敗があれば、最後に例外 `BulkOperationRejected`(失敗の一覧を持つ)を投げ、全体を戻す。応答は 422 で、`errors` に `items.{対象のID}` をキーとして理由を返す(既存の `fieldErrors` の形。`message` は「選んだ対象のうち、処理できないものがあります。」)。
  - 失敗が無ければすべて確定し、200 で処理した対象の一覧を返す。
  - 各件の処理: 一括の Handler から、同じ文脈の1件の Command を CommandBus で dispatch する(CommandBus の dispatch は `DB::transaction` なので件ごとのセーブポイントになる。入口1回=1つの Command の検査は入れ子の深さ0だけを数えるので成り立つ。前例 `ApplyScheduledGrantsHandler` が `GrantPaidLeaves` を dispatch)。1件の Handler の切り出しはしない(1件の API と一括で同じ検査になる)。
  - 件の失敗として集める例外は `DomainRuleException`・`ValidationException`(メッセージを平らにする)・`ModelNotFoundException`・件ごとの認可の拒否だけ。`QueryException`・`DeadlockException`・`Error` は集めずにそのまま投げ直す(500。入れ子のトランザクションでデッドロックが起きると Laravel はセーブポイントまで戻さないため、次の件へ進むとトランザクションの外で書き込みが続く)。既存の `ApplyScheduledGrantsHandler` の `Throwable` の捕捉と「付与は成功したが反映に失敗」の部分成功の処理は削除する。
  - 連鎖で既に済んだ件: 一括の開始の時点で対象だった件が、同じ一括の前の件の連鎖で既に目的の状態になっていたら(例: まとめ申請の兄弟の承認で他の兄弟のワークフローも承認済みになる)、成功として飛ばす。
  - 対象の ID は並べ替えて処理する(ロックの順序をそろえる)。すべての一括の API で ID の `distinct` を必須にする。
  - 応答: 失敗のときは 422 で `code: "bulk_operation_rejected"`、`message`、`errors` に `items.{対象のID}`(画面で入力の検証の 422 と見分ける)。成功のときは 200 で `{ data: [処理した対象の ID と結果の要約] }`(api-design に沿って各 API でそろえる)。
- 未確定・要確認事項: なし

### 論点2: 一括の Command と API
| 一括操作 | Command(文脈) | API | 対象の上限 |
|---|---|---|---|
| バックオフィスの一括完了 | `CompleteBackOfficeTasks`(BackOffice) | 既存 `POST /backoffice-tasks/bulk-complete` | 100(今のまま) |
| バックオフィスの担当の一括変更 | `AssignBackOfficeTasks`(BackOffice) | `POST /backoffice-tasks/bulk-assign` | 100 |
| 申請の一括取消 | `CancelWorkflowRequests`(Workflow) | `POST /workflow-requests/bulk-cancel` | 100 |
| 承認の一括 | `ApproveWorkflowRequests`(Workflow)(論点4) | `POST /workflow-requests/bulk-approve` | 100 |
| 休暇の手動付与 | `GrantPaidLeaveToUsers`(PaidLeaveAccount)・`GrantSpecialLeaveToUsers`(SpecialLeave)・`GrantCompensatoryLeaveToUsers`(CompensatoryLeaveAccount) | `POST /{paid-leave,special-leave,compensatory-leave}/grants/bulk`(本文に `user_ids` または `group_id`) | 500 |
| 付与予定の一括付与 | `ApplyScheduledGrantsInBulk`(PaidLeaveSchedule) | 既存 `POST /paid-leave/schedule-entries/bulk-grant` | 500 |
| 備品の一括操作 | `ApplyAssetBulkOperation`(Asset) | 既存 `POST /assets/bulk` | 200 |
- 上限は検証(`max`)で 422。権限は1件の API と同じものをルートに付ける。
- 既存の1件の API は残す。
- 未確定・要確認事項: なし

### 論点3: 休暇の手動付与のグループの指定
- 決定(有給・特別休暇): グループの指定は入口で `user_ids` に展開する(API を実行した時点の直接の所属のメンバー。退職者は除く。子グループは含めない)。展開は利用者管理の参照のサービスで行い、Command には `user_ids` を渡す(休暇の文脈が所属のテーブルを読まない。原則15)。グループの指定には今の画面と同じく `group.view` を要する。展開した後の件数で上限を検査する。失敗の応答に利用者の表示名を含める。
- 決定(代休): グループの指定をやめ、個人の指定だけにする(代休はその日の休日出勤の実績が利用者ごとに要り、全部か無しかではグループの指定がほぼ必ず失敗するため)。
- 二重付与の防止: 3種類とも、付与 ID を「画面が送る `request_id`(UUID)と利用者の ID から決まる ID」にする。同じ ID の付与が既にあれば、何もせず成功として扱う(冪等。再送で二重にならず、「何も処理していない」の誤表示も出ない)。1件の付与の API も `request_id` を受けて同じく決める(無ければ今どおり採番)。
- 未確定・要確認事項: なし

### 論点4: 承認の一括
- 事実: 承認の一覧の行は種類(`subject_type`)ごとに承認の入口が違う(月次勤怠 `approveMonth`、経費 `approveExpenseClaim`、それ以外は汎用 `ApproveWorkflowRequest`)。月次勤怠の承認の入口は、ワークフローの無い古い月のための分岐を持つ(変更セット`20261128-remaining-entrypoints`で古い月を移行し、ワークフローの経路だけにする)。
- 決定: `ApproveWorkflowRequests` はワークフローの申請の ID を受け、各件を `ApproveWorkflowRequest` で行う。業務側の承認(月次勤怠・経費・休暇3種・振替休日・備品)は、今と同じくワークフローの承認のイベントを受ける Reactor の連鎖で行われる(設計レビューで確認済み: 経費の承認の入口とワークフローのある月の承認の入口は、どちらも `ApproveWorkflowRequest` を1回 dispatch するだけで、汎用の承認と同じ)。承認の一覧の行はワークフローの申請だけなので、ワークフローの無い月は一括に来ない。
- まとめ申請の兄弟を同時に選んだときは、論点1の「連鎖で既に済んだ件は成功として飛ばす」で通る。
- `useApprovals.ts` の古いコメント(subject_type のある行に汎用の承認を呼んではならない)は実装と合わないので消す。
- 画面: 件ごとに詳細を取得する処理をやめ、選んだワークフローの申請の ID をまとめて送る。
- 未確定・要確認事項: なし

### 論点4b: ワークフローの取消が経費・振替休日に伝わらない(既存の不具合)
- 事実(設計レビューで検証): ワークフローの取消を受けて業務側を取り消す Reactor は、休暇・備品・月次勤怠の分しか無い。申請一覧から経費・振替休日のワークフローを取り消すと、ワークフローは取消になるが経費・振替休日は申請中のまま残る(単件の API でも同じ)。
- 決定: ワークフローの取消を受けて経費・振替休日を取り消す Reactor を足す(`viaReactor` で冪等)。一括の取消と単件の取消の両方で直る。
- 未確定・要確認事項: なし

### 論点5: 備品の他人の貸出の返却の権限
- 事実: 他人の貸出の返却に `asset.manage` の検査が無い(冒頭のコメントは必須と書く)。単件の返却の API も同じく見当たらない。
- 事実(設計レビューで検証): 単件の `POST /assets/{asset}/return` にも、ルートの権限・Controller の検査・Handler の検査のいずれも無い(ルートのコメントは「Controller 内で認可」と書くが未実装)。
- 決定: 返却の Handler(`ReturnAsset`)で、借用者本人でなければ全社の `asset.manage`(`hasGlobalPermission`。一括の今の判定に合わせる)を要する検査を行う(1件・一括の両方に効く)。
- 未確定・要確認事項: なし

### 論点5b: バックオフィスの一括完了の今の挙動
- 決定: 完了済みのタスクは飛ばす(成功として扱い、イベントを記録しない。今の挙動)。「担当者本人のタスクだけ」の検査は一括の Handler に置く(1件のステータス変更の API には今も無いので、加えない)。担当の一括変更の通知は件ごとに届く(今と同じ)。
- 未確定・要確認事項: なし

### 論点6: 画面
- 決定:
  - `BulkActionDialog` に全部か無しかの表示の形を足す(今の部分成功の表示「N件ともできませんでした」「できなかったN件は選択したまま」と `onSucceeded` の呼び出しは、全部か無しかの形では使わない)。実行は一括の API を1回呼ぶ。成功ならすべて成功として閉じる。422 で `code: "bulk_operation_rejected"` なら、失敗した対象と理由を一覧で出し、何も確定していないことを示す文「選んだ対象のうち、処理できないものがあります。何も処理していません。対象を選び直してください。」を出す。選択はそのまま残す。
  - 手動付与の3画面も `BulkActionDialog`(または同じ結果の表示)にそろえ、独自の `runBulkGrant` を削除する。
  - 備品の一括操作の画面(5画面)は、全部か無しかの結果に合わせて表示を直す。
- 未確定・要確認事項: なし

## 仕様確定事項(まとめ)
- backend:
  - 論点2の Command・Handler と API。Handler は論点1の作り(各件をセーブポイントで行い失敗を集め、1件でも失敗なら `BulkOperationRejected` で全体を戻す)。
  - 共通の例外 `App\Domain\EventSourcing\BulkOperationRejected`(失敗の一覧: 対象の ID と理由)と、その 422 の応答への変換(`bootstrap/app.php`)。
  - 各件の処理を1件の Handler から共通の処理のクラスに切り出す(1件の API は今と同じ結果)。
  - 手動付与のグループの展開と、付与 ID の決め方(論点3)。
  - 備品の返却の認可(論点5)。
  - 既存の Controller のループ(`bulkComplete`・`bulkGrant`・`AssetBulkOperationController`)は1つの Command の dispatch にする。
  - `docs/03-architecture.md` 3.10 の既存の例外から一括操作を外す。
- frontend: 論点6。
- テスト(`domain-test`):
  - 一括の Command ごとに: 全件成功で全件のイベント、1件の失敗で何も記録されず 422 の `errors` にその対象と理由、複数の失敗がすべて返る、上限を超えると 422、入口1回=1つの Command(`assertSingleTopLevelCommand`)。
  - 承認の一括: 種類の違う申請(休暇・振替休日・経費・月次勤怠・汎用)を混ぜて、業務側まで承認される(業務シナリオのテスト)。
  - 手動付与: グループの指定で付与の時点のメンバーに付与される。同じ `request_id` の再送で二重にならない。
  - 備品: 他人の貸出の返却が管理者でないと拒否される。
  - 画面: 一括の API を1回呼ぶ、422 で失敗の一覧と文が出て選択が残る、成功で閉じる。
  - 書き直す既存のテスト: backend `PaidLeaveBundledGrantScenarioTest`・`BackOfficeTaskAllowedStatusesTest`・`BackOfficeTaskTest`・`AssetApiTest`・`PaidLeaveScheduleControllerTest`、frontend 備品の一括の5画面・`PaidLeaveSchedulePage.test`・`BulkActionDialog.test`・`GrantTargetPicker` のテスト(部分成功を前提にしている)、E2E `scenario-15-paid-leave-schedule.spec.ts:229-241`(一括付与の API を直接呼び 200 と failure_count>0 を期待)。
  - 追加: まとめ申請の兄弟を同時に選んだ承認の一括が通る。ワークフローの取消で経費・振替休日も取り消される(単件・一括)。デッドロック等の例外は集めずに 500。
  - E2E: 新しい E2E は足さない(業務の流れは backend のシナリオのテストで確かめる)。変えた画面を通る spec だけを流す。

## 受け入れ条件
- 7つの一括操作が、それぞれ1回の API 呼び出し・1つの Command で処理され、どれか1件でも失敗すると何も確定せず、失敗した対象と理由が画面に出る。
- 画面が1件ずつ API を呼ぶ一括は無くなる。
- 他人の貸出の返却は管理者だけができる。
- 上のテストと backend・frontend の単体テスト全件が通る。変えた画面を通る E2E の spec が通る。

## 対象外
- 非同期の一括(条件で数千件を処理する一括。現状は該当なし)
- カレンダーの一括操作(既に1つの Command。重さの非同期化はユーザーの判断で不要)
- 差戻し・却下の一括(今も無い)

## ドキュメントへの影響
- `docs/03-architecture.md` 3.10(既存の例外の更新)
- `docs/34`(備品。:90 の「部分成功を許容」を直す)
- 各一括操作のユースケースの docs(docs/09・10・11・14 等。実装時に該当の節を grep で特定): 一括は全部か無しかで、失敗した対象と理由を返すこと。休暇の手動付与はグループを backend で展開すること。

## モック・アセット
なし

## 実装対象
- backend: `app/Domain/{BackOffice,Workflow,PaidLeaveAccount,SpecialLeave,CompensatoryLeaveAccount,PaidLeaveSchedule,Asset,EventSourcing}`、各 Controller、`routes/api.php`、`bootstrap/app.php`、テスト
- frontend: `components/BulkActionDialog`、`pages/backOffice/BackOfficeTaskListPage`、`pages/workflow/WorkflowRequestListPage`、`pages/approvals/ApprovalsPage`、`pages/paidLeave/PaidLeavePolicyPage`・`PaidLeaveSchedulePage`、`pages/specialLeave/SpecialLeaveAdminPage`、`pages/compensatoryLeave/CompensatoryLeaveAdminPage`、`pages/asset/bulk/*`、`lib/bulkGrant.ts`(削除)、api・hooks

## 検証方法
- backend: CI と同じ条件で全件
- frontend: tsc、単体テスト全件
- E2E: 変えた画面を通る spec(scenario-15・14 ほか grep で特定)

## レビュー履歴
- 2026-11-28 初版。
- 2026-11-28 実装前の独立設計レビュー(general-purpose。セーブポイントの仕組みを試験的なテストで確認)を反映:
  - 高: まとめ申請の兄弟を2件以上選ぶと全体が失敗 → 連鎖で済んだ件は成功として飛ばす。ワークフローの取消が経費・振替休日に伝わらない既存の不具合 → Reactor を足す(論点4b)。代休のグループの指定は全部か無しかでほぼ必ず失敗 → 代休は個人の指定だけ(ユーザーに提案し了承)。
  - 中: 二重付与の防止を3種類に、再送は冪等に。グループの展開は入口で利用者管理のサービスにより(原則15)。集める例外を限定。各件は1件の Command の dispatch で(切り出し不要)。一括完了の今の挙動を明記。返却の認可は単件にも無い。応答の `code`。既存のテストと E2E の影響を列挙。
  - 低: 承認の一括は経費・月次も汎用と同じ(確認済み)、ID の distinct、成功の応答の形、docs/34。

## 実装結果
- バックオフィス・ワークフロー・論点4b(担当: BackOffice/Workflow):
  - backend: `CompleteBackOfficeTasks`・`AssignBackOfficeTasks`・`CancelWorkflowRequests`・`ApproveWorkflowRequests`(各 Handler は `BulkItemRunner` で各件を既存の1件の Command で dispatch。連鎖で済んだ件は skipped)。API は `bulk-complete`(応答を `{data:[{id,result}]}` に変更)・`bulk-assign`・`workflow-requests/bulk-cancel`・`bulk-approve`(上限100・distinct)。
  - 論点4b: `CancelExpenseClaimOnWorkflowRequestCancelledReactor`(ExpenseClaim)・`CancelShiftSwapRequestOnWorkflowRequestCancelledReactor`(ShiftSwap)を追加し、`CancelExpenseClaim`・`CancelShiftSwapRequest` に `viaReactor`・`initiatedByUserId` を追加(冪等)。
  - frontend: BackOfficeTaskListPage・WorkflowRequestListPage・ApprovalsPage を一括 API 1回・`allOrNothing` に。テスト: backend `BackOfficeTaskBulkOperationsTest`・`WorkflowRequestBulkOperationsTest`(混在の業務シナリオ・兄弟含む)・`WorkflowRequestCancelPropagationTest`、既存の BackOfficeTask 系2件と画面3件を更新。docs/10・11 を更新。
- 休暇の手動付与・付与予定の一括付与・備品の一括操作・返却の認可(担当: PaidLeaveAccount/SpecialLeave/CompensatoryLeaveAccount/PaidLeaveSchedule/Asset):
  - backend: `GrantPaidLeaveToUsers`・`GrantSpecialLeaveToUsers`・`GrantCompensatoryLeaveToUsers`(各 Handler は `BulkItemRunner` で1件の付与を dispatch)、`POST /{paid-leave,special-leave,compensatory-leave}/grants/bulk`(上限500・distinct、有給・特別休暇はグループを入口で `GroupMemberExpander`(UserManagement。直接の所属・退職者除外・`group.view`)により `user_ids` に展開、代休は個人のみ)。付与 ID は `ManualGrantId`(request_id+利用者 ID の UUIDv5)で、1件の付与(`idempotent`)も `request_id` を受け、同じ ID は何もせず成功。
  - `ApplyScheduledGrantsInBulk`(既存 bulk-grant を1 Command に。上限500。組の片方だけの選択は今どおり入口で422)。`ApplyScheduledGrantsHandler` の `Throwable` の捕捉・「付与は成功したが反映に失敗」の処理を削除(付与前の検査の失敗だけ `failed`)。`ApplyAssetBulkOperation`(既存 `/assets/bulk`。上限200・distinct。応答 `{operation, data:[{asset_id,result}]}`)。`ReturnAssetHandler` が借用者本人以外は全社 `asset.manage` を要する(単件・一括。`AuthorizationException`=403)。
  - frontend: 手動付与3画面を `BulkActionDialog`(allOrNothing)に(`lib/bulkGrant.ts` 削除、`GrantTargetPicker` は group_id を送り `allowGroup=false` で代休は個人のみ)、`PaidLeaveSchedulePage`、備品の一括5画面(新部品 `BulkOperationError`)。docs/09・34、E2E scenario-15(422)・scenario-00(確認ダイアログ)を更新。テストは各 Command(全件成功・失敗の全件返却・上限・distinct・入口1回=1 Command)、グループ展開・再送の冪等、返却の認可を追加。
