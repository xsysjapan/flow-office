import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import { AuthContext, type AuthContextValue } from '../../../auth/AuthContext'
import { SelfBulkReturnPage } from './SelfBulkReturnPage'

const authValue: AuthContextValue = {
  user: {
    id: 'user-1',
    name: '山田太郎',
    email: 'yamada@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
    effective_permissions: [],
  },
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

function Decorator() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return (
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={authValue}>
        <MemoryRouter>
          <SelfBulkReturnPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Pages/Asset/Bulk/SelfBulkReturnPage',
  component: SelfBulkReturnPage,
} satisfies Meta<typeof SelfBulkReturnPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Decorator />,
}
