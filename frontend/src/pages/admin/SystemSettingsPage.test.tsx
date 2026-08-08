import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as systemSettingsApi from '../../api/systemSettings'
import type { SystemSettings } from '../../api/types'
import { SystemSettingsPage } from './SystemSettingsPage'

const settings: SystemSettings = {
  default_timezone: 'Asia/Tokyo',
  default_work_style_id: '33333333-3333-3333-3333-333333333333',
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
  prohibit_self_privileged_role_assignment: false,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(systemSettingsApi, 'fetchSystemSettings').mockResolvedValue(settings)

  return render(
    <QueryClientProvider client={queryClient}>
      <SystemSettingsPage />
    </QueryClientProvider>,
  )
}

describe('SystemSettingsPage', () => {
  it('shows the current default timezone', async () => {
    renderPage()

    expect(await screen.findByLabelText('既定タイムゾーン')).toHaveValue('Asia/Tokyo')
  })

  it('saves the updated default timezone', async () => {
    vi.spyOn(systemSettingsApi, 'updateSystemSettings').mockResolvedValue({
      ...settings,
      default_timezone: 'America/Los_Angeles',
    })
    renderPage()

    const input = await screen.findByLabelText('既定タイムゾーン')
    await userEvent.clear(input)
    await userEvent.type(input, 'America/Los_Angeles')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(systemSettingsApi.updateSystemSettings).toHaveBeenCalledWith({
        default_timezone: 'America/Los_Angeles',
        attendance_submission_deadline_day: 5,
        attendance_month_close_deadline_day: 10,
        default_work_style_id: '33333333-3333-3333-3333-333333333333',
        m365_tenant_id: null,
        m365_client_id: null,
        m365_mock_enabled: false,
        notification_mail_enabled: false,
        notification_mail_sender_address: null,
        notification_mail_sender_name: null,
        paid_leave_requires_approval: true,
        special_leave_requires_approval: true,
        shift_swap_requires_approval: true,
        attendance_requires_approval: true,
        expense_claim_requires_approval: true,
        prohibit_self_privileged_role_assignment: false,
      }),
    )
    expect(await screen.findByText('保存しました。')).toBeInTheDocument()
  })

  it('toggles the attendance and expense-claim approval checkboxes and includes them when saving', async () => {
    vi.spyOn(systemSettingsApi, 'updateSystemSettings').mockResolvedValue(settings)
    renderPage()

    await screen.findByLabelText('既定タイムゾーン')

    const attendanceCheckbox = screen.getByRole('checkbox', { name: '月次勤怠の提出に承認を必須にする' })
    const expenseCheckbox = screen.getByRole('checkbox', { name: '経費精算の申請に承認を必須にする' })
    expect(attendanceCheckbox).toBeChecked()
    expect(expenseCheckbox).toBeChecked()

    await userEvent.click(attendanceCheckbox)
    await userEvent.click(expenseCheckbox)
    expect(attendanceCheckbox).not.toBeChecked()
    expect(expenseCheckbox).not.toBeChecked()

    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(systemSettingsApi.updateSystemSettings).toHaveBeenCalledWith(
        expect.objectContaining({
          attendance_requires_approval: false,
          expense_claim_requires_approval: false,
        }),
      ),
    )
  })

  it('shows an error message when saving fails', async () => {
    vi.spyOn(systemSettingsApi, 'updateSystemSettings').mockRejectedValue(new Error('保存に失敗しました'))
    renderPage()

    await screen.findByLabelText('既定タイムゾーン')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存に失敗しました')
  })
})
