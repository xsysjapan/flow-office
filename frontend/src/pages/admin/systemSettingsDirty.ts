import type { SystemSettings } from '../../api/types'

export interface SystemSettingsFormValues {
  defaultTimezone: string
  submissionDeadlineDay: string
  monthCloseDeadlineDay: string
  ms365TenantId: string
  ms365ClientId: string
  ms365ClientSecret: string
  ms365MockEnabled: boolean
  notificationMailEnabled: boolean
  notificationMailSenderAddress: string
  notificationMailSenderName: string
  paidLeaveRequiresApproval: boolean
  specialLeaveRequiresApproval: boolean
  shiftSwapRequiresApproval: boolean
  attendanceRequiresApproval: boolean
  expenseClaimRequiresApproval: boolean
  prohibitSelfPrivilegedRoleAssignment: boolean
  compensatoryLeaveRequiresApproval: boolean
}

/** 読み込んだ設定から、フォームの初期値(比べる元)を作る。 */
export function systemSettingsToForm(data: SystemSettings): SystemSettingsFormValues {
  return {
    defaultTimezone: data.default_timezone,
    submissionDeadlineDay: String(data.attendance_submission_deadline_day),
    monthCloseDeadlineDay: String(data.attendance_month_close_deadline_day),
    ms365TenantId: data.m365_tenant_id ?? '',
    ms365ClientId: data.m365_client_id ?? '',
    ms365ClientSecret: '',
    ms365MockEnabled: data.m365_mock_enabled,
    notificationMailEnabled: data.notification_mail_enabled,
    notificationMailSenderAddress: data.notification_mail_sender_address ?? '',
    notificationMailSenderName: data.notification_mail_sender_name ?? '',
    paidLeaveRequiresApproval: data.paid_leave_requires_approval,
    specialLeaveRequiresApproval: data.special_leave_requires_approval,
    shiftSwapRequiresApproval: data.shift_swap_requires_approval,
    attendanceRequiresApproval: data.attendance_requires_approval,
    expenseClaimRequiresApproval: data.expense_claim_requires_approval,
    prohibitSelfPrivilegedRoleAssignment: data.prohibits_self_privileged_role_assignment,
    compensatoryLeaveRequiresApproval: data.compensatory_leave_requires_approval,
  }
}

/** システム設定フォームに、読み込んだ値(保存後は送った値)から変更があるか。 */
export function isSystemSettingsDirty(base: SystemSettingsFormValues, current: SystemSettingsFormValues): boolean {
  return (Object.keys(base) as (keyof SystemSettingsFormValues)[]).some((key) => base[key] !== current[key])
}
