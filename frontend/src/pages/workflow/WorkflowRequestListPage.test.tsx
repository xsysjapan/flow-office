import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as workflowRequestsApi from '../../api/workflowRequests'
import { ApiError } from '../../api/client'
import type { Paginated, WorkflowRequest } from '../../api/types'
import { WorkflowRequestListPage } from './WorkflowRequestListPage'

const navigate = vi.fn()

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: { id: 'user-1', effective_permissions: ['attendance.submission_revoke'] } }),
}))

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
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
  })

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
    expect(within(screen.getByRole('table')).getByText('承認済み')).toBeInTheDocument()
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

    await userEvent.click(screen.getByRole('button', { name: 'まとめて取消' }))
    await userEvent.type(screen.getByPlaceholderText('取消理由'), '重複申請のため')
    await userEvent.click(screen.getByRole('button', { name: 'まとめて取り消す' }))

    await waitFor(() => expect(cancelSpy).toHaveBeenCalledTimes(2))
    expect(cancelSpy).toHaveBeenCalledWith('workflow-request-1', '重複申請のため')
    expect(cancelSpy).toHaveBeenCalledWith('workflow-request-2', '重複申請のため')
  })

  it('does not cancel and keeps the confirmation dialog open when no reason is entered', async () => {
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
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(withData)
    const cancelSpy = vi.spyOn(workflowRequestsApi, 'cancelWorkflowRequest').mockResolvedValue(withData.data[0])

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代を選択' }))
    await userEvent.click(screen.getByRole('button', { name: 'まとめて取消' }))
    expect(screen.getByText('この操作は元に戻せません。選択した申請はすべて取消状態になります。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'まとめて取り消す' }))

    expect(await screen.findByText('取消理由を入力してください。')).toBeInTheDocument()
    expect(cancelSpy).not.toHaveBeenCalled()
  })

  it('shows a permission denied state instead of a generic error on 403', async () => {
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockRejectedValue(new ApiError(403, 'Forbidden'))

    renderPage()

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })

  it('renders the domain-specific subtitle and status for a subject-linked request', async () => {
    const withData: Paginated<WorkflowRequest> = {
      data: [
        {
          id: 'workflow-request-3',
          title: '有給申請',
          status: 'submitted',
          form_data: {},
          submitted_at: '2026-08-01T00:00:00+09:00',
          approved_at: null,
          returned_at: null,
          cancelled_at: null,
          created_at: null,
          subject_type: 'paid_leave_request',
          subject_summary: {
            target_date: '2026-08-10',
            leave_type: 'full',
            leave_type_label: '全休',
            hours: null,
            requested_days: 1,
            reason: null,
          },
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(withData)

    renderPage()

    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: '有給申請' })).toHaveAttribute(
      'href',
      '/requests/workflow-request-3',
    )
    expect(within(table).getByText('2026-08-10 全休')).toBeInTheDocument()
    expect(within(table).getByText('有給')).toBeInTheDocument()
  })

  it('opens a request-type selection dialog from a single 新規申請 button, then navigates to the chosen domain', async () => {
    const empty: Paginated<WorkflowRequest> = { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(empty)

    renderPage()

    await screen.findByText('申請はまだありません。')
    expect(screen.queryByRole('link', { name: '有給申請' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '新規申請' }))

    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByRole('button', { name: /有給申請/ })).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: /代休申請/ })).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: /経費精算/ })).toBeInTheDocument()

    await userEvent.click(dialog.getByRole('button', { name: /経費精算/ }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(navigate).toHaveBeenCalledWith('/expenses/new')
  })

  it('filters by status and subject type via URL params', async () => {
    const fetchSpy = vi
      .spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests')
      .mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })

    renderPage()
    await screen.findByText('申請はまだありません。')

    await userEvent.selectOptions(screen.getByLabelText('状態'), '承認済み')
    await userEvent.selectOptions(screen.getByLabelText('種別'), '経費精算')

    await waitFor(() =>
      expect(fetchSpy).toHaveBeenLastCalledWith({ status: 'approved', subjectType: 'expense_claim', page: 1 }),
    )
  })
})
