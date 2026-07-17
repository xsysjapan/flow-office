import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as workflowRequestsApi from '../api/workflowRequests'
import type { Paginated, WorkflowRequest } from '../api/types'
import { ApprovalsPage } from './ApprovalsPage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ApprovalsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ApprovalsPage', () => {
  it('shows an empty state when there is nothing to approve', async () => {
    const empty: Paginated<WorkflowRequest> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(empty)

    renderPage()

    expect(await screen.findByText('承認待ちの申請はありません。')).toBeInTheDocument()
  })

  it('lists requests awaiting approval with the applicant name', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [
        {
          id: 'workflow-request-7',
          title: 'タクシー代',
          status: 'submitted',
          form_data: {},
          applicant: { id: 1, name: '申請者太郎', email: 'taro@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null },
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
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(withData)

    renderPage()

    expect(await screen.findByRole('link', { name: 'タクシー代' })).toHaveAttribute('href', '/requests/workflow-request-7')
    expect(screen.getByText('申請者太郎')).toBeInTheDocument()
    expect(screen.getByText('提出済み')).toBeInTheDocument()
  })
})
