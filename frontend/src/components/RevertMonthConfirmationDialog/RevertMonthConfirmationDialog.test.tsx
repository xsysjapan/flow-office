import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import { ApiError } from '../../api/client'
import { RevertMonthConfirmationDialog } from './RevertMonthConfirmationDialog'
import { notify } from '../../lib/notify'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

function renderDialog() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <RevertMonthConfirmationDialog monthId="month-1" yearMonth="2026-07" />
    </QueryClientProvider>,
  )
}

describe('RevertMonthConfirmationDialog', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('shows the title and description once the trigger is clicked', async () => {
    renderDialog()

    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))

    expect(await screen.findByText('月次勤怠を未提出に戻しますか?')).toBeInTheDocument()
    expect(screen.getByText(/2026-07の月次勤怠を未提出に戻します/)).toBeInTheDocument()
  })

  it('does not send the request and shows an error when the message is empty', async () => {
    const revertSpy = vi.spyOn(attendanceApi, 'revertMonthConfirmation').mockResolvedValue({} as never)
    renderDialog()

    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))
    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))

    expect(await screen.findByText('社員へのメッセージを入力してください。')).toBeInTheDocument()
    expect(revertSpy).not.toHaveBeenCalled()
  })

  it('reverts the month with the message and without workflow_request_id when confirmed', async () => {
    const revertSpy = vi.spyOn(attendanceApi, 'revertMonthConfirmation').mockResolvedValue({} as never)
    renderDialog()

    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))
    await userEvent.type(await screen.findByLabelText('社員へのメッセージ'), '日次の休憩入力に誤りがあるため')
    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))

    await waitFor(() =>
      expect(revertSpy).toHaveBeenCalledWith('month-1', '日次の休憩入力に誤りがあるため', undefined),
    )
    expect(revertSpy.mock.calls[0]).toHaveLength(3)
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('2026-07の月次勤怠を未提出に戻しました'))
  })

  it('shows the reason error once under the message field and keeps other keys in the summary', async () => {
    vi.spyOn(attendanceApi, 'revertMonthConfirmation').mockRejectedValue(
      new ApiError(422, 'サーバーの最初のエラー文', {
        reason: ['msg:reason'],
        workflow_request_id: ['msg:workflow_request_id'],
      }),
    )
    renderDialog()

    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))
    await userEvent.type(await screen.findByLabelText('社員へのメッセージ'), '誤りがあるため')
    await userEvent.click(screen.getByRole('button', { name: '未提出に戻す' }))

    await screen.findByText('入力内容を確認してください。')
    expect(screen.getAllByText('msg:reason')).toHaveLength(1)
    expect(screen.getAllByText('msg:workflow_request_id')).toHaveLength(1)
    expect(screen.queryByText('サーバーの最初のエラー文')).not.toBeInTheDocument()
  })
})
