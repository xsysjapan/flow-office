import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { RequestType } from '../api/types'
import { RequestTypeListPage } from './RequestTypeListPage'

const requestTypes: RequestType[] = [
  {
    id: 1,
    code: 'expense_reimbursement',
    name: '経費精算',
    description: null,
    form_schema: [],
    requires_backoffice_task: true,
    backoffice_task_type: 'expense_reimbursement',
    is_active: true,
  },
  {
    id: 2,
    code: 'address_change',
    name: '住所変更',
    description: null,
    form_schema: [],
    requires_backoffice_task: false,
    backoffice_task_type: null,
    is_active: false,
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['request-types', true], requestTypes)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <RequestTypeListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/RequestTypeListPage',
  component: RequestTypeListPage,
} satisfies Meta<typeof RequestTypeListPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
