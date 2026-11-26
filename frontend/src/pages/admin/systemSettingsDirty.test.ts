import { describe, expect, it } from 'vitest'
import type { SystemSettings } from '../../api/types'
import { isSystemSettingsDirty, systemSettingsToForm } from './systemSettingsDirty'

const settings: SystemSettings = {
  default_timezone: 'Asia/Tokyo',
  default_work_style_id: null,
  attendance_submission_deadline_day: 5,
  attendance_month_close_deadline_day: 10,
  m365_tenant_id: null,
  m365_client_id: null,
  m365_client_secret_configured: false,
  m365_mock_enabled: false,
  notification_mail_enabled: false,
  notification_mail_sender_address: null,
  notification_mail_sender_name: null,
  paid_leave_requires_approval: true,
  special_leave_requires_approval: true,
  shift_swap_requires_approval: true,
  attendance_requires_approval: true,
  expense_claim_requires_approval: true,
  prohibits_self_privileged_role_assignment: false,
  compensatory_leave_requires_approval: true,
}

describe('isSystemSettingsDirty', () => {
  const base = systemSettingsToForm(settings)
  it('変更なしはfalse', () => {
    expect(isSystemSettingsDirty(base, { ...base })).toBe(false)
  })
  it('1項目の変更はtrue', () => {
    expect(isSystemSettingsDirty(base, { ...base, defaultTimezone: 'UTC' })).toBe(true)
    expect(isSystemSettingsDirty(base, { ...base, ms365ClientSecret: 'secret' })).toBe(true)
    expect(isSystemSettingsDirty(base, { ...base, paidLeaveRequiresApproval: false })).toBe(true)
  })
  it('元に戻すとfalse', () => {
    const changed = { ...base, submissionDeadlineDay: '7' }
    expect(isSystemSettingsDirty(base, { ...changed, submissionDeadlineDay: '5' })).toBe(false)
  })
})
