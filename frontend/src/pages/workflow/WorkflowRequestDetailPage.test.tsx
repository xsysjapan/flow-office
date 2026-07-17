import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attachmentsApi from '../../api/attachments'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { Attachment, StoredEvent, User, WorkflowRequest } from '../../api/types'
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

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const submittedRequest: WorkflowRequest = {
  id: 'workflow-request-1',
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

const historyEvent: StoredEvent = {
  id: 1,
  event_id: 'evt-1',
  aggregate_type: 'workflow_request',
  aggregate_id: '1',
  version: 1,
  event_type: 'workflow_request.drafted',
  payload: {},
  occurred_at: '2026-07-01T00:00:00+09:00',
}

function renderPage(request: WorkflowRequest, attachments: Attachment[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(request)
  vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestHistory').mockResolvedValue([historyEvent])
  vi.spyOn(attachmentsApi, 'fetchAttachments').mockResolvedValue(attachments)

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

    await waitFor(() =>
      expect(workflowRequestsApi.approveWorkflowRequest).toHaveBeenCalledWith('workflow-request-1'),
    )
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
      expect(workflowRequestsApi.returnWorkflowRequest).toHaveBeenCalledWith('workflow-request-1', '不備があります'),
    )
  })

  it('disables the return button until a comment is entered', async () => {
    currentUser = approver
    renderPage(submittedRequest)

    expect(await screen.findByRole('button', { name: '差戻す' })).toBeDisabled()
  })

  it('shows the event history', async () => {
    renderPage(submittedRequest)

    expect(await screen.findByText('下書き作成')).toBeInTheDocument()
  })

  it('uploads a selected file as an attachment', async () => {
    vi.spyOn(attachmentsApi, 'uploadAttachment').mockResolvedValue({
      id: 1,
      file_name: 'receipt.pdf',
      mime_type: 'application/pdf',
      file_size: 100,
      uploaded_by: 1,
      created_at: null,
    })

    renderPage(submittedRequest)
    await screen.findByText('タクシー代')

    const file = new File(['dummy'], 'receipt.pdf', { type: 'application/pdf' })
    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    await userEvent.upload(input, file)

    await waitFor(() =>
      expect(attachmentsApi.uploadAttachment).toHaveBeenCalledWith('workflow_request', 'workflow-request-1', file),
    )
  })

  it('shows existing attachments and downloads them on click', async () => {
    vi.spyOn(attachmentsApi, 'downloadAttachment').mockResolvedValue(undefined)

    renderPage(submittedRequest, [
      { id: 9, file_name: 'receipt.pdf', mime_type: 'application/pdf', file_size: 2048, uploaded_by: 1, created_at: null },
    ])

    await userEvent.click(await screen.findByRole('button', { name: 'ダウンロード' }))

    await waitFor(() => expect(attachmentsApi.downloadAttachment).toHaveBeenCalledWith(9, 'receipt.pdf'))
  })
})
