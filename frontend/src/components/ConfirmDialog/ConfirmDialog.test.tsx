import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Button } from '../Button/Button'
import { ConfirmDialog } from './ConfirmDialog'

function renderDialog(onConfirm = vi.fn(), isConfirming = false) {
  return render(
    <ConfirmDialog
      trigger={
        <Button variant="danger" size="sm">
          削除
        </Button>
      }
      title="この下書きを削除しますか?"
      description="削除すると元に戻せません。"
      onConfirm={onConfirm}
      isConfirming={isConfirming}
    />,
  )
}

describe('ConfirmDialog', () => {
  it('does not run onConfirm just from rendering or opening the dialog', async () => {
    const onConfirm = vi.fn()
    renderDialog(onConfirm)

    await userEvent.click(screen.getByRole('button', { name: '削除' }))

    expect(await screen.findByText('この下書きを削除しますか?')).toBeInTheDocument()
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('runs onConfirm only after the confirm button inside the dialog is clicked', async () => {
    const onConfirm = vi.fn()
    renderDialog(onConfirm)

    await userEvent.click(screen.getByRole('button', { name: '削除' }))
    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('closes without calling onConfirm when cancelled', async () => {
    const onConfirm = vi.fn()
    renderDialog(onConfirm)

    await userEvent.click(screen.getByRole('button', { name: '削除' }))
    await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

    expect(onConfirm).not.toHaveBeenCalled()
    expect(screen.queryByText('この下書きを削除しますか?')).not.toBeInTheDocument()
  })
})
