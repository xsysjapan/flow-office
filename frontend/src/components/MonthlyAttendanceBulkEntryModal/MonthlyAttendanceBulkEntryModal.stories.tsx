import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { User } from '../../api/types'
import type { StatusMeta } from '../../utils/statusLabels'
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

function Decorator({ dayOffBadgesByDate }: { dayOffBadgesByDate?: Record<string, StatusMeta[]> }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return (
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={authValue}>
        <MonthlyAttendanceBulkEntryModal yearMonth="2026-08" dayOffBadgesByDate={dayOffBadgesByDate} />
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
  args: { yearMonth: '2026-08' },
  render: () => <Decorator />,
}

/** 休日・祝日名・休暇のバッジを日別の行に表示する(「曜日ごとに設定」/「日にちごとに設定」タブ)。 */
export const WithDayOffBadges: Story = {
  args: { yearMonth: '2026-08' },
  render: () => (
    <Decorator
      dayOffBadgesByDate={{
        '2026-08-11': [
          { label: '祝日名の例(山の日)', tone: 'neutral' },
          { label: '有給休暇(午前半休)', tone: 'info' },
          { label: '代休(午前半休)', tone: 'info' },
        ],
        '2026-08-16': [{ label: '法定休日', tone: 'danger' }],
      }}
    />
  ),
}
