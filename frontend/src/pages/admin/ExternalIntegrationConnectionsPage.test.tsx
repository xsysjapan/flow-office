import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as connectionsApi from '../../api/externalIntegrationConnections'
import type { ExternalIntegrationConnection } from '../../api/types'
import { ExternalIntegrationConnectionsPage } from './ExternalIntegrationConnectionsPage'

const connections: ExternalIntegrationConnection[] = [
  {
    id: 'conn-1',
    provider: 'freee',
    name: 'freee本社経理',
    auth_type: 'oauth2',
    status: 'connected',
    enabled: true,
    external_office_id: '12345',
    custom_settings: null,
    has_client_id: true,
    has_client_secret: true,
    has_api_key: false,
    client_id_masked: '****ab12',
    api_key_masked: null,
    connected_by_user_id: 'user-1',
    connected_at: '2026-07-01T00:00:00+09:00',
    created_at: '2026-06-01T00:00:00+09:00',
    updated_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 'conn-2',
    provider: 'moneyforward',
    name: 'マネーフォワード大阪支店',
    auth_type: 'api_key',
    status: 'unconfigured',
    enabled: false,
    external_office_id: null,
    custom_settings: null,
    has_client_id: false,
    has_client_secret: false,
    has_api_key: false,
    client_id_masked: null,
    api_key_masked: null,
    connected_by_user_id: null,
    connected_at: null,
    created_at: '2026-07-10T00:00:00+09:00',
    updated_at: '2026-07-10T00:00:00+09:00',
  },
]

function renderPage(data: ExternalIntegrationConnection[] = connections) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(connectionsApi, 'fetchExternalIntegrationConnections').mockResolvedValue({ data })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ExternalIntegrationConnectionsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExternalIntegrationConnectionsPage', () => {
  it('lists connections with provider, status and masked credentials', async () => {
    renderPage()

    expect(await screen.findByText('freee本社経理')).toBeInTheDocument()
    expect(screen.getByText('マネーフォワード大阪支店')).toBeInTheDocument()
    expect(screen.getByText('接続済み')).toBeInTheDocument()
    expect(screen.getAllByText('未設定').length).toBeGreaterThan(0)
    expect(screen.getByText('****ab12')).toBeInTheDocument()
  })

  it('shows an empty state when there are no connections registered', async () => {
    renderPage([])

    expect(await screen.findByText('登録済みの外部連携はまだありません。')).toBeInTheDocument()
  })

  it('disables the create button until required fields for oauth2 are filled', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('freee本社経理')
    await user.click(screen.getByRole('button', { name: '新規登録' }))

    // freeeの初期選択はOAuth2。名称のみでは作成不可(クライアントID/シークレット未入力)。
    await user.type(screen.getAllByLabelText('名称')[0], '新しい接続')
    expect(screen.getByRole('button', { name: '作成' })).toBeDisabled()

    await user.type(screen.getByLabelText('クライアントID'), 'client-id-1')
    await user.type(screen.getByLabelText('クライアントシークレット'), 'secret-1')
    expect(screen.getByRole('button', { name: '作成' })).toBeEnabled()
  })

  it('submits a new connection with the entered fields', async () => {
    const user = userEvent.setup()
    const createSpy = vi
      .spyOn(connectionsApi, 'createExternalIntegrationConnection')
      .mockResolvedValue(connections[0])
    renderPage()

    await screen.findByText('freee本社経理')
    await user.click(screen.getByRole('button', { name: '新規登録' }))
    await user.type(screen.getAllByLabelText('名称')[0], '新しい接続')
    await user.type(screen.getByLabelText('クライアントID'), 'client-id-1')
    await user.type(screen.getByLabelText('クライアントシークレット'), 'secret-1')
    await user.click(screen.getByRole('button', { name: '作成' }))

    expect(createSpy).toHaveBeenCalledWith(
      expect.objectContaining({
        provider: 'freee',
        name: '新しい接続',
        auth_type: 'oauth2',
        client_id: 'client-id-1',
        client_secret: 'secret-1',
      }),
    )
  })

  it('toggles enabled state via the PATCH endpoint', async () => {
    const user = userEvent.setup()
    const updateSpy = vi
      .spyOn(connectionsApi, 'updateExternalIntegrationConnection')
      .mockResolvedValue({ ...connections[1], enabled: true })
    renderPage()

    await screen.findByText('マネーフォワード大阪支店')
    await user.click(screen.getByRole('button', { name: '有効にする' }))

    expect(updateSpy).toHaveBeenCalledWith('conn-2', { enabled: true })
  })

  it('opens a confirmation dialog before deleting a connection', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('freee本社経理')
    await user.click(screen.getAllByRole('button', { name: '削除する' })[0])

    expect(await screen.findByText('外部連携を削除しますか?')).toBeInTheDocument()
  })
})
