import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as requestTypesApi from '../api/requestTypes'
import type { RequestType } from '../api/types'
import { RequestTypeEditPage } from './RequestTypeEditPage'

const expenseType: RequestType = {
  id: 1,
  code: 'expense_reimbursement',
  name: '経費精算',
  description: null,
  form_schema: [{ key: 'amount', label: '金額', type: 'number', required: true }],
  requires_backoffice_task: true,
  backoffice_task_type: 'expense_reimbursement',
  is_active: true,
}

function renderPage(initialPath: string, types: RequestType[] = [expenseType]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue(types)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
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
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(requestTypesApi.createRequestType).toHaveBeenCalledWith({
        code: 'new_type',
        name: '新規種別',
        description: undefined,
        form_schema: [],
        requires_backoffice_task: false,
        backoffice_task_type: undefined,
        is_active: true,
      }),
    )
    expect(await screen.findByText('申請種別一覧ページ')).toBeInTheDocument()
  })

  it('updates an existing request type', async () => {
    vi.spyOn(requestTypesApi, 'updateRequestType').mockResolvedValue(expenseType)
    renderPage('/admin/request-types/1')

    await screen.findByDisplayValue('経費精算')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(requestTypesApi.updateRequestType).toHaveBeenCalledWith(1, {
        code: 'expense_reimbursement',
        name: '経費精算',
        description: undefined,
        form_schema: [{ key: 'amount', label: '金額', type: 'number', required: true }],
        requires_backoffice_task: true,
        backoffice_task_type: 'expense_reimbursement',
        is_active: true,
      }),
    )
  })
})
