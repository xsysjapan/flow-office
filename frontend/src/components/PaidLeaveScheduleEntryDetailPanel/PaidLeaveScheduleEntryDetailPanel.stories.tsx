import type { Meta, StoryObj } from '@storybook/react-vite'
import type { PaidLeaveScheduleEntry } from '../../api/types'
import { PaidLeaveScheduleEntryDetailPanel } from './PaidLeaveScheduleEntryDetailPanel'

const baseEntry: PaidLeaveScheduleEntry = {
  id: 'entry-1',
  user_id: 'user-1',
  user_name: '加藤 由美',
  scheduled_on: '2026-10-12',
  category: 'regular',
  candidate_grant_days: 12,
  status: 'NeedsReview',
  needs_review_due_to_conflict: false,
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
}

const meta = {
  title: 'Components/PaidLeaveScheduleEntryDetailPanel',
  component: PaidLeaveScheduleEntryDetailPanel,
} satisfies Meta<typeof PaidLeaveScheduleEntryDetailPanel>

export default meta
type Story = StoryObj<typeof meta>

export const NeedsReview: Story = {
  args: {
    entry: baseEntry,
    onReassess: () => {},
    onOverride: () => {},
  },
}

export const Eligible: Story = {
  args: {
    entry: {
      ...baseEntry,
      status: 'Eligible',
      candidate_grant_days: 11,
      assessment: {
        ...baseEntry.assessment,
        attendance_days: 230,
        excluded_days: 5,
        attendance_rate: 0.92,
        automatic_result: 'Eligible',
        final_result: 'Eligible',
      },
    },
    onReassess: () => {},
    onOverride: () => {},
  },
}

export const OverriddenNotEligible: Story = {
  args: {
    entry: {
      ...baseEntry,
      status: 'NotEligible',
      manual_override_reason: '育休中のため出勤率算定期間から除外して判定した。',
      manual_override_by_user_id: 'admin-1',
      manual_override_at: '2026-09-01T09:00:00+09:00',
      assessment: {
        ...baseEntry.assessment,
        attendance_days: 150,
        excluded_days: 90,
        attendance_rate: 0.62,
        automatic_result: 'NotEligible',
        final_result: 'NotEligible',
      },
    },
    onReassess: () => {},
    onOverride: () => {},
  },
}
