import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { AttendanceMonth, Paginated, User } from '../api/types'
import { AttendanceMonthsPage } from './AttendanceMonthsPage'

const approver: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

const months: AttendanceMonth[] = [
  {
    id: 1,
    user_id: 1,
    year_month: '2026-05',
    status: 'closed',
    approver,
    submitted_at: '2026-06-01T00:00:00+09:00',
    approved_at: '2026-06-02T00:00:00+09:00',
    returned_at: null,
    closed_at: '2026-06-05T00:00:00+09:00',
    snapshot: { work_minutes: 9600 },
    legal_holiday_warnings: [],
  },
  {
    id: 2,
    user_id: 1,
    year_month: '2026-06',
    status: 'returned',
    approver,
    submitted_at: '2026-07-01T00:00:00+09:00',
    approved_at: null,
    returned_at: '2026-07-02T00:00:00+09:00',
    closed_at: null,
    snapshot: null,
    legal_holiday_warnings: [],
  },
  {
    id: 3,
    user_id: 1,
    year_month: '2026-07',
    status: 'not_submitted',
    submitted_at: null,
    approved_at: null,
    returned_at: null,
    closed_at: null,
    snapshot: null,
    legal_holiday_warnings: [],
  },
]

function withSeeded(data: AttendanceMonth[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'months', 'mine'], data)
  queryClient.setQueryData(['users', ''], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <AttendanceMonthsPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/AttendanceMonthsPage',
  component: AttendanceMonthsPage,
} satisfies Meta<typeof AttendanceMonthsPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithMonths: Story = {
  render: withSeeded(months),
}

export const Empty: Story = {
  render: withSeeded([]),
}
