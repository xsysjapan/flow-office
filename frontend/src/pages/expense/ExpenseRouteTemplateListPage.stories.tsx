import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { ExpenseCategory, ExpenseRouteTemplate } from '../../api/types'
import { ExpenseRouteTemplateListPage } from './ExpenseRouteTemplateListPage'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    entry_mode: 'batch',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const templates: ExpenseRouteTemplate[] = [
  {
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
  },
  {
    id: 2,
    scope: 'personal',
    employee_id: 'emp-001',
    name: '自宅-本社',
    origin: '自宅',
    destination: '本社',
    transport_type: 'train',
    amount: 480,
    category_id: 1,
    is_active: true,
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-route-templates'], templates)
  queryClient.setQueryData(['expense-categories', false], categories)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ExpenseRouteTemplateListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseRouteTemplateListPage',
  component: ExpenseRouteTemplateListPage,
} satisfies Meta<typeof ExpenseRouteTemplateListPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
