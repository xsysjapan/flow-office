import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { RevokeGrantButton } from './RevokeGrantButton'

describe('RevokeGrantButton', () => {
  it('confirms and calls onRevoke with the entered reason', async () => {
    const onRevoke = vi.fn().mockResolvedValue(undefined)
    render(
      <RevokeGrantButton
        id="revoke-reason"
        title="付与を取り消しますか?"
        description="この操作は元に戻せません。"
        onRevoke={onRevoke}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: '取消' }))
    await userEvent.type(await screen.findByLabelText('取消理由(任意)'), '入力ミス')
    await userEvent.click(screen.getByRole('button', { name: '取消する' }))

    expect(onRevoke).toHaveBeenCalledWith('入力ミス')
  })

  it('renders a disabled button with a reason tooltip when disabled', () => {
    render(
      <RevokeGrantButton
        id="revoke-reason"
        title="付与を取り消しますか?"
        description="この操作は元に戻せません。"
        onRevoke={vi.fn()}
        disabled
        disabledReason="既に消化された分は取り消せません。"
      />,
    )

    const button = screen.getByRole('button', { name: '取消' })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('title', '既に消化された分は取り消せません。')
  })
})
