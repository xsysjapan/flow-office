import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as integrationsApi from '../../api/integrations'
import type { ApplicationIntegration } from '../../api/types'
import { MyIntegrationsPage } from './MyIntegrationsPage'

const integrations: ApplicationIntegration[] = [
  {
    id: 'integration-1',
    owner_type: 'personal',
    owner_user_id: 'user-1',
    client_type: 'mcp_client',
    client_name: 'Claude Desktop',
    purpose: '月次勤怠の下書き作成',
    status: 'active',
    last_used_at: '2026-07-18T09:00:00+09:00',
    scopes: ['profile:self:read', 'attendance:self:read'],
    created_at: '2026-07-01T09:00:00+09:00',
  },
  {
    id: 'integration-2',
    owner_type: 'personal',
    owner_user_id: 'user-1',
    client_type: 'api_client',
    client_name: '旧連携',
    purpose: null,
    status: 'revoked',
    last_used_at: null,
    scopes: ['profile:self:read'],
    created_at: '2026-06-01T09:00:00+09:00',
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(integrationsApi, 'fetchMyIntegrations').mockResolvedValue(integrations)

  return render(
    <QueryClientProvider client={queryClient}>
      <MyIntegrationsPage />
    </QueryClientProvider>,
  )
}

describe('MyIntegrationsPage', () => {
  it('lists integrations with type and status', async () => {
    renderPage()

    expect(await screen.findByText('Claude Desktop')).toBeInTheDocument()
    expect(screen.getByText('旧連携')).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
    expect(screen.getByText('停止済み')).toBeInTheDocument()
  })

  it('only shows reissue/stop actions for active integrations', async () => {
    renderPage()

    await screen.findByText('Claude Desktop')

    expect(screen.getAllByRole('button', { name: 'トークン再発行' })).toHaveLength(1)
    expect(screen.getAllByRole('button', { name: '停止する' })).toHaveLength(1)
  })

  it('registers a new integration and displays the issued token once', async () => {
    const user = userEvent.setup()
    vi.spyOn(integrationsApi, 'registerIntegration').mockResolvedValue({
      integration: integrations[0],
      token: 'plain-text-token-xyz',
    })
    renderPage()

    await screen.findByText('Claude Desktop')
    await user.click(screen.getByRole('button', { name: '新規登録' }))
    await user.type(screen.getByLabelText('名称'), 'Claude Code')
    await user.click(screen.getByLabelText('自分の勤怠を閲覧する'))
    await user.click(screen.getByRole('button', { name: '登録する' }))

    expect(await screen.findByText('plain-text-token-xyz')).toBeInTheDocument()
  })

  it('opens a confirmation dialog before stopping an integration', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('Claude Desktop')
    await user.click(screen.getByRole('button', { name: '停止する' }))

    expect(await screen.findByText('連携を停止しますか?')).toBeInTheDocument()
  })
})
