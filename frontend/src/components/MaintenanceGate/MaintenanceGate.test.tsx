import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, expect, it, vi } from 'vitest'
import * as api from '../../api/maintenance'
import { MAINTENANCE_EVENT } from '../../api/client'
import { MaintenanceGate } from './MaintenanceGate'

afterEach(() => vi.restoreAllMocks())

function renderGate(path: string) {
  vi.spyOn(api, 'fetchMaintenanceStatus').mockResolvedValue({ maintenance: true })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <MaintenanceGate><p>通常の画面</p></MaintenanceGate>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function fireMaintenance() {
  act(() => {
    window.dispatchEvent(new CustomEvent(MAINTENANCE_EVENT, { detail: 'APIのメッセージ' }))
  })
}

it('shows children until a maintenance error is received, then the full-screen notice', () => {
  renderGate('/attendance')
  expect(screen.getByText('通常の画面')).toBeInTheDocument()

  fireMaintenance()

  expect(screen.getByText('メンテナンス中')).toBeInTheDocument()
  expect(screen.getByText('APIのメッセージ')).toBeInTheDocument()
  expect(screen.queryByText('通常の画面')).not.toBeInTheDocument()
})

it('does not show the notice on the commands admin page', () => {
  renderGate('/admin/commands')

  fireMaintenance()

  expect(screen.getByText('通常の画面')).toBeInTheDocument()
  expect(screen.queryByText('メンテナンス中')).not.toBeInTheDocument()
})
