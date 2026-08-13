import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { ExpenseCategory, ExpenseEntryPreset } from '../../api/types'
import { ExpenseEntryPresetEditPage } from './ExpenseEntryPresetEditPage'

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
    approval_skip_threshold: null,
    is_active: true,
  },
  {
    id: 2,
    code: 'lodging',
    name: '宿泊費',
    description: null,
    evidence_type_default: 'receipt_required',
    entry_mode: 'single',
    field_definitions: null,
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const preset: ExpenseEntryPreset = {
  id: 1,
  visibility: 'personal',
  owner_user_id: 'applicant-1',
  name: '自宅⇔会社',
  description: null,
  preset_type: 'single_item',
  definition: [
    {
      category_id: 1,
      description: '自宅 → 会社',
      amount: 420,
      payment_bearer: 'employee',
      departure: '自宅',
      destination: '会社',
    },
  ],
  is_active: true,
  usage_count: 3,
  last_used_at: null,
  created_by: 'applicant-1',
}

function withSeeded(initialPath: string, existing: ExpenseEntryPreset) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-categories', false], categories)
  queryClient.setQueryData(['expense-entry-presets', 'detail', existing.id], existing)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialPath]}>
          <Routes>
            <Route path="/expenses/presets/:id" element={<ExpenseEntryPresetEditPage />} />
            <Route path="/expenses/presets" element={<p>入力プリセット一覧ページ</p>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseEntryPresetEditPage',
  component: ExpenseEntryPresetEditPage,
} satisfies Meta<typeof ExpenseEntryPresetEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const Create: Story = {
  render: withSeeded('/expenses/presets/new', preset),
}

export const Edit: Story = {
  render: withSeeded('/expenses/presets/1', preset),
}
