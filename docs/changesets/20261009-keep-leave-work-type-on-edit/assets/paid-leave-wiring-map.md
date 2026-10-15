# 有給の配線替えの変更箇所(2026-10-10、investigator調査・委譲元で主要箇所は既検証)

## 入口と処理
- 申請(承認あり): `PaidLeaveController::storeRequest`(:581-627)→DraftWorkflowRequest(:608)→`PaidLeaveRequestOnWorkflowRequestDraftedReactor`がRequestPaidLeave。
- 申請(承認不要): 同:629-660、1トランザクションでRequestPaidLeave(:642)→ApprovePaidLeaveRequestCommand(approvedBy=null、:654)。
- `RequestPaidLeaveHandler`: 残数=DesignatePaidLeaveUsage(:126-138)/勤怠直接書込(:169-196)・計算(:158-161)/インライン提出(:143-153)/検証: 勤務予定日(:68-85)・同日重複(有給:91-99、特別:101-109、他文脈の直接読取)・締め(:115-119)・時間休(:198-229)。
- `ApprovePaidLeaveRequestHandler`(:48-85): SUBMITTEDのみ・承認者一致、paid_leave_usagesからusage取得→ConfirmPaidLeaveUsage、勤怠計算。
- `ReturnPaidLeaveRequestHandler`(:36-44): 通知のみ(二重通知の片方)。
- `CancelPaidLeaveRequestHandler`(:55-122): 本人/管理者・状態・締め、CancelPaidLeaveUsage、勤怠直接書込、インラインでCancelWorkflowRequest。
- Reactor: `PaidLeaveApprovalOnWorkflowRequestApprovedReactor`(兄弟申請をpaid_leave_requestsで読んで承認、兄弟ワークフロー承認:53-89)、`PaidLeaveReturnOnWorkflowRequestReturnedReactor`。
- Controller承認/差戻し(:711-751)は`submittedWorkflowRequestId`(:854-868、workflow_requests直読)経由。取消:761、管理者取消:781。

## paid_leave_requests
- 書き手: `PaidLeaveUsageAllocationProjector` createPaidLeaveRequestIfNeeded(:196-217)、updatePaidLeaveRequestStatus(:224-236)、onWorkflowRequestReturned(:176-188)。
- 列: id, request_group_id, user_id, approver_user_id, status, leave_type, target_date, hours, requested_days, reason, submitted_at, approved_at, returned_at, cancelled_at, timestamps。
- 読み手: PaidLeaveApprovalGuard(:18-26)、LeaveUsageQuery(:18-60)、WorkflowRequestController(:266,:288,:322,:336-353)、WorkflowRequestResource:94、他休暇の重複チェック、PaidLeaveController、Reactor。
- FK: paid_leave_usages.paid_leave_request_id・attendance_day_id(撤去対象)。

## 残数側
- Designate(新規uuidのusageIdを返す。申請IDでusageを引けない)、Confirm(確定済み・取消済みで例外、残数不足は黙って部分充当)、Cancel(取消済みで例外)。
- 集約は申請ID→usageIdを保持していない。問い合わせメソッド・viaReactorなし。

## 通知
- 差戻し: ReturnWorkflowRequestHandler(:43-49)とReturnPaidLeaveRequestHandler(:40-44)の二重。取消: CancelWorkflowRequestHandler(:59-66、申請中のみ承認者宛)。申請・承認はワークフロー汎用のみ。

## 期待値の変更が要るテスト
- PaidLeaveAccount/PaidLeaveRequestTest(work_type期待 :86,:112,:220,:375,:563、取消後null :317,:385,:415、承認不要 :537-569、残数不足の自動承認 :571-589、取消でworkflowも取消 :592、承認2回 :643)
- PaidLeaveAccount/PaidLeaveAdminCancelRequestTest(:54-120)、PaidLeaveUsageCancellationRecalculationTest(:60-90)、PaidLeaveAccountProjectionTest、PaidLeaveWarningBatchTest(:101)
- Workflow/WorkflowRequestSubjectTest(:296-320、:384-416、:455-467)、Attendance/HalfDayLeavePrescribedMinutesTest(:14-51)
