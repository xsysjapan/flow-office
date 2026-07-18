import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as authenticationKeysApi from '../../api/authenticationKeys'
import type { AuthenticationKey } from '../../api/types'
import { AuthenticationKeysPanel } from './AuthenticationKeysPanel'

const keys: AuthenticationKey[] = [
  {
    id: 1,
    user_id: 42,
    key_type: 'nfc_uid',
    display_name: '本社ICカード',
    status: 'active',
    valid_from: null,
    valid_until: null,
    registered_by_user_id: 42,
    registered_at: '2026-07-01T09:00:00+09:00',
    disabled_at: null,
  },
  {
    id: 2,
    user_id: 42,
    key_type: 'fingerprint_external_id',
    display_name: '右手人差し指',
    status: 'disabled',
    valid_from: null,
    valid_until: null,
    registered_by_user_id: 42,
    registered_at: '2026-06-01T09:00:00+09:00',
    disabled_at: '2026-06-15T09:00:00+09:00',
  },
]

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(authenticationKeysApi, 'fetchAuthenticationKeysForUser').mockResolvedValue(keys)

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthenticationKeysPanel userId={42} />
    </QueryClientProvider>,
  )
}

describe('AuthenticationKeysPanel', () => {
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
      expect.objectContaining({ user_id: 42, display_name: '来客用QR', raw_key_value: 'abc123' }),
    )
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

    expect(disableSpy).toHaveBeenCalledWith(1)
  })

  it('opens a confirmation dialog before disabling a key', async () => {
    const user = userEvent.setup()
    renderPanel()

    await screen.findByText('本社ICカード')
    await user.click(screen.getByRole('button', { name: '無効化する' }))

    expect(await screen.findByText('認証キーを無効化しますか?')).toBeInTheDocument()
  })
})
