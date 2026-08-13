import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { ExpenseCategory } from '../../api/types'
import { ExpenseCategoryListPage } from './ExpenseCategoryListPage'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transportation',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    entry_mode: 'batch',
    field_definitions: null,
    receipt_required_threshold: null,
    approval_skip_threshold: 3000,
    is_active: true,
  },
  {
    id: 2,
    code: 'supplies',
    name: '消耗品費',
    description: null,
    evidence_type_default: 'receipt_required',
    entry_mode: 'single',
    field_definitions: null,
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
    is_active: false,
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-categories', true], categories)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ExpenseCategoryListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseCategoryListPage',
  component: ExpenseCategoryListPage,
} satisfies Meta<typeof ExpenseCategoryListPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
