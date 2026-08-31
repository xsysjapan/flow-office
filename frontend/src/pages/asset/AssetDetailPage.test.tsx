import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import { ApiError } from '../../api/client'
import * as usersApi from '../../api/users'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { Asset, StoredEvent } from '../../api/types'
import { AssetDetailPage } from './AssetDetailPage'

let currentUser: { id: string; effective_permissions: string[] } | null = {
  id: 'user-1',
  effective_permissions: ['asset.manage'],
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const lendingAsset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00121',
  name: 'ThinkPad X1',
  category: 'ノートPC',
  serial_number: 'SN-001',
  management_type: 'lending',
  lending_status: 'loaned',
  installation_status: null,
  lending_method: 'self_service',
  default_location_text: '本社4F',
  qr_token: 'qr-token-1',
  qr_url: 'https://example.com/assets/qr/qr-token-1',
  current_loan_id: 'loan-1',
  notes: '付属品: 充電器',
  current_loan: {
    id: 'loan-1',
    asset_id: 'asset-1',
    user_id: 'user-1',
    borrower: {
      id: 'user-1',
      name: '山田太郎',
      email: 'yamada@example.com',
      department: null,
      job_title: null,
      employment_status: 'active',
      last_login_at: null,
    },
    loan_request_id: null,
    loaned_at: '2026-08-01T00:00:00+09:00',
    expected_return_at: null,
    loaned_by_user_id: 'user-1',
    returned_at: null,
    returned_by_user_id: null,
    return_note: null,
  },
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

const history: StoredEvent[] = [
  {
    id: '1',
    event_id: '1',
    aggregate_type: 'asset',
    aggregate_id: 'asset-1',
    version: 1,
    event_type: 'asset.registered',
    payload: {},
    occurred_at: '2026-08-01T00:00:00+09:00',
  },
  {
    id: '2',
    event_id: '2',
    aggregate_type: 'asset',
    aggregate_id: 'asset-1',
    version: 2,
    event_type: 'asset.loaned',
    payload: {},
    occurred_at: '2026-08-02T00:00:00+09:00',
  },
]

function renderPage(id = 'asset-1') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/assets/${id}`]}>
        <Routes>
          <Route path="/assets/:id" element={<AssetDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AssetDetailPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    currentUser = { id: 'user-1', effective_permissions: ['asset.manage'] }
  })

  it('shows a back link to the asset list', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(lendingAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole('link', { name: '← 一覧へ戻る' })).toHaveAttribute('href', '/assets')
  })

  it('shows a permission denied state instead of a generic error on 403', async () => {
    vi.spyOn(assetApi, 'getAsset').mockRejectedValue(new ApiError(403, 'Forbidden'))
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })

  it('shows lending details for a loaned lending asset', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(lendingAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect((await screen.findAllByText('EQ-00121')).length).toBeGreaterThan(0)
    expect(screen.getByText('貸出中')).toBeInTheDocument()
    expect(screen.getByText('山田太郎')).toBeInTheDocument()
    expect(screen.getByText('本社4F')).toBeInTheDocument()
  })

  it('shows installation details for an installed installation asset', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue({
      ...lendingAsset,
      id: 'asset-2',
      management_type: 'installation',
      lending_status: null,
      installation_status: 'installed',
      lending_method: null,
      default_location_text: null,
      current_loan: null,
      current_loan_id: null,
      current_placement: { location_text: '会議室A', started_at: '2026-08-01T00:00:00+09:00' },
    })
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage('asset-2')

    expect(await screen.findByText('設置中')).toBeInTheDocument()
    expect(screen.getByText('会議室A')).toBeInTheDocument()
  })

  it('shows the operation history', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(lendingAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue(history)

    renderPage()

    expect(await screen.findByText('登録')).toBeInTheDocument()
    expect(screen.getByText('貸与')).toBeInTheDocument()
  })

  it('shows manage actions (lend/return/delete) for asset.manage holders on an available asset', async () => {
    const availableAsset: Asset = { ...lendingAsset, lending_status: 'available', current_loan_id: null, current_loan: null }
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(availableAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole('button', { name: '貸与する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '借りる' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '廃棄' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '削除' })).toBeInTheDocument()
  })

  it('only shows self-service borrow/return actions for users without asset.manage', async () => {
    currentUser = { id: 'user-1', effective_permissions: [] }
    const availableAsset: Asset = { ...lendingAsset, lending_status: 'available', current_loan_id: null, current_loan: null }
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(availableAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole('button', { name: '借りる' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '貸与する' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '削除' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: '編集' })).not.toBeInTheDocument()
  })

  it('shows a return action for a loaned asset', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(lendingAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole('button', { name: '返却' })).toBeInTheDocument()
  })

  it('calls returnAsset when the return dialog is confirmed', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(lendingAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])
    const returnSpy = vi.spyOn(assetApi, 'returnAsset').mockResolvedValue({ ...lendingAsset, lending_status: 'available' })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '返却' }))
    await userEvent.click(await screen.findByRole('button', { name: '返却する' }))

    expect(returnSpy).toHaveBeenCalledWith('asset-1', { return_note: null })
  })

  it('shows no operations for a disposed asset', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue({ ...lendingAsset, lending_status: 'disposed', current_loan_id: null, current_loan: null })
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText('廃棄済みのため操作はありません。')).toBeInTheDocument()
  })

  const approvalAsset: Asset = {
    ...lendingAsset,
    id: 'asset-approval',
    lending_method: 'approval',
    lending_status: 'available',
    current_loan_id: null,
    current_loan: null,
  }

  const approver = {
    id: 'approver-1',
    name: '承認者花子',
    email: 'hanako@example.com',
    department: null,
    job_title: null,
    employment_status: 'active' as const,
    last_login_at: null,
  }

  it('shows a loan request button for an approval-method available asset (UC-L07)', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(approvalAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])

    renderPage('asset-approval')

    expect(await screen.findByRole('button', { name: '貸出申請' })).toBeInTheDocument()
  })

  it('submits an asset_loan workflow request with the asset_id/purpose form data', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(approvalAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue({
      data: [approver],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    })
    const created = {
      id: 'workflow-request-1',
      title: `${approvalAsset.name}(${approvalAsset.asset_no})の貸出申請`,
      status: 'draft' as const,
      form_data: { asset_id: approvalAsset.id, purpose: 'リモート会議用' },
      submitted_at: null,
      approved_at: null,
      returned_at: null,
      cancelled_at: null,
      created_at: null,
    }
    vi.spyOn(workflowRequestsApi, 'createWorkflowRequest').mockResolvedValue(created)
    vi.spyOn(workflowRequestsApi, 'submitWorkflowRequest').mockResolvedValue({ ...created, status: 'submitted' })

    renderPage('asset-approval')

    await userEvent.click(await screen.findByRole('button', { name: '貸出申請' }))
    await userEvent.type(screen.getByLabelText('利用目的'), 'リモート会議用')
    await userEvent.click(screen.getByLabelText('承認者'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '承認者')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '申請する' }))

    await waitFor(() =>
      expect(workflowRequestsApi.createWorkflowRequest).toHaveBeenCalledWith({
        request_type_code: 'asset_loan',
        title: `${approvalAsset.name}(${approvalAsset.asset_no})の貸出申請`,
        form_data: { asset_id: approvalAsset.id, purpose: 'リモート会議用' },
        approver_user_id: 'approver-1',
      }),
    )
    await waitFor(() =>
      expect(workflowRequestsApi.submitWorkflowRequest).toHaveBeenCalledWith('workflow-request-1', 'approver-1'),
    )
  })

  it('auto-selects the single approved loan request and includes it when lending an approval-method asset (spec 論点2-3)', async () => {
    vi.spyOn(assetApi, 'getAsset').mockResolvedValue(approvalAsset)
    vi.spyOn(assetApi, 'getAssetHistory').mockResolvedValue([])
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue({
      data: [approver],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    })
    vi.spyOn(assetApi, 'getAssetLoanRequests').mockResolvedValue([
      {
        id: 'loan-request-1',
        asset_id: approvalAsset.id,
        applicant_user_id: 'approver-1',
        applicant: null,
        approver_user_id: 'user-1',
        approver: null,
        status: 'approved',
        purpose: 'リモート会議用',
        submitted_at: '2026-08-01T00:00:00+09:00',
        approved_at: '2026-08-02T00:00:00+09:00',
        rejected_at: null,
        rejection_reason: null,
        withdrawn_at: null,
        cancelled_at: null,
        lent_at: null,
      },
    ])
    const lendSpy = vi.spyOn(assetApi, 'lendAsset').mockResolvedValue({ ...approvalAsset, lending_status: 'loaned' })

    renderPage('asset-approval')

    await userEvent.click(await screen.findByRole('button', { name: '貸与する' }))
    await userEvent.click(screen.getByLabelText('借用者'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '承認者')
    await userEvent.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))

    await screen.findByText('利用目的: リモート会議用')
    await userEvent.click(screen.getByRole('button', { name: '貸与する' }))

    await waitFor(() =>
      expect(lendSpy).toHaveBeenCalledWith('asset-approval', {
        borrower_user_id: 'approver-1',
        expected_return_at: null,
        loan_request_id: 'loan-request-1',
      }),
    )
  })
})
