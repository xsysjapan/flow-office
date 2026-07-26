import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { ExpenseCategory, ExpenseRouteTemplate } from '../../api/types'
import { ExpenseRouteTemplateEditPage } from './ExpenseRouteTemplateEditPage'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const template: ExpenseRouteTemplate = {
  id: 1,
  scope: 'company',
  employee_id: null,
  name: '本社-横浜支社',
  origin: '本社',
  destination: '横浜支社',
  transport_type: 'train',
  amount: 640,
  category_id: 1,
  is_active: true,
}

function withSeeded(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-route-templates'], [template])
  queryClient.setQueryData(['expense-categories', false], categories)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialPath]}>
          <Routes>
            <Route path="/admin/expense-route-templates/:id" element={<ExpenseRouteTemplateEditPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseRouteTemplateEditPage',
  component: ExpenseRouteTemplateEditPage,
} satisfies Meta<typeof ExpenseRouteTemplateEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const New: Story = {
  render: withSeeded('/admin/expense-route-templates/new'),
}

export const Edit: Story = {
  render: withSeeded('/admin/expense-route-templates/1'),
}
