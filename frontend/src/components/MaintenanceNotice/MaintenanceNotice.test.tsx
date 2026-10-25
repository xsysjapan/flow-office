import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import * as api from '../../api/readModelRebuilds'
import { MaintenanceNotice } from './MaintenanceNotice'

const originalLocation = window.location

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  vi.restoreAllMocks()
})

function renderNotice() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><MaintenanceNotice message="メンテナンス中のメッセージ" /></QueryClientProvider>)
}

it('shows the API message and does not reload while maintenance continues', async () => {
  const reload = vi.fn()
  Object.defineProperty(window, 'location', { configurable: true, value: { ...originalLocation, reload } })
  const spy = vi.spyOn(api, 'fetchMaintenanceStatus').mockResolvedValue({ maintenance: true })
  renderNotice()

  expect(screen.getByText('メンテナンス中のメッセージ')).toBeInTheDocument()
  await waitFor(() => expect(spy).toHaveBeenCalled())
  expect(reload).not.toHaveBeenCalled()
})

it('reloads the page once maintenance is over', async () => {
  const reload = vi.fn()
  Object.defineProperty(window, 'location', { configurable: true, value: { ...originalLocation, reload } })
  vi.spyOn(api, 'fetchMaintenanceStatus').mockResolvedValue({ maintenance: false })
  renderNotice()

  await waitFor(() => expect(reload).toHaveBeenCalledTimes(1))
})
