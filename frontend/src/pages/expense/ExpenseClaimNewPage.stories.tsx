import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { ExpenseCategory, ExpenseRouteTemplate, Paginated, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { ExpenseClaimNewPage } from './ExpenseClaimNewPage'

const applicant: User = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    entry_mode: 'batch',
    receipt_required_threshold: null,
    approval_skip_threshold: 3000,
    is_active: true,
  },
  {
    id: 2,
    code: 'lodging',
    name: '宿泊費',
    description: null,
    evidence_type_default: 'receipt_required',
    entry_mode: 'single',
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
    is_active: true,
  },
  {
    id: 3,
    code: 'meal',
    name: '会食',
    description: null,
    evidence_type_default: 'receipt_required',
    entry_mode: 'single',
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const templates: ExpenseRouteTemplate[] = [
  {
    id: 1,
    scope: 'personal',
    employee_id: 'applicant-1',
    name: '自宅⇔会社',
    origin: '自宅',
    destination: '会社',
    transport_type: '電車',
    amount: 420,
    category_id: 1,
    is_active: true,
  },
]

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-categories', false], categories)
  queryClient.setQueryData(['expense-route-templates'], templates)
  queryClient.setQueryData(['users', ''], emptyUsers)

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
          <MemoryRouter initialEntries={['/expenses/new']}>
            <Routes>
              <Route path="/expenses/new" element={<ExpenseClaimNewPage />} />
              <Route path="/expenses/:id" element={<p>経費精算詳細ページ</p>} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseClaimNewPage',
  component: ExpenseClaimNewPage,
  parameters: {
    docs: {
      description: {
        component:
          'UC-X004: 対象期間は聞かず、まず経費区分を選ぶ。区分のentry_modeがbatch(交通費)なら表形式・移動経路・テンプレートの3タブ、singleなら区分専用の1件入力フォームに自動的に切り替わる。',
      },
    },
  },
} satisfies Meta<typeof ExpenseClaimNewPage>

export default meta
type Story = StoryObj<typeof meta>

/** UC-X004: 対象期間は聞かず、まず経費区分を選ぶ初期状態。 */
export const CategorySelection: Story = {
  render: withSeeded(),
}
