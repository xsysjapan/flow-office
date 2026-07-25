import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { WeeklyAttendanceBulkEntryModal } from './WeeklyAttendanceBulkEntryModal'

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
        <WeeklyAttendanceBulkEntryModal defaultFrom="2026-08-03" defaultTo="2026-08-09" />
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Components/WeeklyAttendanceBulkEntryModal',
  component: WeeklyAttendanceBulkEntryModal,
} satisfies Meta<typeof WeeklyAttendanceBulkEntryModal>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Decorator />,
}
