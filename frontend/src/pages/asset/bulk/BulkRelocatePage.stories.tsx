import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { fn } from 'storybook/test'
import { AuthContext, type AuthContextValue } from '../../../auth/AuthContext'
import { BulkRelocatePage } from './BulkRelocatePage'

const managerAuthValue: AuthContextValue = {
  user: {
    id: 'user-manager',
    name: '管理担当者',
    email: 'manager@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
    effective_permissions: ['asset.manage'],
  },
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

const staffAuthValue: AuthContextValue = {
  ...managerAuthValue,
  user: { ...managerAuthValue.user!, id: 'user-1', name: '山田太郎', effective_permissions: [] },
}

function Decorator({ auth }: { auth: AuthContextValue }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return (
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <BulkRelocatePage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Pages/Asset/Bulk/BulkRelocatePage',
  component: BulkRelocatePage,
} satisfies Meta<typeof BulkRelocatePage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Decorator auth={managerAuthValue} />,
}

export const PermissionDenied: Story = {
  render: () => <Decorator auth={staffAuthValue} />,
}
