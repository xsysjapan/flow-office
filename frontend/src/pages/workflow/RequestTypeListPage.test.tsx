import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as requestTypesApi from '../../api/requestTypes'
import type { RequestType } from '../../api/types'
import { RequestTypeListPage } from './RequestTypeListPage'

const requestTypes: RequestType[] = [
  {
    id: 1,
    code: 'expense_reimbursement',
    name: '経費精算',
    description: null,
    form_schema: [],
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
  },
  {
    id: 2,
    code: 'address_change',
    name: '住所変更',
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
    is_active: false,
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(requestTypesApi, 'fetchRequestTypes').mockResolvedValue(requestTypes)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <RequestTypeListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('RequestTypeListPage', () => {
  it('lists request types with an active/inactive badge', async () => {
    renderPage()

    expect(await screen.findByText('経費精算')).toBeInTheDocument()
    expect(screen.getByText('住所変更')).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
    expect(screen.getByText('無効')).toBeInTheDocument()
  })

  it('links to the new-request-type page', async () => {
    renderPage()

    expect(await screen.findByRole('link', { name: '新規作成' })).toHaveAttribute(
      'href',
      '/admin/request-types/new',
    )
  })
})
