import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { ExpenseCategory } from '../../api/types'
import { ExpenseCategoryEditPage } from './ExpenseCategoryEditPage'

const transportCategory: ExpenseCategory = {
  id: 1,
  code: 'transportation',
  name: '交通費',
  description: '通勤・出張時の交通費',
  evidence_type_default: 'fact_reference_available',
  entry_mode: 'batch',
  field_definitions: null,
  receipt_required_threshold: null,
  approval_skip_threshold: 3000,
  is_active: true,
}

function withSeeded(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-categories', true], [transportCategory])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialPath]}>
          <Routes>
            <Route path="/admin/expense-categories/:id" element={<ExpenseCategoryEditPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseCategoryEditPage',
  component: ExpenseCategoryEditPage,
} satisfies Meta<typeof ExpenseCategoryEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const New: Story = {
  render: withSeeded('/admin/expense-categories/new'),
}

export const Edit: Story = {
  render: withSeeded('/admin/expense-categories/1'),
}
