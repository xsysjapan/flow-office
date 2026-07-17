import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as auditLogApi from '../../api/auditLog'
import type { Paginated, StoredEvent } from '../../api/types'
import { AuditLogPage } from './AuditLogPage'

const event: StoredEvent = {
  id: 1,
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

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(auditLogApi, 'fetchAuditLog').mockResolvedValue(paginated)

  return render(
    <QueryClientProvider client={queryClient}>
      <AuditLogPage />
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
})
