import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { PaidLeaveScheduleEntry } from '../../api/types'
import { PaidLeaveSchedulePage } from './PaidLeaveSchedulePage'

const entries: PaidLeaveScheduleEntry[] = [
  {
    id: 'entry-1',
    user_id: 'user-1',
    user_name: '鈴木一郎',
    scheduled_on: '2026-10-01',
    category: 'normal',
    candidate_grant_days: 11,
    status: 'Eligible',
    latest_assessment_id: 'assessment-1',
    is_manually_overridden: false,
    attendance_rate: 92,
  },
  {
    id: 'entry-2',
    user_id: 'user-2',
    user_name: '佐藤花子',
    scheduled_on: '2026-10-05',
    category: 'proportional',
    candidate_grant_days: 7,
    status: 'NotEligible',
    latest_assessment_id: 'assessment-2',
    is_manually_overridden: false,
    attendance_rate: 65,
  },
  {
    id: 'entry-3',
    user_id: 'user-3',
    user_name: '田中次郎',
    scheduled_on: '2026-10-10',
    category: 'needs_review',
    candidate_grant_days: null,
    status: 'NeedsReview',
    latest_assessment_id: null,
    is_manually_overridden: false,
    attendance_rate: null,
  },
]

function withSeeded(seedEntries: PaidLeaveScheduleEntry[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'schedule-entries', 'all'], seedEntries)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <PaidLeaveSchedulePage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/PaidLeave/PaidLeaveSchedulePage',
  component: PaidLeaveSchedulePage,
} satisfies Meta<typeof PaidLeaveSchedulePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithEntries: Story = {
  render: withSeeded(entries),
}

export const Empty: Story = {
  render: withSeeded([]),
}
