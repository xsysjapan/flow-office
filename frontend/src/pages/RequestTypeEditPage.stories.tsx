import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { RequestType } from '../api/types'
import { RequestTypeEditPage } from './RequestTypeEditPage'

const expenseType: RequestType = {
  id: 1,
  code: 'expense_reimbursement',
  name: '経費精算',
  description: '出張・接待などの経費を精算する',
  form_schema: [{ key: 'amount', label: '金額', type: 'number', required: true }],
  requires_backoffice_task: true,
  backoffice_task_type: 'expense_reimbursement',
  is_active: true,
}

function withSeeded(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['request-types', true], [expenseType])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialPath]}>
          <Routes>
            <Route path="/admin/request-types/:id" element={<RequestTypeEditPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/RequestTypeEditPage',
  component: RequestTypeEditPage,
} satisfies Meta<typeof RequestTypeEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const New: Story = {
  render: withSeeded('/admin/request-types/new'),
}

export const Edit: Story = {
  render: withSeeded('/admin/request-types/1'),
}
