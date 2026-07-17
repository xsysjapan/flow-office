import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as requestTypesApi from '../../api/requestTypes'
import * as usersApi from '../../api/users'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { RequestType, User, WorkflowRequest } from '../../api/types'
import { WorkflowRequestNewPage } from './WorkflowRequestNewPage'

const expenseType: RequestType = {
  id: 1,
  code: 'expense_reimbursement',
  name: '経費精算',
  description: null,
  form_schema: [{ key: 'amount', label: '金額', type: 'number', required: true }],
  requires_attachment: false,
  attachment_max_size_kb: null,
  attachment_allowed_extensions: null,
  eligible_role_codes: null,
  requires_backoffice_task: true,
  backoffice_task_type: 'expense_reimbursement',
  backoffice_department: null,
  export_amount_field: null,
  allowed_status_transitions: null,
  is_active: true,
}

const approver: User = {
  id: 5,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/requests/new']}>
        <Routes>
          <Route path="/requests/new" element={<WorkflowRequestNewPage />} />
          <Route path="/requests/:id" element={<p>申請詳細ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkflowRequestNewPage', () => {
  it('shows dynamic fields for the selected request type', async () => {
    vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue([expenseType])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } })

    renderPage()

    const select = await screen.findByLabelText('申請種別')
    await userEvent.selectOptions(select, '経費精算')

    expect(screen.getByLabelText('金額')).toBeInTheDocument()
  })

  it('creates and submits the request, then navigates to the detail page', async () => {
    vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue([expenseType])
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({ data: [approver], meta: { current_page: 1, last_page: 1, total: 1 }, links: { next: null, prev: null } })

    const created: WorkflowRequest = {
      id: 'workflow-request-42',
      title: 'タクシー代',
      status: 'draft',
      form_data: { amount: '1200' },
      submitted_at: null,
      approved_at: null,
      returned_at: null,
      cancelled_at: null,
      created_at: null,
    }
    vi.spyOn(workflowRequestsApi, 'createWorkflowRequest').mockResolvedValue(created)
    vi.spyOn(workflowRequestsApi, 'submitWorkflowRequest').mockResolvedValue({ ...created, status: 'submitted' })

    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText('申請種別'), '経費精算')
    await userEvent.type(screen.getByLabelText('タイトル'), 'タクシー代')
    await userEvent.type(screen.getByLabelText('金額'), '1200')
    await userEvent.click(screen.getByLabelText('承認者'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '承認者')

    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '提出する' }))

    await waitFor(() =>
      expect(workflowRequestsApi.createWorkflowRequest).toHaveBeenCalledWith({
        request_type_code: 'expense_reimbursement',
        title: 'タクシー代',
        form_data: { amount: '1200' },
        approver_user_id: 5,
      }),
    )
    await waitFor(() =>
      expect(workflowRequestsApi.submitWorkflowRequest).toHaveBeenCalledWith('workflow-request-42', 5),
    )
    expect(await screen.findByText('申請詳細ページ')).toBeInTheDocument()
  })
})
