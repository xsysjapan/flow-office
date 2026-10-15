# 差戻し・再提出と重複チェックの現状調査(2026-10-10、investigator調査・委譲元で主要箇所を検証済み)

## 統合ワークフローの再提出
- 再提出は同じworkflow_requestの再Submitのみ(`SubmitWorkflowRequestHandler.php:32`でDRAFT/RETURNEDを許可・検証済み)。
  Commandは`SubmitWorkflowRequest`(approverUserIdのみ変更可)、API `POST /workflow-requests/{id}/submit`。
  form_data(対象日・取得単位)は変更できない。

## 休暇の差戻し・再提出・取消
- 差戻し: `WorkflowRequestReturned` → `{PaidLeave,SpecialLeave,CompensatoryLeave}ReturnOnWorkflowRequestReturnedReactor.php:20` →
  有給は`PaidLeaveUsageAllocationProjector.php:176-188`、特別は`SpecialLeaveRequestProjector.php:47-52`、代休は
  `CompensatoryLeaveRequestProjector.php:48-53`で休暇申請をRETURNEDにする。usage・勤怠は変更しない。
- 再提出後: workflowはSUBMITTEDに戻るが、休暇申請をSUBMITTEDに戻す処理が無い(休暇側でWorkflowRequestSubmittedを
  購読していない・検証済み)。承認Handlerが SUBMITTED 以外を拒否するため(`ApprovePaidLeaveRequestHandler.php:50`・検証済み、
  Special:42、Comp:46)、**再提出した休暇は承認が必ず失敗する(既存不具合)**。再提出時の承認者も休暇側に反映されない。
- 取消: 休暇の取消HandlerはRETURNEDを拒否(`CancelPaidLeaveRequestHandler.php:61`ほか)。workflowの取消は休暇に連動しない
  (WorkflowRequestCancelledの購読はAsset・AttendanceMonthのみ・検証済み)。→ 差し戻された休暇はusageが残ったまま孤児化する。
- 残高: 有給の`pending_days`は未確定・未取消のusageを含むためRETURNEDも申請中として計上(`PaidLeaveBalanceProjector.php:161-173`)。
  特別・代休のgrant残は承認時のみ減る。`PaidLeaveApprovalGuard.php:21`はRETURNEDを未承認扱いし月次提出をブロック。
- フロント: `WorkflowRequestDetailPage.tsx:207-216`に「提出する」(draft/returned)・「取消」。休暇各ページは submitted のみ取消可
  (`MyPaidLeavePage.tsx:283`ほか)で、RETURNED行に操作導線が無い。
- 承認不要設定時はworkflowを作らず申請・承認を一括で行うため差戻しは発生しない。

## 重複チェック
- 有給 `RequestPaidLeaveHandler.php:91-109`: 有給・特別のSUBMITTED/APPROVEDのみ(代休を見ない)。
- 特別 `RequestSpecialLeaveHandler.php:176-203`: 有給・特別のみ。代休 `RequestCompensatoryLeaveHandler.php:106-129`: 3種。
- RETURNEDは除外されるため差戻し後に同日へ新規申請でき、usageが二重になる。
- 時間休は時間数(hours)のみで開始・終了時刻を持たない。→ 時間帯の重なりは判定不可。
  (ユーザー決定: 時間休は時間数の合計で判定する)
