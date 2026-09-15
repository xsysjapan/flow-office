import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { PaidLeaveScheduleBulkGrantBar } from './PaidLeaveScheduleBulkGrantBar'

describe('PaidLeaveScheduleBulkGrantBar', () => {
  it('shows the selected count', () => {
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={5} onCancel={vi.fn()} onBulkGrant={vi.fn()} />)
    expect(screen.getByText('5件を選択中')).toBeInTheDocument()
  })

  it('calls onBulkGrant and onCancel', async () => {
    const onBulkGrant = vi.fn()
    const onCancel = vi.fn()
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={2} onCancel={onCancel} onBulkGrant={onBulkGrant} />)

    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))
    expect(onBulkGrant).toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))
    expect(onCancel).toHaveBeenCalled()
  })

  it('disables the buttons while submitting', () => {
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={2} isSubmitting onCancel={vi.fn()} onBulkGrant={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'キャンセル' })).toBeDisabled()
  })
})
