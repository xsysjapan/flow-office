import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { AttendanceSelectionActionBar } from './AttendanceSelectionActionBar'

function renderBar(props: Partial<Parameters<typeof AttendanceSelectionActionBar>[0]> = {}) {
  const onCancel = vi.fn()
  render(
    <MemoryRouter>
      <AttendanceSelectionActionBar
        selectedCount={0}
        hasSpecialLeaveTypes
        datesQuery="2026-08-24"
        onCancel={onCancel}
        {...props}
      />
    </MemoryRouter>,
  )
  return { onCancel }
}

describe('AttendanceSelectionActionBar', () => {
  it('選択件数を表示する', () => {
    renderBar({ selectedCount: 3 })
    expect(screen.getByText('3件を選択中')).toBeInTheDocument()
  })

  it('未選択時は申請ボタンが無効化される', () => {
    renderBar({ selectedCount: 0 })
    expect(screen.getByRole('button', { name: '有給休暇を申請する' })).toBeDisabled()
    expect(screen.getByRole('button', { name: '代休を申請する' })).toBeDisabled()
  })

  it('選択時は申請ボタンが選択日付をクエリに持つリンクになる', () => {
    renderBar({ selectedCount: 2, datesQuery: '2026-08-24,2026-08-25' })
    const link = screen.getByRole('link', { name: '有給休暇を申請する' })
    expect(link).toHaveAttribute('href', '/paid-leave?dates=2026-08-24,2026-08-25')
  })

  it('特別休暇が無効な場合は特別休暇の導線を出さない', () => {
    renderBar({ selectedCount: 1, hasSpecialLeaveTypes: false })
    expect(screen.queryByText('特別休暇を申請する')).not.toBeInTheDocument()
  })

  it('キャンセルを押すとonCancelが呼ばれる', async () => {
    const { onCancel } = renderBar({ selectedCount: 1 })
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))
    expect(onCancel).toHaveBeenCalledTimes(1)
  })
})
