import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as workflowRequestsApi from '../../api/workflowRequests'
import { ApiError } from '../../api/client'
import { BULK_REJECTED_NOTICE } from '../../components/BulkActionDialog/BulkActionDialog'
import type { Paginated, WorkflowRequest } from '../../api/types'
import { notify } from '../../lib/notify'
import { WorkflowRequestListPage } from './WorkflowRequestListPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const navigate = vi.fn()

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: { id: 'user-1', effective_permissions: ['attendance.submission_revoke'] } }),
}))

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.search}</div>
}

function renderPage(initialEntry = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <WorkflowRequestListPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkflowRequestListPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    navigate.mockClear()
    vi.mocked(notify.success).mockClear()
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

  it('renders no selection column (header and cells) when no row can be cancelled', async () => {
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue({
      data: [
        { id: 'workflow-request-1', title: 'タクシー代', status: 'approved', form_data: {}, submitted_at: null, approved_at: null, returned_at: null, cancelled_at: null, created_at: null },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    })

    renderPage()

    await screen.findByRole('link', { name: 'タクシー代' })
    expect(document.querySelectorAll('thead th')).toHaveLength(3)
    expect(document.querySelectorAll('tbody tr:first-child td')).toHaveLength(3)
  })

  it('renders the selection column (header and cells) when at least one row can be cancelled', async () => {
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue({
      data: [
        { id: 'workflow-request-1', title: 'タクシー代', status: 'approved', form_data: {}, submitted_at: null, approved_at: null, returned_at: null, cancelled_at: null, created_at: null },
        { id: 'workflow-request-2', title: '名刺の再作成', status: 'submitted', form_data: {}, submitted_at: null, approved_at: null, returned_at: null, cancelled_at: null, created_at: null },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    })

    renderPage()

    await screen.findByRole('link', { name: 'タクシー代' })
    expect(document.querySelectorAll('thead th')).toHaveLength(4)
    expect(document.querySelectorAll('tbody tr:first-child td')).toHaveLength(4)
    expect(screen.getByRole('checkbox', { name: '名刺の再作成を選択' })).toBeInTheDocument()
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
    const bulkCancelSpy = vi.spyOn(workflowRequestsApi, 'bulkCancelWorkflowRequests').mockResolvedValue({ data: [] })
    const cancelSpy = vi.spyOn(workflowRequestsApi, 'cancelWorkflowRequest')

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '名刺の再作成を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'まとめて取消' }))
    expect(await screen.findByText('選択した2件の申請を取り消しますか?')).toBeInTheDocument()
    await userEvent.type(screen.getByPlaceholderText('取消理由'), '重複申請のため')
    await userEvent.click(screen.getByRole('button', { name: '2件を取り消す' }))

    // 一括取消のAPIを1回だけ呼ぶ(件ごとの取消APIは呼ばない)
    await waitFor(() => expect(bulkCancelSpy).toHaveBeenCalledTimes(1))
    expect(bulkCancelSpy).toHaveBeenCalledWith(['workflow-request-1', 'workflow-request-2'], '重複申請のため')
    expect(cancelSpy).not.toHaveBeenCalled()
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('2件の申請を取り消しました'))
    await waitFor(() => expect(screen.queryByText(/件を選択中/)).not.toBeInTheDocument())
  })

  it('places the bulk action bar after the filter bar and before the table', async () => {
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

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代を選択' }))
    const filterLabel = screen.getByLabelText('種別')
    const bulkText = screen.getByText('1件を選択中')
    const table = screen.getByRole('table')
    expect(filterLabel.compareDocumentPosition(bulkText) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(bulkText.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('shows failed cancellations in the result and keeps only the failed requests selected', async () => {
    const base = {
      form_data: {},
      submitted_at: null,
      approved_at: null,
      returned_at: null,
      cancelled_at: null,
      created_at: null,
    }
    const withData: Paginated<WorkflowRequest> = {
      data: [
        { ...base, id: 'workflow-request-1', title: 'タクシー代', status: 'submitted' },
        { ...base, id: 'workflow-request-2', title: '名刺の再作成', status: 'draft' },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    }
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(withData)
    vi.spyOn(workflowRequestsApi, 'bulkCancelWorkflowRequests').mockRejectedValue(
      new ApiError(
        422,
        '選んだ対象のうち、処理できないものがあります。',
        { 'items.workflow-request-2': ['取消できない状態です。'] },
        'bulk_operation_rejected',
        [{ id: 'workflow-request-2', message: '取消できない状態です。' }],
      ),
    )

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '名刺の再作成を選択' }))
    await userEvent.click(screen.getByRole('button', { name: 'まとめて取消' }))
    await userEvent.type(await screen.findByPlaceholderText('取消理由'), '重複申請のため')
    await userEvent.click(screen.getByRole('button', { name: '2件を取り消す' }))

    expect(await screen.findByText('取消の結果')).toBeInTheDocument()
    expect(screen.getByText(BULK_REJECTED_NOTICE)).toBeInTheDocument()
    expect(screen.getByText('名刺の再作成: 取消できない状態です。')).toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: '閉じる' }).at(-1)!)
    expect(notify.success).not.toHaveBeenCalled()
    // 何も取り消されていないので、選択はそのまま残る
    expect(await screen.findByText('2件を選択中')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'タクシー代を選択' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '名刺の再作成を選択' })).toBeChecked()
  })

  it('does not allow cancelling while no reason is entered', async () => {
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
    expect(screen.getByText('この操作は元に戻せません。取り消した申請はすべて取消状態になります。')).toBeInTheDocument()

    expect(screen.getByRole('button', { name: '1件を取り消す' })).toBeDisabled()
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

    // 空状態の作成ボタンと Card の主操作が同じ名前で2つ出る(Card の主操作が先)
    expect(screen.getByText('「新規申請」から申請を作成できます。')).toBeInTheDocument()
    const newRequestButtons = screen.getAllByRole('button', { name: '新規申請' })
    expect(newRequestButtons).toHaveLength(2)
    await userEvent.click(newRequestButtons[0])

    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByRole('button', { name: /有給申請/ })).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: /代休申請/ })).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: /経費精算/ })).toBeInTheDocument()

    await userEvent.click(dialog.getByRole('button', { name: /経費精算/ }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(navigate).toHaveBeenCalledWith('/expenses/new', {
      state: { backTo: { pathname: '/', search: '', hash: '' } },
    })
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

  it('updates the URL on condition change (dropping page) and clears conditions with 検索条件をクリア', async () => {
    const fetchSpy = vi
      .spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests')
      .mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })

    renderPage('/?page=3')
    await screen.findByText('申請はまだありません。')
    expect(screen.queryByRole('button', { name: '検索条件をクリア' })).not.toBeInTheDocument()
    expect(within(screen.getByLabelText('種別')).getByRole('option', { name: 'すべて' })).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('状態'), '承認済み')
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('?status=approved'))
    expect(screen.getByTestId('location')).not.toHaveTextContent('page=')

    await userEvent.click((await screen.findAllByRole('button', { name: '検索条件をクリア' }))[0])
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^$/))
    expect(screen.getByLabelText('状態')).toHaveValue('all')
    expect(fetchSpy).toHaveBeenLastCalledWith({ status: 'all', subjectType: 'all', page: 1 })
  })

  it('keeps the filter bar and shows the error in the table area when a later fetch fails', async () => {
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockRejectedValue(new ApiError(500, '取得失敗'))

    renderPage()

    expect(await screen.findByText('取得失敗')).toBeInTheDocument()
    expect(screen.getByLabelText('状態')).toBeInTheDocument()
  })
})
