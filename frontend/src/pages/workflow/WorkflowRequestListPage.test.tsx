import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { Paginated, WorkflowRequest } from '../../api/types'
import { WorkflowRequestListPage } from './WorkflowRequestListPage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <WorkflowRequestListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkflowRequestListPage', () => {
  it('shows an empty state when there are no requests', async () => {
    const empty: Paginated<WorkflowRequest> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(empty)

    renderPage()

    expect(await screen.findByText('申請はまだありません。')).toBeInTheDocument()
  })

  it('lists requests with their status', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [
        {
          id: 'workflow-request-1',
          title: 'タクシー代',
          status: 'approved',
          form_data: {},
          submitted_at: null,
          approved_at: null,
          returned_at: null,
          cancelled_at: null,
          created_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(withData)

    renderPage()

    expect(await screen.findByRole('link', { name: 'タクシー代' })).toHaveAttribute('href', '/requests/workflow-request-1')
    expect(screen.getByText('承認済み')).toBeInTheDocument()
  })

  it('does not show a selection checkbox for requests that cannot be cancelled', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [
        {
          id: 'workflow-request-1',
          title: 'タクシー代',
          status: 'approved',
          form_data: {},
          submitted_at: null,
          approved_at: null,
          returned_at: null,
          cancelled_at: null,
          created_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(withData)

    renderPage()

    await screen.findByRole('link', { name: 'タクシー代' })
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })

  it('bulk-cancels selected requests with a shared reason', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [
        {
          id: 'workflow-request-1',
          title: 'タクシー代',
          status: 'submitted',
          form_data: {},
          submitted_at: '2026-07-01T00:00:00+09:00',
          approved_at: null,
          returned_at: null,
          cancelled_at: null,
          created_at: null,
        },
        {
          id: 'workflow-request-2',
          title: '名刺の再作成',
          status: 'draft',
          form_data: {},
          submitted_at: null,
          approved_at: null,
          returned_at: null,
          cancelled_at: null,
          created_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(withData)
    const cancelSpy = vi.spyOn(workflowRequestsApi, 'cancelWorkflowRequest').mockResolvedValue(withData.data[0])

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '名刺の再作成を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()

    await userEvent.type(screen.getByPlaceholderText('取消理由'), '重複申請のため')
    await userEvent.click(screen.getByRole('button', { name: 'まとめて取り消す' }))

    await waitFor(() => expect(cancelSpy).toHaveBeenCalledTimes(2))
    expect(cancelSpy).toHaveBeenCalledWith('workflow-request-1', '重複申請のため')
    expect(cancelSpy).toHaveBeenCalledWith('workflow-request-2', '重複申請のため')
  })
})
