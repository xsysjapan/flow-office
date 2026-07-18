import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { MonthlyAttendanceDraft } from '../../api/types'
import { MyMonthlyAttendanceDraftsPage } from './MyMonthlyAttendanceDraftsPage'

const drafts: MonthlyAttendanceDraft[] = [
  {
    id: 2,
    user_id: 42,
    target_month: '2026-07',
    status: 'needs_review',
    version: 3,
    source_type: 'work_report',
    source_reference: null,
    submitted_at: null,
    created_at: '2026-07-15T09:00:00+09:00',
  },
  {
    id: 1,
    user_id: 42,
    target_month: '2026-06',
    status: 'submitted',
    version: 5,
    source_type: 'work_report',
    source_reference: null,
    submitted_at: '2026-06-30T09:00:00+09:00',
    created_at: '2026-06-15T09:00:00+09:00',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['monthly-attendance-drafts', 'me'], drafts)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <MyMonthlyAttendanceDraftsPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/MyMonthlyAttendanceDraftsPage',
  component: MyMonthlyAttendanceDraftsPage,
} satisfies Meta<typeof MyMonthlyAttendanceDraftsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
