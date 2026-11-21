import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as auditLogApi from '../../api/auditLog'
import type { Paginated, StoredEvent } from '../../api/types'
import { AuditLogPage } from './AuditLogPage'

const event: StoredEvent = {
  id: 'evt-1',
  event_id: 'evt-1',
  aggregate_type: 'workflow_request',
  aggregate_id: '1',
  version: 1,
  event_type: 'workflow_request.drafted',
  payload: { title: 'タクシー代' },
  occurred_at: '2026-07-01T00:00:00+09:00',
}

const paginated: Paginated<StoredEvent> = {
  data: [event],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderPage(initialEntry = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(auditLogApi, 'fetchAuditLog').mockResolvedValue(paginated)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <AuditLogPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AuditLogPage', () => {
  it('shows events and the total count', async () => {
    renderPage()

    expect(await screen.findByText('workflow_request.drafted')).toBeInTheDocument()
    expect(screen.getByText('全1件 (1/1ページ)')).toBeInTheDocument()
  })

  it('requeries with the entered aggregate type filter', async () => {
    renderPage()
    await screen.findByText('workflow_request.drafted')

    await userEvent.type(screen.getByLabelText('対象タイプ'), 'workflow_request')

    await waitFor(() =>
      expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(
        expect.objectContaining({ aggregate_type: 'workflow_request' }),
      ),
    )
  })

  it('downloads the CSV when the button is clicked', async () => {
    vi.spyOn(auditLogApi, 'downloadAuditLogCsv').mockResolvedValue(undefined)
    renderPage()
    await screen.findByText('workflow_request.drafted')

    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() => expect(auditLogApi.downloadAuditLogCsv).toHaveBeenCalled())
  })

  it('drops invalid from/to/page from the URL and shows the default', async () => {
    renderPage('/?from=abc&to=2026-02-30&page=-1&aggregate_type=workflow_request')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent('?aggregate_type=workflow_request'))
    expect(screen.getByTestId('location-search').textContent).not.toMatch(/from|to=|page/)
    await waitFor(() =>
      expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(
        expect.objectContaining({ aggregate_type: 'workflow_request', from: undefined, to: undefined, page: 1 }),
      ),
    )
  })

  it('keeps a valid from date in the URL', async () => {
    renderPage('/?from=2026-07-01')

    await screen.findByText('workflow_request.drafted')
    expect(screen.getByTestId('location-search')).toHaveTextContent('?from=2026-07-01')
    expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2026-07-01' }))
  })
})
