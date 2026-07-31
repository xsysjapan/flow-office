import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as attendanceSubmissionReminderExclusionsApi from '../../api/attendanceSubmissionReminderExclusions'
import type { AttendanceSubmissionReminderExclusion } from '../../api/types'
import { AttendanceSubmissionReminderExclusionPanel } from './AttendanceSubmissionReminderExclusionPanel'

const USER_ID = '11111111-1111-1111-1111-111111111111'

const exclusions: AttendanceSubmissionReminderExclusion[] = [
  {
    id: 'exclusion-1',
    user_id: USER_ID,
    year_month: '2026-06',
    reason: '利用開始日より前の月を誤って督促対象にしていたため',
    excluded_by_user_id: '22222222-2222-2222-2222-222222222222',
    created_at: '2026-07-10T09:00:00+09:00',
  },
]

function renderPanel(seed: AttendanceSubmissionReminderExclusion[] = exclusions) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attendanceSubmissionReminderExclusionsApi, 'fetchAttendanceSubmissionReminderExclusions').mockResolvedValue(seed)

  return render(
    <QueryClientProvider client={queryClient}>
      <AttendanceSubmissionReminderExclusionPanel userId={USER_ID} />
    </QueryClientProvider>,
  )
}

describe('AttendanceSubmissionReminderExclusionPanel', () => {
  it('lists existing exclusions with their reason', async () => {
    renderPanel()

    expect(await screen.findByText('2026-06')).toBeInTheDocument()
    expect(screen.getByText('利用開始日より前の月を誤って督促対象にしていたため')).toBeInTheDocument()
  })

  it('shows a message when there are no exclusions yet', async () => {
    renderPanel([])

    expect(await screen.findByText('除外設定はまだありません。')).toBeInTheDocument()
  })

  it('disables the submit button until a year-month and reason are entered', async () => {
    renderPanel([])
    await screen.findByText('除外設定はまだありません。')

    expect(screen.getByRole('button', { name: 'この月の督促を対象外にする' })).toBeDisabled()
  })

  it('submits a new exclusion with the selected year-month and entered reason', async () => {
    const user = userEvent.setup()
    const excludeSpy = vi.spyOn(attendanceSubmissionReminderExclusionsApi, 'excludeAttendanceSubmissionReminder').mockResolvedValue(
      exclusions[0],
    )
    renderPanel([])
    await screen.findByText('除外設定はまだありません。')

    // YearMonthPickerはFormFieldのlabel(htmlFor)と紐づいているため、ボタンの
    // アクセシブルネームは表示中のプレースホルダーではなくFormFieldのラベル文言になる
    // (frontend/src/pages/admin/UserRoleEditPage.test.tsxのDatePicker利用箇所と同じ)。
    await user.click(screen.getByRole('button', { name: '対象年月' }))
    await user.click(screen.getByRole('option', { name: '2026年6月' }))
    await user.type(screen.getByLabelText('除外理由'), '誤ってその月を提出対象にしていたため')
    await user.click(screen.getByRole('button', { name: 'この月の督促を対象外にする' }))

    expect(excludeSpy).toHaveBeenCalledWith({
      user_id: USER_ID,
      year_month: '2026-06',
      reason: '誤ってその月を提出対象にしていたため',
    })
  })
})
