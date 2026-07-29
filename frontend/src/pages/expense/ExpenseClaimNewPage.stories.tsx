import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { expect, fn, userEvent, within } from 'storybook/test'
import type { ExpenseCategory, Paginated, User } from '../../api/types'
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
    field_definitions: null,
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
    field_definitions: null,
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
    field_definitions: null,
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
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
  queryClient.setQueryData(['expense-entry-presets'], [])
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
          '経費精算を新規開始すると、まず「個別に経費登録」「まとめて経費登録」を選ばせる。個別の場合はそのまま経費区分選択(UC-X004)へ進み、区分のentry_modeがbatch(交通費)ならプリセットから追加できる表形式入力、singleなら区分専用の1件入力フォームに自動的に切り替わる。',
      },
    },
  },
} satisfies Meta<typeof ExpenseClaimNewPage>

export default meta
type Story = StoryObj<typeof meta>

/** 経費精算を新規開始した直後の初期状態。個別/まとめての登録方法を選ばせる。 */
export const EntryModeSelection: Story = {
  render: withSeeded(),
}

/** UC-X004: 「個別に経費登録」を選んだ後、対象期間は聞かず、まず経費区分を選ぶ。 */
export const CategorySelection: Story = {
  render: withSeeded(),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: '個別に登録する' }))
    await expect(await canvas.findByRole('button', { name: '交通費' })).toBeInTheDocument()
  },
}
