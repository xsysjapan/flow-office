import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { AttendanceSubmissionReminderExclusion } from '../../api/types'
import { AttendanceSubmissionReminderExclusionPanel } from './AttendanceSubmissionReminderExclusionPanel'

const USER_ID = '11111111-1111-1111-1111-111111111111'

const exclusions: AttendanceSubmissionReminderExclusion[] = [
  {
    id: 'exclusion-1',
    user_id: USER_ID,
    year_month: '2026-06',
    reason: '利用開始日より前の月を誤って督促対象にしていたため',
    excluded_by_user_id: '22222222-2222-2222-2222-222222222222',
    created_at: '2026-07-10T09:00:00+09:00',
  },
]

function withSeeded(data: AttendanceSubmissionReminderExclusion[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance-submission-reminder-exclusions', USER_ID], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AttendanceSubmissionReminderExclusionPanel userId={USER_ID} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/AttendanceSubmissionReminderExclusionPanel',
  component: AttendanceSubmissionReminderExclusionPanel,
} satisfies Meta<typeof AttendanceSubmissionReminderExclusionPanel>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {
  args: { userId: USER_ID },
  render: withSeeded([]),
}

export const WithExclusions: Story = {
  args: { userId: USER_ID },
  render: withSeeded(exclusions),
}
