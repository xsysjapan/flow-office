import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as authenticationKeysApi from '../../api/authenticationKeys'
import type { AuthenticationKey } from '../../api/types'
import { AuthenticationKeysPanel } from './AuthenticationKeysPanel'
import { notify } from '../../lib/notify'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const keys: AuthenticationKey[] = [
  {
    id: 'auth-key-1',
    user_id: '11111111-1111-1111-1111-111111111111',
    key_type: 'nfc_uid',
    display_name: '本社ICカード',
    status: 'active',
    valid_from: null,
    valid_until: null,
    registered_by_user_id: '11111111-1111-1111-1111-111111111111',
    registered_at: '2026-07-01T09:00:00+09:00',
    disabled_at: null,
  },
  {
    id: 'auth-key-2',
    user_id: '11111111-1111-1111-1111-111111111111',
    key_type: 'fingerprint_external_id',
    display_name: '右手人差し指',
    status: 'disabled',
    valid_from: null,
    valid_until: null,
    registered_by_user_id: '11111111-1111-1111-1111-111111111111',
    registered_at: '2026-06-01T09:00:00+09:00',
    disabled_at: '2026-06-15T09:00:00+09:00',
  },
]

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(authenticationKeysApi, 'fetchAuthenticationKeysForUser').mockResolvedValue(keys)

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthenticationKeysPanel userId="11111111-1111-1111-1111-111111111111" />
    </QueryClientProvider>,
  )
}

describe('AuthenticationKeysPanel', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('lists authentication keys with type and status', async () => {
    renderPanel()

    expect(await screen.findByText('本社ICカード')).toBeInTheDocument()
    expect(screen.getByText('右手人差し指')).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
    expect(screen.getByText('無効化済み')).toBeInTheDocument()
  })

  it('does not show a disable button for an already-disabled key', async () => {
    renderPanel()

    await screen.findByText('右手人差し指')

    expect(screen.getAllByRole('button', { name: '無効化する' })).toHaveLength(1)
  })

  it('opens the issue form and submits a new key', async () => {
    const user = userEvent.setup()
    const issueSpy = vi.spyOn(authenticationKeysApi, 'issueAuthenticationKey').mockResolvedValue(keys[0])
    renderPanel()

    await screen.findByText('本社ICカード')
    await user.click(screen.getByRole('button', { name: '新規発行' }))
    await user.type(screen.getByLabelText('表示名'), '来客用QR')
    await user.type(screen.getByLabelText('読み取った値(カードUID・外部認証端末IDなど)'), 'abc123')
    await user.click(screen.getByRole('button', { name: '発行する' }))

    expect(issueSpy).toHaveBeenCalledWith(
      expect.objectContaining({ user_id: '11111111-1111-1111-1111-111111111111', display_name: '来客用QR', raw_key_value: 'abc123' }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('認証キー「来客用QR」を発行しました'))
  })

  it('disables an active key', async () => {
    const user = userEvent.setup()
    const disableSpy = vi.spyOn(authenticationKeysApi, 'disableAuthenticationKey').mockResolvedValue({
      ...keys[0],
      status: 'disabled',
    })
    renderPanel()

    await screen.findByText('本社ICカード')
    await user.click(screen.getByRole('button', { name: '無効化する' }))
    const buttons = await screen.findAllByRole('button', { name: '無効化する' })
    await user.click(buttons[buttons.length - 1])

    expect(disableSpy).toHaveBeenCalledWith('auth-key-1')
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('認証キー「本社ICカード」を無効化しました'))
  })

  it('opens a confirmation dialog before disabling a key', async () => {
    const user = userEvent.setup()
    renderPanel()

    await screen.findByText('本社ICカード')
    await user.click(screen.getByRole('button', { name: '無効化する' }))

    expect(await screen.findByText('認証キーを無効化しますか?')).toBeInTheDocument()
  })

  it('shows 422 field errors of the issue form under each field exactly once', async () => {
    const user = userEvent.setup()
    const issueSpy = vi.spyOn(authenticationKeysApi, 'issueAuthenticationKey').mockRejectedValueOnce(
      new ApiError(422, '種別エラー。', {
        key_type: ['種別エラー。'],
        display_name: ['表示名エラー。'],
        raw_key_value: ['読み取った値エラー。'],
        user_id: ['未知のエラー。'],
      }),
    )
    renderPanel()

    await screen.findByText('本社ICカード')
    await user.click(screen.getByRole('button', { name: '新規発行' }))
    await user.type(screen.getByLabelText('表示名'), '来客用QR')
    await user.type(screen.getByLabelText('読み取った値(カードUID・外部認証端末IDなど)'), 'abc123')
    await user.click(screen.getByRole('button', { name: '発行する' }))

    for (const message of ['種別エラー。', '表示名エラー。', '読み取った値エラー。', '未知のエラー。']) {
      expect(await screen.findByText(message)).toBeInTheDocument()
    }
    expect(screen.getByText('入力内容を確認してください。')).toBeInTheDocument()
    issueSpy.mockRestore()
  })
})
