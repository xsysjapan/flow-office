import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiError } from '../../api/client'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { notify } from '../../lib/notify'
import { RevokeGrantButton } from './RevokeGrantButton'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

describe('RevokeGrantButton', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('confirms and calls onRevoke with the entered reason', async () => {
    const onRevoke = vi.fn().mockResolvedValue(undefined)
    render(
      <RevokeGrantButton
        id="revoke-reason"
        grantedOn="2026-07-01"
        title="付与を取り消しますか?"
        description="この操作は元に戻せません。"
        onRevoke={onRevoke}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: '取消' }))
    await userEvent.type(await screen.findByLabelText('取消理由(任意)'), '入力ミス')
    await userEvent.click(screen.getByRole('button', { name: '取消する' }))

    expect(onRevoke).toHaveBeenCalledWith('入力ミス')
    expect(notify.success).toHaveBeenCalledWith('2026-07-01付与を取り消しました')
  })

  it('shows each 422 message once under its field and the heading above the buttons', async () => {
    render(
      <RevokeGrantButton
        id="revoke-reason"
        grantedOn="2026-07-01"
        title="付与を取り消しますか?"
        description="この操作は元に戻せません。"
        onRevoke={vi.fn()}
        error={
          new ApiError(422, '最初のエラーの文です。', {
            reason: ['理由のエラー'],
            unknown_key: ['その他のエラー'],
          })
        }
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: '取消' }))

    for (const message of ['理由のエラー', 'その他のエラー']) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
  })

  it('renders a disabled button with a reason tooltip when disabled', () => {
    render(
      <RevokeGrantButton
        id="revoke-reason"
        grantedOn="2026-07-01"
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
