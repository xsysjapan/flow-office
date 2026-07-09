import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as workflowRequestsApi from '../api/workflowRequests'
import type { User, WorkflowRequest } from '../api/types'
import { WorkflowRequestDetailPage } from './WorkflowRequestDetailPage'

const applicant: User = {
  id: 1,
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const approver: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

let currentUser: User = applicant

vi.mock('../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const submittedRequest: WorkflowRequest = {
  id: 1,
  title: 'タクシー代',
  status: 'submitted',
  form_data: { amount: '1200' },
  applicant,
  approver,
  submitted_at: '2026-07-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00+09:00',
}

function renderPage(request: WorkflowRequest) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(request)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/requests/${request.id}`]}>
        <Routes>
          <Route path="/requests/:id" element={<WorkflowRequestDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkflowRequestDetailPage', () => {
  beforeEach(() => {
    currentUser = applicant
  })

  it('shows submit and cancel actions for the applicant on a draft request', async () => {
    renderPage({ ...submittedRequest, status: 'draft', submitted_at: null })

    expect(await screen.findByRole('button', { name: '提出する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '取り消す' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '承認する' })).not.toBeInTheDocument()
  })

  it('shows approve and return actions for the approver on a submitted request', async () => {
    currentUser = approver
    renderPage(submittedRequest)

    expect(await screen.findByRole('button', { name: '承認する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '差戻す' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '提出する' })).not.toBeInTheDocument()
  })

  it('approves the request when the approver clicks approve', async () => {
    currentUser = approver
    vi.spyOn(workflowRequestsApi, 'approveWorkflowRequest').mockResolvedValue({
      ...submittedRequest,
      status: 'approved',
    })

    renderPage(submittedRequest)
    await userEvent.click(await screen.findByRole('button', { name: '承認する' }))

    await waitFor(() => expect(workflowRequestsApi.approveWorkflowRequest).toHaveBeenCalledWith(1))
  })

  it('returns the request with a comment when the approver clicks return', async () => {
    currentUser = approver
    vi.spyOn(workflowRequestsApi, 'returnWorkflowRequest').mockResolvedValue({
      ...submittedRequest,
      status: 'returned',
    })

    renderPage(submittedRequest)
    await userEvent.type(await screen.findByPlaceholderText('差戻しコメント'), '不備があります')
    await userEvent.click(screen.getByRole('button', { name: '差戻す' }))

    await waitFor(() =>
      expect(workflowRequestsApi.returnWorkflowRequest).toHaveBeenCalledWith(1, '不備があります'),
    )
  })

  it('disables the return button until a comment is entered', async () => {
    currentUser = approver
    renderPage(submittedRequest)

    expect(await screen.findByRole('button', { name: '差戻す' })).toBeDisabled()
  })
})
