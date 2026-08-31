import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import * as attachmentsApi from '../../api/attachments'
import { ApiError } from '../../api/client'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { Asset, Attachment, User, WorkflowRequest, WorkflowRequestHistoryEntry } from '../../api/types'
import { WorkflowRequestDetailPage } from './WorkflowRequestDetailPage'

const applicant: User = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const approver: User = {
  id: 'approver-1',
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

const historyEntry: WorkflowRequestHistoryEntry = {
  id: 1,
  action: 'drafted',
  actor_user_id: 'applicant-1',
  comment: null,
  occurred_at: '2026-07-01T00:00:00+09:00',
}

function renderPage(request: WorkflowRequest, attachments: Attachment[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(request)
  vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestHistory').mockResolvedValue([historyEntry])
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
    vi.restoreAllMocks()
    currentUser = applicant
  })

  it('shows a back link to the request list', async () => {
    renderPage(submittedRequest)

    expect(await screen.findByRole('link', { name: '← 一覧へ戻る' })).toHaveAttribute('href', '/requests')
  })

  it('shows a permission denied state instead of a generic error on 403', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockRejectedValue(new ApiError(403, 'Forbidden'))

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/requests/workflow-request-1']}>
          <Routes>
            <Route path="/requests/:id" element={<WorkflowRequestDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })

  it('shows submit and cancel actions for the applicant on a draft request', async () => {
    renderPage({ ...submittedRequest, status: 'draft', submitted_at: null })

    expect(await screen.findByRole('button', { name: '提出する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '取消' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '承認する' })).not.toBeInTheDocument()
  })

  it('hides the cancel button for an attendance_month request when the applicant lacks attendance.submission_revoke', async () => {
    currentUser = applicant
    renderPage({ ...submittedRequest, status: 'draft', submitted_at: null, subject_type: 'attendance_month' })

    await screen.findByRole('button', { name: '提出する' })
    expect(screen.queryByRole('button', { name: '取消' })).not.toBeInTheDocument()
  })

  it('shows the cancel button for an attendance_month request when the applicant has attendance.submission_revoke', async () => {
    currentUser = { ...applicant, effective_permissions: ['attendance.submission_revoke'] }
    renderPage({ ...submittedRequest, status: 'draft', submitted_at: null, subject_type: 'attendance_month' })

    expect(await screen.findByRole('button', { name: '取消' })).toBeInTheDocument()
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

  it('cancels the request with a reason via the confirmation dialog', async () => {
    vi.spyOn(workflowRequestsApi, 'cancelWorkflowRequest').mockResolvedValue({
      ...submittedRequest,
      status: 'cancelled',
    })

    renderPage(submittedRequest)

    await userEvent.click(await screen.findByRole('button', { name: '取消' }))
    expect(screen.getByText('この操作は元に戻せません。申請は取消状態になります。')).toBeInTheDocument()

    await userEvent.type(screen.getByPlaceholderText('取消理由'), '出張が中止になったため')
    await userEvent.click(screen.getByRole('button', { name: '取り消す' }))

    await waitFor(() =>
      expect(workflowRequestsApi.cancelWorkflowRequest).toHaveBeenCalledWith('workflow-request-1', '出張が中止になったため'),
    )
  })

  it('keeps the cancel confirmation dialog open and shows a message when no reason is entered', async () => {
    const cancelSpy = vi.spyOn(workflowRequestsApi, 'cancelWorkflowRequest').mockResolvedValue({
      ...submittedRequest,
      status: 'cancelled',
    })

    renderPage(submittedRequest)

    await userEvent.click(await screen.findByRole('button', { name: '取消' }))
    await userEvent.click(screen.getByRole('button', { name: '取り消す' }))

    expect(await screen.findByText('取消理由を入力してください。')).toBeInTheDocument()
    expect(cancelSpy).not.toHaveBeenCalled()
  })

  it('shows the event history', async () => {
    renderPage(submittedRequest)

    expect(await screen.findByText('下書き作成')).toBeInTheDocument()
  })

  it('uploads a selected file as an attachment', async () => {
    vi.spyOn(attachmentsApi, 'uploadAttachment').mockResolvedValue({
      id: 'attachment-1',
      file_name: 'receipt.pdf',
      mime_type: 'application/pdf',
      file_size: 100,
      uploaded_by: 'applicant-1',
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
      { id: 'attachment-9', file_name: 'receipt.pdf', mime_type: 'application/pdf', file_size: 2048, uploaded_by: 'applicant-1', created_at: null },
    ])

    await userEvent.click(await screen.findByRole('button', { name: 'ダウンロード' }))

    await waitFor(() => expect(attachmentsApi.downloadAttachment).toHaveBeenCalledWith('attachment-9', 'receipt.pdf'))
  })

  it('shows the paid leave subject detail (approval-style, read only) for a paid_leave_request', async () => {
    const paidLeaveRequest: WorkflowRequest = {
      ...submittedRequest,
      id: 'workflow-request-paid-leave',
      title: '有給休暇申請',
      subject_type: 'paid_leave_request',
      subject: {
        type: 'paid_leave_request',
        id: 'paid-leave-1',
        user_id: 'applicant-1',
        status: 'submitted',
        target_date: '2026-08-10',
        leave_type: 'full',
        leave_type_label: '全休',
        hours: null,
        requested_days: 1,
        reason: '私用のため',
        submitted_at: '2026-08-01T00:00:00+09:00',
        approved_at: null,
        returned_at: null,
        cancelled_at: null,
        request_group_dates: null,
        used_days_last_year: 3,
        pending_days_last_year: 1,
        approved_days_last_year: 2,
      },
    }

    renderPage(paidLeaveRequest)

    expect(await screen.findByText('申請内容')).toBeInTheDocument()
    expect(screen.getByText('2026-08-10')).toBeInTheDocument()
    expect(screen.getByText('全休')).toBeInTheDocument()
    expect(screen.getByText('1日')).toBeInTheDocument()
    expect(screen.getByText('私用のため')).toBeInTheDocument()
    // 添付資料としての読み取り専用表示であり、承認・却下のアクションは持たない。
    expect(screen.queryByRole('button', { name: '承認する' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '差戻す' })).not.toBeInTheDocument()
  })

  it('shows the expense claim subject detail (approval-style) for an expense_claim', async () => {
    const expenseRequest: WorkflowRequest = {
      ...submittedRequest,
      id: 'workflow-request-expense',
      title: '経費精算申請',
      subject_type: 'expense_claim',
      subject: {
        type: 'expense_claim',
        id: 'expense-claim-1',
        employee_id: 'applicant-1',
        title: '交通費',
        status: 'in_review',
        total_amount: 3400,
        period_from: '2026-07-01',
        period_to: '2026-07-31',
        submitted_at: '2026-08-01T00:00:00+09:00',
        approved_at: null,
        items: [
          {
            id: 'item-1',
            category_id: 1,
            category_name: '交通費',
            usage_date: '2026-07-10',
            description: 'タクシー',
            amount: 1200,
            commuting_deduction_amount: null,
            reimbursement_amount: 1200,
            payment_bearer: 'employee',
          },
        ],
      },
    }

    renderPage(expenseRequest)

    expect(await screen.findByText('申請内容')).toBeInTheDocument()
    expect(screen.getByText('3,400円')).toBeInTheDocument()
    expect(screen.getByText('タクシー')).toBeInTheDocument()
  })

  it('does not show a subject detail section for a general request without subject_type', async () => {
    renderPage(submittedRequest)

    await screen.findByText('タクシー代')
    expect(screen.queryByText('申請内容')).not.toBeInTheDocument()
  })

  const asset: Asset = {
    id: 'asset-1',
    asset_no: 'EQ-00121',
    name: 'タブレット',
    category: 'PC',
    serial_number: null,
    management_type: 'lending',
    lending_status: 'available',
    installation_status: null,
    lending_method: 'approval',
    default_location_text: null,
    qr_token: 'qr-token-1',
    qr_url: 'https://example.com/assets/qr/qr-token-1',
    current_loan_id: null,
    notes: null,
    created_at: '2026-08-01T00:00:00+09:00',
    updated_at: '2026-08-01T00:00:00+09:00',
  }

  const assetLoanRequest: WorkflowRequest = {
    ...submittedRequest,
    id: 'workflow-request-asset-loan',
    title: 'タブレット貸出申請',
    request_type: { id: 1, code: 'asset_loan', name: '備品貸出申請', description: null, form_schema: [], requires_attachment: false, attachment_max_size_kb: null, attachment_allowed_extensions: null, eligible_role_codes: null, requires_backoffice_task: false, backoffice_task_type: null, backoffice_department: null, export_amount_field: null, allowed_status_transitions: null, is_active: true },
    form_data: { asset_id: 'asset-1', purpose: 'リモート会議用' },
  }

  it('resolves and shows the target asset name for an asset_loan request', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)
    renderPage(assetLoanRequest)

    expect(await screen.findByText('申請内容')).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'タブレット(EQ-00121)' })).toHaveAttribute('href', '/assets/asset-1')
  })

  it('shows a reject button (with reason dialog) for the approver only on asset_loan requests', async () => {
    currentUser = approver
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)
    renderPage(assetLoanRequest)

    expect(await screen.findByRole('button', { name: '却下' })).toBeInTheDocument()
  })

  it('does not show a reject button for non-asset_loan requests', async () => {
    currentUser = approver
    renderPage(submittedRequest)

    await screen.findByRole('button', { name: '承認する' })
    expect(screen.queryByRole('button', { name: '却下' })).not.toBeInTheDocument()
  })

  it('rejects an asset_loan request with a reason via the confirmation dialog', async () => {
    currentUser = approver
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)
    vi.spyOn(workflowRequestsApi, 'rejectWorkflowRequest').mockResolvedValue({ ...assetLoanRequest, status: 'rejected' })

    renderPage(assetLoanRequest)

    await userEvent.click(await screen.findByRole('button', { name: '却下' }))
    await userEvent.type(screen.getByPlaceholderText('却下理由'), '在庫不足のため')
    await userEvent.click(screen.getByRole('button', { name: '却下する' }))

    await waitFor(() =>
      expect(workflowRequestsApi.rejectWorkflowRequest).toHaveBeenCalledWith('workflow-request-asset-loan', '在庫不足のため'),
    )
  })

  it('shows the rejection reason for a rejected request', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(asset)
    renderPage({ ...assetLoanRequest, status: 'rejected', rejected_at: '2026-08-05T00:00:00+09:00', rejection_reason: '在庫不足のため' })

    expect(await screen.findByText('却下理由: 在庫不足のため')).toBeInTheDocument()
  })
})
