import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { AttendanceDay, AttendanceMonth, Paginated, User } from '../api/types'
import { AttendanceMonthDetailPage } from './AttendanceMonthDetailPage'

const yearMonth = '2026-07'

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

const month: AttendanceMonth = {
  id: 1,
  user_id: 1,
  year_month: yearMonth,
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const days: AttendanceDay[] = [
  {
    id: 1,
    user_id: 1,
    work_date: '2026-07-01',
    status: 'clocked_out',
    actual_start_at: '2026-07-01T09:00:00+09:00',
    actual_end_at: '2026-07-01T18:00:00+09:00',
    work_type: null,
    note: null,
    is_locked: false,
    breaks: [],
    calculation: null,
  },
]

function withSeeded(monthData: { days: AttendanceDay[]; month: AttendanceMonth | null }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'month', yearMonth], { ...monthData, flex_settlement_summary: null })
  queryClient.setQueryData(['users', ''], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/attendance/months/${yearMonth}`]}>
          <Routes>
            <Route path="/attendance/months/:yearMonth" element={<AttendanceMonthDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/AttendanceMonthDetailPage',
  component: AttendanceMonthDetailPage,
} satisfies Meta<typeof AttendanceMonthDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const NotSubmitted: Story = {
  render: withSeeded({ days, month }),
}

export const Approved: Story = {
  render: withSeeded({ days, month: { ...month, status: 'approved' } }),
}
