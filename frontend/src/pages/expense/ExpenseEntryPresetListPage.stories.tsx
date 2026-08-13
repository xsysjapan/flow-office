import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { ExpenseCategory, ExpenseEntryPreset, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { ExpenseEntryPresetListPage } from './ExpenseEntryPresetListPage'

const applicant: User = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const presets: ExpenseEntryPreset[] = [
  {
    id: 1,
    visibility: 'personal',
    owner_user_id: 'applicant-1',
    name: '自宅⇔会社',
    description: null,
    preset_type: 'single_item',
    definition: [{ category_id: 1, description: '自宅 → 会社(電車)', amount: 420 }],
    is_active: true,
    usage_count: 3,
    last_used_at: null,
    created_by: 'applicant-1',
  },
  {
    id: 2,
    visibility: 'system',
    owner_user_id: null,
    name: '深夜タクシー',
    description: '公共交通機関が利用できない場合の帰宅にご利用ください。',
    preset_type: 'single_item',
    definition: [{ category_id: 2, description: null, amount: null }],
    is_active: true,
    usage_count: 0,
    last_used_at: null,
    created_by: null,
  },
]

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
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

function withSeededList(data: ExpenseEntryPreset[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-categories', false], categories)
  queryClient.setQueryData(['expense-entry-presets', { q: undefined, category_id: undefined, page: 1 }], {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length },
    links: { next: null, prev: null },
  })

  const authValue: AuthContextValue = {
    user: applicant,
    status: 'authenticated',
    login: fn(),
    completeLogin: fn(),
    applySession: fn(),
    logout: fn(),
  }

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MemoryRouter>
            <ExpenseEntryPresetListPage />
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseEntryPresetListPage',
  component: ExpenseEntryPresetListPage,
} satisfies Meta<typeof ExpenseEntryPresetListPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithPresets: Story = {
  render: withSeededList(presets),
}

export const Empty: Story = {
  render: withSeededList([]),
}
