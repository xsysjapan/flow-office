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
    nominal_service_months: null,
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
    nominal_service_months: null,
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
    nominal_service_months: null,
    status: 'NeedsReview',
    latest_assessment_id: null,
    is_manually_overridden: false,
    attendance_rate: null,
  },
]

/** 名目6か月と18か月の組(同じ社員・同じ付与予定日・どちらも付与対象)。一覧で片方を選ぶと両方が選ばれる。 */
const pairEntries: PaidLeaveScheduleEntry[] = [
  {
    id: 'entry-pair-6',
    user_id: 'user-6',
    user_name: '佐藤三郎',
    scheduled_on: '2026-04-01',
    category: 'normal',
    candidate_grant_days: 10,
    nominal_service_months: 6,
    status: 'Eligible',
    latest_assessment_id: 'assessment-pair-6',
    is_manually_overridden: false,
    attendance_rate: 95,
  },
  {
    id: 'entry-pair-18',
    user_id: 'user-6',
    user_name: '佐藤三郎',
    scheduled_on: '2026-04-01',
    category: 'normal',
    candidate_grant_days: 11,
    nominal_service_months: 18,
    status: 'Eligible',
    latest_assessment_id: 'assessment-pair-18',
    is_manually_overridden: false,
    attendance_rate: 95,
  },
]

const overdueEntries: PaidLeaveScheduleEntry[] = [
  {
    id: 'entry-4',
    user_id: 'user-4',
    user_name: '高橋美咲',
    scheduled_on: '2026-09-20',
    category: 'normal',
    candidate_grant_days: 10,
    nominal_service_months: null,
    status: 'Eligible',
    latest_assessment_id: 'assessment-4',
    is_manually_overridden: false,
    attendance_rate: 90,
  },
]

/**
 * 一覧・超過件数のキャッシュを仕込む。キーは`usePaidLeaveScheduleEntries`の組み立て方
 * (`['paid-leave','schedule-entries','list', params]`、未指定のパラメータは含めない)に合わせる。
 */
function withSeeded(
  seedEntries: PaidLeaveScheduleEntry[],
  options: { overdue?: PaidLeaveScheduleEntry[]; initialPath?: string } = {},
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'schedule-entries', 'list', { filter: 'all' }], seedEntries)
  queryClient.setQueryData(['paid-leave', 'schedule-entries', 'list', { filter: 'overdue' }], options.overdue ?? [])
  queryClient.setQueryData(['paid-leave', 'schedule-entries', 'list', { filter: 'needs_review' }], [])
  // 自動判定カードの状態(前回は完了、次回は11月1日 3:00)。取得エラーにならないよう仕込む。
  queryClient.setQueryData(['paid-leave', 'schedule-assessment-runs', 'status'], {
    next_run_at: '2026-11-01T03:00:00',
    latest_run: {
      id: 'run-1',
      status: 'succeeded',
      trigger: 'schedule',
      requested_by_name: null,
      started_at: '2026-10-01T03:00:00',
      finished_at: '2026-10-01T03:02:00',
      summary: { targets: 3, assessed: 3, eligible: 1, not_eligible: 1, needs_review: 1, failed: 0 },
      error_message: null,
    },
  })

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[options.initialPath ?? '/admin/paid-leave/schedule']}>
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

export const WithOverdue: Story = {
  render: withSeeded(entries, { overdue: overdueEntries }),
}

export const WithPair: Story = {
  render: withSeeded(pairEntries),
}

export const Empty: Story = {
  render: withSeeded([]),
}

export const FilteredEmpty: Story = {
  render: withSeeded([], { initialPath: '/admin/paid-leave/schedule?filter=needs_review' }),
}
