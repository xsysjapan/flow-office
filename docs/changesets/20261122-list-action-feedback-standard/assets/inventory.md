# 棚卸し結果と適用後(パスは frontend/src/pages/ からの相対)

## 一括操作
| 画面 | 操作 | 現状 | 適用後 |
|---|---|---|---|
| approvals/ApprovalsPage.tsx | まとめて承認 | Card actions直置き、確認なし、成功は選択解除のみ、部分失敗で成功分も選択に残る | BulkActionBar+BulkActionDialog(確認文「承認すると取り消せません」)、件数トースト、失敗分のみ選択に残す |
| workflow/WorkflowRequestListPage.tsx | まとめて取消 | Card actions直置き、ConfirmActionDialogで理由入力 | BulkActionBar+BulkActionDialog(理由入力)、件数トースト、部分失敗の内訳 |
| backOffice/BackOfficeTaskListPage.tsx | 担当者を設定 / 完了 | バー内UserPicker、確認なし | BulkActionBar+BulkActionDialog(担当者を変更・完了にする)、件数トースト |
| paidLeave/PaidLeaveSchedulePage.tsx | 一括付与 | PaidLeaveScheduleBulkGrantBar(確認・結果サマリあり) | BulkActionBar+BulkActionDialog(既存の確認文・結果内訳を移す)、件数トースト |
| attendance/WeekAttendancePage.tsx, AttendanceMonthDetailPage.tsx | 選択→申請画面へ遷移 | AttendanceSelectionActionBar | BulkActionBar(操作は遷移リンクのまま) |
| asset/bulk/*(スキャン式) | 確定 | 確認なし、結果の成功N件/失敗N件を表示 | 結果表示は維持し、成功トーストを追加 |

## 行内操作
| 画面 | 操作 | 現状 | 適用後 |
|---|---|---|---|
| admin/ExternalIntegrationConnectionsPage.tsx | 編集・有効/無効・削除 | 行内に3ボタン、切替は確認なし | 「⋯」メニュー、切替は即実行+トースト、削除は確認+トースト |
| admin/DeviceListPage.tsx | 詳細・失効・削除 | 行内に3ボタン | 「⋯」メニュー、失効・削除は確認+トースト |
| integrations/MyIntegrationsPage.tsx | トークン再発行・停止 | 再発行は確認なし | 「⋯」メニュー、再発行に確認追加(旧トークンが失効する旨)+トースト |
| paidLeave/PaidLeavePolicyPage.tsx, specialLeave/SpecialLeaveAdminPage.tsx | 自動付与トグル | 確認なし、知らせなし | 即実行+トースト |
| expense/ExpenseClaimListPage.tsx, ExpenseEntryPresetListPage.tsx, workCalendar/WorkCalendarListPage.tsx | 削除 | 確認あり、知らせなし | 成功トースト追加 |
| paidLeave/MyPaidLeavePage, compensatoryLeave/MyCompensatoryLeavePage, specialLeave/MySpecialLeavePage | 取消 | 確認あり、知らせなし | 成功トースト追加、申請送信の成功表示もトーストへ |

## 詳細・編集画面
| 画面 | 操作 | 現状 | 適用後 |
|---|---|---|---|
| workflow/WorkflowRequestDetailPage.tsx | 提出・承認・差戻し・取消・却下 | 承認・提出は確認なし、知らせなし | 承認に確認追加、全操作に成功トースト |
| expense/ExpenseClaimDetailPage.tsx | 提出・承認・差戻し・取消 | 同上 | 同上 |
| approvals/ApprovalsPage.tsx(詳細Dialog) | 承認・差戻し | 承認は即時 | 承認に確認追加、成功トースト |
| backOffice/BackOfficeTaskDetailPage.tsx | 担当者・ステータス・確定の取消 | 知らせなし、許可外の選択肢、エラーが上部 | 論点5 |
| asset/AssetDetailPage.tsx | 貸与・返却・修理・紛失・廃棄・削除・移設・設置等 約20 | 確認あり、知らせなし | 成功トースト追加 |
| attendance/AttendanceDayPage.tsx | 実績保存・打刻削除・行内メニュー | 作成時のみ文で表示 | 全操作に成功トースト(文の表示は置き換え) |
| attendance/AttendanceMonthDetailPage.tsx | 提出 | Dialog内で確定 | 成功トースト追加 |
| admin/UserRoleEditPage.tsx | 各項目の保存・所属変更 | 項目ごとのBadge「保存しました」 | トーストへ置き換え |
| admin/SystemSettingsPage.tsx | 保存 | 「保存しました。」の文 | トーストへ置き換え |
| admin/GroupDetailPage.tsx, admin/UserManagementAccessPage.tsx | 予約取消・解除・取消 | 確認あり、知らせなし | 成功トースト追加 |
| workCalendar/WorkCalendarDetailPage.tsx | 削除・年度再作成・公開・複製・出典設定 | 公開・複製は確認なし | 公開に確認追加、全操作に成功トースト |
| workCalendar/CalendarBulkOperationsPage.tsx | 実行・取消 | プレビューリセットのみ | 成功トースト追加 |
| 各編集・作成画面(PaidLeaveGrantRuleEdit, ExpenseCategoryEdit, ExpenseEntryPresetEdit, RequestTypeEdit, AssetEdit, WorkCalendarCreate 等) | 保存 | 遷移のみ | 遷移+成功トースト |
| paidLeave/PaidLeavePolicyPage.tsx, specialLeave/SpecialLeaveAdminPage.tsx, compensatoryLeave/CompensatoryLeaveAdminPage.tsx | 付与・種別作成・取消・ルール削除 | 一部のみ表示 | 全操作に成功トースト |
| admin/AdminCommandsPage.tsx | 実行 | 確認あり、閉じるのみ | 「実行を依頼しました」のトースト追加 |

実装時は、上記に無い変更操作も`grep`(`useMutation`・`mutate(`・`mutateAsync(`)で洗い出し、同じ規則を適用する。
