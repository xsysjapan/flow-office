import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as requestTypesApi from '../../api/requestTypes'
import * as usersApi from '../../api/users'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { RequestType, WorkflowRequest } from '../../api/types'
import { renderWithGuard } from '../../test/renderWithGuard'
import { WorkflowRequestNewPage } from './WorkflowRequestNewPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

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
  requires_backoffice_task: false,
  backoffice_task_type: null,
  backoffice_department: null,
  export_amount_field: null,
  allowed_status_transitions: null,
  is_active: true,
}

describe('WorkflowRequestNewPage の離脱警告', () => {
  it('下書き保存して詳細へ移るとき、確認のDialogは出ない', async () => {
    vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue([expenseType])
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
      links: { next: null, prev: null },
    })
    const draft: WorkflowRequest = {
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
    vi.spyOn(workflowRequestsApi, 'createWorkflowRequest').mockResolvedValue(draft)
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    renderWithGuard(
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/requests/new" element={<WorkflowRequestNewPage />} />
          <Route path="/requests/:id" element={<p>申請詳細ページ</p>} />
        </Routes>
      </QueryClientProvider>,
      { initialEntries: ['/requests/new'] },
    )

    await userEvent.selectOptions(await screen.findByLabelText('申請種別'), '経費精算')
    await userEvent.type(screen.getByLabelText('タイトル'), 'タクシー代')
    await userEvent.type(screen.getByLabelText('金額'), '1200')
    await userEvent.click(screen.getByRole('button', { name: '下書き保存' }))

    expect(await screen.findByText('申請詳細ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })
})
