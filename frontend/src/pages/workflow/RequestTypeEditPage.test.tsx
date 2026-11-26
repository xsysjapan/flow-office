import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as requestTypesApi from '../../api/requestTypes'
import type { RequestType } from '../../api/types'
import { notify } from '../../lib/notify'
import { RequestTypeEditPage } from './RequestTypeEditPage'


vi.mock('../../lib/notify', () => ({
  notify: { success: vi.fn() },
}))

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

function renderPage(
  initialPath: string,
  types: RequestType[] = [expenseType],
  state?: unknown,
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue(types)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[{ pathname: initialPath, state }]}>
        <Routes>
          <Route path="/admin/request-types/:id" element={<RequestTypeEditPage />} />
          <Route path="/admin/request-types" element={<p>申請種別一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('RequestTypeEditPage', () => {
  it('starts blank in create mode', async () => {
    renderPage('/admin/request-types/new')

    expect(await screen.findByLabelText('コード')).toHaveValue('')
    expect(screen.getByLabelText('コード')).not.toBeDisabled()
  })

  it('shows a back link to the request type list', async () => {
    renderPage('/admin/request-types/new')

    const backLink = await screen.findByRole('link', { name: '申請種別に戻る' })
    expect(backLink).toHaveAttribute('href', '/admin/request-types')
  })

  it('returns to the recorded origin (with its query) when cancelled', async () => {
    renderPage('/admin/request-types/1', [expenseType], {
      backTo: { pathname: '/admin/request-types', search: '?x=1', hash: '' },
    })

    expect(await screen.findByRole('link', { name: '申請種別に戻る' })).toHaveAttribute(
      'href',
      '/admin/request-types?x=1',
    )
    await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

    expect(await screen.findByText('申請種別一覧ページ')).toBeInTheDocument()
  })

  it('prefills the form and disables the code field in edit mode', async () => {
    renderPage('/admin/request-types/1')

    expect(await screen.findByLabelText('コード')).toHaveValue('expense_reimbursement')
    expect(screen.getByLabelText('コード')).toBeDisabled()
    expect(screen.getByLabelText('名称')).toHaveValue('経費精算')
    expect(screen.getByDisplayValue('amount')).toBeInTheDocument()
  })

  it('adds a new schema row', async () => {
    renderPage('/admin/request-types/new')
    await screen.findByLabelText('コード')

    await userEvent.click(screen.getByRole('button', { name: '項目を追加' }))

    expect(screen.getByPlaceholderText('キー')).toBeInTheDocument()
  })

  it('removes a schema row', async () => {
    renderPage('/admin/request-types/1')
    await screen.findByDisplayValue('amount')

    await userEvent.click(screen.getByRole('button', { name: '削除' }))

    expect(screen.queryByDisplayValue('amount')).not.toBeInTheDocument()
  })

  it('creates a new request type and navigates back to the list', async () => {
    vi.spyOn(requestTypesApi, 'createRequestType').mockResolvedValue({ ...expenseType, id: 2, code: 'new_type' })
    renderPage('/admin/request-types/new')

    await userEvent.type(await screen.findByLabelText('コード'), 'new_type')
    await userEvent.type(screen.getByLabelText('名称'), '新規種別')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    await waitFor(() =>
      expect(requestTypesApi.createRequestType).toHaveBeenCalledWith({
        code: 'new_type',
        name: '新規種別',
        description: undefined,
        form_schema: [],
        requires_attachment: false,
        attachment_max_size_kb: undefined,
        attachment_allowed_extensions: undefined,
        eligible_role_codes: undefined,
        requires_backoffice_task: false,
        backoffice_task_type: undefined,
        backoffice_department: undefined,
        export_amount_field: undefined,
        allowed_status_transitions: undefined,
        is_active: true,
      }),
    )
    expect(await screen.findByText('申請種別一覧ページ')).toBeInTheDocument()
    expect(notify.success).toHaveBeenCalledWith('申請種別を保存しました')
  })

  it('updates an existing request type', async () => {
    vi.spyOn(requestTypesApi, 'updateRequestType').mockResolvedValue(expenseType)
    renderPage('/admin/request-types/1')

    await screen.findByDisplayValue('経費精算')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(requestTypesApi.updateRequestType).toHaveBeenCalledWith(1, {
        code: 'expense_reimbursement',
        name: '経費精算',
        description: undefined,
        form_schema: [{ key: 'amount', label: '金額', type: 'number', required: true }],
        requires_attachment: false,
        attachment_max_size_kb: undefined,
        attachment_allowed_extensions: undefined,
        eligible_role_codes: undefined,
        requires_backoffice_task: true,
        backoffice_task_type: 'expense_reimbursement',
        backoffice_department: undefined,
        export_amount_field: undefined,
        allowed_status_transitions: undefined,
        is_active: true,
      }),
    )
  })

  describe('422の項目エラー', () => {
    function expectEachOnce(errors: Record<string, string[]>) {
      for (const [key, messages] of Object.entries(errors)) {
        expect(screen.getAllByText(messages[0]), key).toHaveLength(1)
      }
      expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
      expect(screen.queryByText('最初のエラーの文')).not.toBeInTheDocument()
    }

    it('shows each field error once under its field, including the conditional settings', async () => {
      const errors = {
        code: ['コードのエラー'],
        name: ['名称のエラー'],
        description: ['説明のエラー'],
        'eligible_role_codes.0': ['対象者の0番のエラー'],
        attachment_max_size_kb: ['上限サイズのエラー'],
        attachment_allowed_extensions: ['拡張子のエラー'],
        backoffice_task_type: ['タスク種別のエラー'],
        backoffice_department: ['処理部署のエラー'],
        export_amount_field: ['金額項目のエラー'],
        allowed_status_transitions: ['ステータス遷移のエラー'],
        unknown_key: ['未知のキーのエラー'],
      }
      vi.spyOn(requestTypesApi, 'createRequestType').mockRejectedValue(new ApiError(422, '最初のエラーの文', errors))
      renderPage('/admin/request-types/new')

      await userEvent.type(await screen.findByLabelText('コード'), 'x')
      await userEvent.type(screen.getByLabelText('名称'), 'y')
      await userEvent.click(screen.getByLabelText('添付ファイルを必須にする'))
      await userEvent.click(screen.getByLabelText('バックオフィス処理を発生させる'))
      await userEvent.click(screen.getByRole('button', { name: '作成' }))

      expect(await screen.findByText('入力内容を確認してください。')).toBeInTheDocument()
      expectEachOnce(errors)
    })

    it('keeps the errors of undrawn conditional fields in the summary', async () => {
      const errors = {
        code: ['コードのエラー'],
        attachment_max_size_kb: ['上限サイズのエラー'],
        backoffice_task_type: ['タスク種別のエラー'],
      }
      vi.spyOn(requestTypesApi, 'createRequestType').mockRejectedValue(new ApiError(422, '最初のエラーの文', errors))
      renderPage('/admin/request-types/new')

      await userEvent.type(await screen.findByLabelText('コード'), 'x')
      await userEvent.type(screen.getByLabelText('名称'), 'y')
      await userEvent.click(screen.getByRole('button', { name: '作成' }))

      expect(await screen.findByText('入力内容を確認してください。')).toBeInTheDocument()
      expectEachOnce(errors)
    })
  })
})
