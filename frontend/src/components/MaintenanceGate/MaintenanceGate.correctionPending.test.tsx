import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { apiFetch } from '../../api/client'
import { clearCorrectionPending } from '../../api/correctionPending'
import { MaintenanceGate } from './MaintenanceGate'

beforeEach(() => clearCorrectionPending())
afterEach(() => vi.restoreAllMocks())

it('does not switch to the full-screen maintenance notice on correction_pending', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify({ code: 'correction_pending', message: '補正待ちです。' }), {
      status: 503,
      headers: { 'content-type': 'application/json' },
    }),
  )
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/attendance']}>
        <MaintenanceGate><p>通常の画面</p></MaintenanceGate>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  await apiFetch('/attendance/days').catch(() => undefined)

  expect(screen.getByText('通常の画面')).toBeInTheDocument()
  expect(screen.queryByText('メンテナンス中')).not.toBeInTheDocument()
})
