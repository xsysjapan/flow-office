import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { MonthlyAttendanceBulkEntryModal } from './MonthlyAttendanceBulkEntryModal'

const currentUser: User = {
  id: 'user-1',
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const authValue: AuthContextValue = {
  user: currentUser,
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
        <MonthlyAttendanceBulkEntryModal yearMonth="2026-08" />
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Components/MonthlyAttendanceBulkEntryModal',
  component: MonthlyAttendanceBulkEntryModal,
} satisfies Meta<typeof MonthlyAttendanceBulkEntryModal>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Decorator />,
}
