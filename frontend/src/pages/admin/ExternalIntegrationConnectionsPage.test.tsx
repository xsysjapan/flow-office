import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as connectionsApi from '../../api/externalIntegrationConnections'
import type { ExternalIntegrationConnection } from '../../api/types'
import { notify } from '../../lib/notify'
import { ExternalIntegrationConnectionsPage } from './ExternalIntegrationConnectionsPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

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
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
    vi.restoreAllMocks()
  })

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
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('新しい接続を作成しました')
    })
  })

  it('opens the edit dialog from the row menu and shows a toast after saving', async () => {
    const user = userEvent.setup()
    const updateSpy = vi
      .spyOn(connectionsApi, 'updateExternalIntegrationConnection')
      .mockResolvedValue(connections[0])
    renderPage()

    await screen.findByText('freee本社経理')
    await user.click(screen.getByRole('button', { name: 'freee本社経理の操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '編集' }))

    expect(await screen.findByText('外部連携を編集')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: '保存' }))

    expect(updateSpy).toHaveBeenCalledWith('conn-1', expect.objectContaining({ name: 'freee本社経理' }))
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('freee本社経理を保存しました')
    })
  })

  it('toggles enabled state via the PATCH endpoint from the row menu and shows a toast', async () => {
    const user = userEvent.setup()
    const updateSpy = vi
      .spyOn(connectionsApi, 'updateExternalIntegrationConnection')
      .mockResolvedValue({ ...connections[1], enabled: true })
    renderPage()

    await screen.findByText('マネーフォワード大阪支店')
    await user.click(screen.getByRole('button', { name: 'マネーフォワード大阪支店の操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '有効にする' }))

    expect(updateSpy).toHaveBeenCalledWith('conn-2', { enabled: true })
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('マネーフォワード大阪支店を有効にしました')
    })
  })

  it('shows the toggle error next to the row when switching fails', async () => {
    const user = userEvent.setup()
    vi.spyOn(connectionsApi, 'updateExternalIntegrationConnection').mockRejectedValue(new Error('network'))
    renderPage()

    await screen.findByText('マネーフォワード大阪支店')
    await user.click(screen.getByRole('button', { name: 'マネーフォワード大阪支店の操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '有効にする' }))

    expect(await screen.findByText(/エラー|失敗|network/)).toBeInTheDocument()
    expect(notify.success).not.toHaveBeenCalled()
  })

  it('confirms before deleting from the row menu and shows a toast after deleting', async () => {
    const user = userEvent.setup()
    const deleteSpy = vi.spyOn(connectionsApi, 'deleteExternalIntegrationConnection').mockResolvedValue(undefined)
    renderPage()

    await screen.findByText('freee本社経理')
    await user.click(screen.getByRole('button', { name: 'freee本社経理の操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '削除する' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('外部連携を削除しますか?')).toBeInTheDocument()
    expect(deleteSpy).not.toHaveBeenCalled()

    await user.click(within(dialog).getByRole('button', { name: '削除する' }))

    expect(deleteSpy).toHaveBeenCalledWith('conn-1')
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('freee本社経理を削除しました')
    })
  })

  it('describes when each provider sends data', async () => {
    renderPage()

    await screen.findByText('freee本社経理')
    expect(screen.getByText('連携(送信)のタイミングについて')).toBeInTheDocument()
    expect(screen.getByText(/月次確定後/)).toBeInTheDocument()
    expect(screen.getByText(/CSV出力のみで連携する/)).toBeInTheDocument()
  })

  it('starts the freee OAuth flow by navigating to the returned authorize url', async () => {
    const user = userEvent.setup()
    const redirectUrlSpy = vi
      .spyOn(connectionsApi, 'getExternalIntegrationConnectionOAuthRedirectUrl')
      .mockResolvedValue({ url: 'https://accounts.secure.freee.co.jp/public_api/authorize?client_id=x' })
    const originalLocation = window.location
    // @ts-expect-error -- jsdomのlocationはリードオンリーなので置き換えて検証する。
    delete window.location
    // @ts-expect-error -- 同上。
    window.location = { ...originalLocation, href: '' }

    renderPage()
    await screen.findByText('freee本社経理')
    await user.click(screen.getByRole('button', { name: 'freeeと連携する' }))

    expect(redirectUrlSpy).toHaveBeenCalledWith('conn-1')
    await vi.waitFor(() => {
      expect(window.location.href).toBe('https://accounts.secure.freee.co.jp/public_api/authorize?client_id=x')
    })

    // @ts-expect-error -- 同上。
    window.location = originalLocation
  })

  it('disables the freee OAuth button until client credentials are registered', async () => {
    renderPage([{ ...connections[0], has_client_id: false, has_client_secret: false }, connections[1]])

    await screen.findByText('freee本社経理')
    expect(screen.getByRole('button', { name: 'freeeと連携する' })).toBeDisabled()
  })
})
