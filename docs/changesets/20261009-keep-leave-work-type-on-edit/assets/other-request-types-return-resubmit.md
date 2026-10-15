# 他の申請種別の差戻し・再提出・取消(2026-10-10、investigator調査・委譲元で一部検証済み)

| 種別 | 差戻し時 | 再提出 | 内容修正 | 取消 |
|---|---|---|---|---|
| 経費精算 | 業務側をRETURNED(`ExpenseClaimReturnOnWorkflowRequestReturnedReactor.php:20-28`) | 業務側APIが毎回新しいworkflow_requestをDraft→提出(`ExpenseClaimController.php:254-`・検証済み)。旧workflowはRETURNEDのまま | 差戻し中のみ明細を編集可 | 業務側の取消はDRAFT/IN_REVIEWのみ。workflow取消は連動なし |
| 月次勤怠 | 業務側をRETURNED | 業務側APIが新しいDraftを作成(`AttendanceController.php:806`)→`SubmitAttendanceMonth`(RETURNED可) | 差戻し月は日次を再編集可 | workflow取消に連動(`AttendanceMonthCancelOnWorkflowRequestCancelledReactor`、権限必要) |
| シフト交代 | 業務側をRETURNED | 専用経路なし(毎回新規申請) | 不可 | SUBMITTEDのみ。workflow取消は連動なし |
| 代休 | 業務側をRETURNED | 新規申請(新しい申請・usage) | 不可 | SUBMITTED/APPROVEDのみ |
| 備品貸出 | 業務側は変化なし | 同じworkflowを汎用submitで再提出し、Reactorが業務側をPENDINGへ戻す | 不可 | workflow取消に連動(PENDING→WITHDRAWN、APPROVED→CANCELLED) |
| 汎用申請 | 業務側なし | 同じworkflowを汎用submit | 不可 | workflowのみ |

- 申請詳細画面(`WorkflowRequestDetailPage.tsx:207-218`)は種別に関係なく「提出する」(draft/returned)・「取消」を表示する。
  業務側を持つ種別(休暇・シフト交代・経費・月次勤怠)の差し戻された行で「提出する」を押すと、業務側が更新されず承認時に失敗する(既存の不整合)。
- 標準パターン(経費・月次勤怠・有給の設計書): 差戻しで業務側をRETURNEDにし、再提出は同じ業務データについて新しいworkflow_requestを作る
  (旧workflowはRETURNEDのまま)。修正は差戻し中に業務データを編集する。取消は業務側の専用Commandで行う。
- **有給の設計書(`docs/09-usecases-paid-leave.md:315-316`)**: 「差戻し・取消(承認前): 未確定Usageを`CancelPaidLeaveUsage`で取り消す
  (再提出時は新規Usageを作成する)」。しかし実装の`ReturnPaidLeaveRequestHandler`は通知のみでUsageを取り消していない(検証済み)。
  特別休暇・代休の差戻しHandlerもUsageを取り消さない。→ 設計書と実装の乖離(既存不具合)。
- docs: `docs/10-usecases-workflow.md:64-78`(UC-W004 差戻し→修正して再申請、UC-W005 取消可能ステータス)。
