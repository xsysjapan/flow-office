import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ConfirmActionDialog } from './ConfirmActionDialog'

describe('ConfirmActionDialog', () => {
  it('does not show the confirmation dialog until the trigger is clicked', () => {
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効させる"
        onConfirm={vi.fn()}
      />,
    )

    expect(screen.queryByText('端末を失効させますか?')).not.toBeInTheDocument()
  })

  it('shows the title and description once opened', async () => {
    const user = userEvent.setup()
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効させる"
        onConfirm={vi.fn()}
      />,
    )

    await user.click(screen.getByRole('button', { name: '失効させる' }))

    expect(await screen.findByText('端末を失効させますか?')).toBeInTheDocument()
    expect(screen.getByText('この操作は取り消せません。')).toBeInTheDocument()
  })

  it('calls onConfirm when the confirm button is clicked', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効を確定する"
        onConfirm={onConfirm}
      />,
    )

    await user.click(screen.getByRole('button', { name: '失効させる' }))
    await user.click(screen.getByRole('button', { name: '失効を確定する' }))

    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('closes without confirming when cancel is clicked', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効を確定する"
        onConfirm={onConfirm}
      />,
    )

    await user.click(screen.getByRole('button', { name: '失効させる' }))
    await user.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(onConfirm).not.toHaveBeenCalled()
    expect(screen.queryByText('端末を失効させますか?')).not.toBeInTheDocument()
  })

  it('renders extra content such as a reason input between the description and buttons', async () => {
    const user = userEvent.setup()
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効させる"
        onConfirm={vi.fn()}
      >
        <input aria-label="失効理由" placeholder="理由(任意)" />
      </ConfirmActionDialog>,
    )

    await user.click(screen.getByRole('button', { name: '失効させる' }))

    expect(screen.getByLabelText('失効理由')).toBeInTheDocument()
  })

  it('shows an error message when provided', async () => {
    const user = userEvent.setup()
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効させる"
        onConfirm={vi.fn()}
        error={new Error('失効に失敗しました。')}
      />,
    )

    await user.click(screen.getByRole('button', { name: '失効させる' }))

    expect(await screen.findByText('失効に失敗しました。')).toBeInTheDocument()
  })

  it('calls onOpenChange when the dialog opens and closes', async () => {
    const user = userEvent.setup()
    const onOpenChange = vi.fn()
    render(
      <ConfirmActionDialog
        triggerLabel="失効させる"
        title="端末を失効させますか?"
        description="この操作は取り消せません。"
        confirmLabel="失効させる"
        onConfirm={vi.fn()}
        onOpenChange={onOpenChange}
      />,
    )

    await user.click(screen.getByRole('button', { name: '失効させる' }))
    expect(onOpenChange).toHaveBeenCalledWith(true)

    await user.click(screen.getByRole('button', { name: 'キャンセル' }))
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })
})
