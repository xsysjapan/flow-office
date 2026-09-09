import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, PaidLeaveScheduleEntry } from '../../api/types'
import { PaidLeaveSchedulePage } from './PaidLeaveSchedulePage'

const entries: PaidLeaveScheduleEntry[] = [
  {
    id: 'entry-1',
    user_id: 'user-1',
    user_name: '高橋 太郎',
    scheduled_on: '2026-10-01',
    category: 'regular',
    candidate_grant_days: 11,
    status: 'Eligible',
    needs_review_due_to_conflict: false,
    manual_override_reason: null,
    manual_override_by_user_id: null,
    manual_override_at: null,
    granted_paid_leave_grant_id: null,
    assessment: {
      period_start: '2025-10-01',
      period_end: '2026-09-30',
      denominator_days: 240,
      attendance_days: 221,
      excluded_days: 3,
      attendance_rate: 0.92,
      policy_version: 'v1',
      automatic_result: 'Eligible',
      final_result: 'Eligible',
      override_reason: null,
    },
  },
  {
    id: 'entry-2',
    user_id: 'user-2',
    user_name: '伊藤 花子',
    scheduled_on: '2026-10-05',
    category: 'proportional',
    candidate_grant_days: 7,
    status: 'NotEligible',
    needs_review_due_to_conflict: false,
    manual_override_reason: null,
    manual_override_by_user_id: null,
    manual_override_at: null,
    granted_paid_leave_grant_id: null,
    assessment: {
      period_start: '2025-10-05',
      period_end: '2026-10-04',
      denominator_days: 150,
      attendance_days: 114,
      excluded_days: 0,
      attendance_rate: 0.76,
      policy_version: 'v1',
      automatic_result: 'NotEligible',
      final_result: 'NotEligible',
      override_reason: null,
    },
  },
  {
    id: 'entry-3',
    user_id: 'user-3',
    user_name: '加藤 由美',
    scheduled_on: '2026-10-12',
    category: 'regular',
    candidate_grant_days: 12,
    status: 'NeedsReview',
    needs_review_due_to_conflict: true,
    manual_override_reason: null,
    manual_override_by_user_id: null,
    manual_override_at: null,
    granted_paid_leave_grant_id: null,
    assessment: {
      period_start: '2025-10-12',
      period_end: '2026-10-11',
      denominator_days: 243,
      attendance_days: null,
      excluded_days: null,
      attendance_rate: null,
      policy_version: 'v1',
      automatic_result: 'NeedsReview',
      final_result: 'NeedsReview',
      override_reason: null,
    },
  },
]

const paginated: Paginated<PaidLeaveScheduleEntry> = {
  data: entries,
  meta: { current_page: 1, last_page: 1, total: entries.length },
  links: { next: null, prev: null },
}

function withSeeded(data: Paginated<PaidLeaveScheduleEntry>, initialPath = '/admin/paid-leave/schedule') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'schedule-entries', { status: 'all', page: 1 }], data)

  return function Decorator() {
    return (
      <MemoryRouter initialEntries={[initialPath]}>
        <QueryClientProvider client={queryClient}>
          <PaidLeaveSchedulePage />
        </QueryClientProvider>
      </MemoryRouter>
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
  render: withSeeded(paginated),
}

export const Empty: Story = {
  render: withSeeded({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }),
}
