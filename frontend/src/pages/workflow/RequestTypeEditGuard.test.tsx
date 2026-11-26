import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as requestTypesApi from '../../api/requestTypes'
import type { RequestType } from '../../api/types'
import { renderWithGuard } from '../../test/renderWithGuard'
import { RequestTypeEditPage } from './RequestTypeEditPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const created: RequestType = {
  id: 2,
  code: 'new_type',
  name: '新規種別',
  description: null,
  form_schema: [],
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

describe('RequestTypeEditPage の離脱警告', () => {
  it('作成して一覧へ移るとき、確認のDialogは出ない', async () => {
    vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue([])
    vi.spyOn(requestTypesApi, 'createRequestType').mockResolvedValue(created)
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    renderWithGuard(
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/admin/request-types/:id" element={<RequestTypeEditPage />} />
          <Route path="/admin/request-types" element={<p>申請種別一覧ページ</p>} />
        </Routes>
      </QueryClientProvider>,
      { initialEntries: ['/admin/request-types/new'] },
    )

    await userEvent.type(await screen.findByLabelText('コード'), 'new_type')
    await userEvent.type(screen.getByLabelText('名称'), '新規種別')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    expect(await screen.findByText('申請種別一覧ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })
})
