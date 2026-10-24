import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { PaidLeaveScheduleBulkGrantBar } from './PaidLeaveScheduleBulkGrantBar'

const description = '2件(合計22日)を付与します。'

describe('PaidLeaveScheduleBulkGrantBar', () => {
  it('shows the selected count', () => {
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={5} confirmDescription={description} onCancel={vi.fn()} onBulkGrant={vi.fn()} />)
    expect(screen.getByText('5件を選択中')).toBeInTheDocument()
  })

  it('asks for confirmation before calling onBulkGrant', async () => {
    const onBulkGrant = vi.fn()
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={2} confirmDescription={description} onCancel={vi.fn()} onBulkGrant={onBulkGrant} />)

    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('付与予定を一括付与しますか?')).toBeInTheDocument()
    expect(within(dialog).getByText('2件(合計22日)を付与します。')).toBeInTheDocument()
    expect(onBulkGrant).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: '一括付与する' }))
    expect(onBulkGrant).toHaveBeenCalledTimes(1)
  })

  it('does not call onBulkGrant when the confirmation is cancelled', async () => {
    const onBulkGrant = vi.fn()
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={2} confirmDescription={description} onCancel={vi.fn()} onBulkGrant={onBulkGrant} />)

    await userEvent.click(screen.getByRole('button', { name: '一括付与する' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'キャンセル' }))

    expect(onBulkGrant).not.toHaveBeenCalled()
  })

  it('calls onCancel when the selection is cleared', async () => {
    const onCancel = vi.fn()
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={2} confirmDescription={description} onCancel={onCancel} onBulkGrant={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))
    expect(onCancel).toHaveBeenCalled()
  })

  it('disables the cancel button while submitting', () => {
    render(<PaidLeaveScheduleBulkGrantBar selectedCount={2} isSubmitting confirmDescription={description} onCancel={vi.fn()} onBulkGrant={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'キャンセル' })).toBeDisabled()
  })
})
