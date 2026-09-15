import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { PaidLeaveScheduleEntryDetail } from '../../api/types'
import { PaidLeaveScheduleAssessmentPanel } from './PaidLeaveScheduleAssessmentPanel'

const baseEntry: PaidLeaveScheduleEntryDetail = {
  id: 'entry-1',
  user_id: 'user-1',
  user_name: '鈴木一郎',
  scheduled_on: '2026-10-01',
  category: 'normal',
  candidate_grant_days: 11,
  status: 'NotEligible',
  latest_assessment_id: 'assessment-1',
  is_manually_overridden: false,
  attendance_rate: 75,
  manual_override_reason: null,
  cancelled_reason: null,
  grant_id: null,
  assessments: [
    {
      id: 'assessment-1',
      period_start: '2025-10-01',
      period_end: '2026-10-01',
      denominator_days: 240,
      attendance_days: 180,
      excluded_days: 5,
      attendance_rate: 75,
      policy_version: 'v1',
      automatic_result: 'NotEligible',
      final_result: 'NotEligible',
      override_reason: null,
      overridden_by_user_id: null,
      created_at: '2026-09-01T00:00:00Z',
    },
  ],
}

const meta = {
  title: 'Components/PaidLeaveScheduleAssessmentPanel',
  component: PaidLeaveScheduleAssessmentPanel,
  args: {
    onReassess: fn(),
    onOverride: fn(),
  },
} satisfies Meta<typeof PaidLeaveScheduleAssessmentPanel>

export default meta
type Story = StoryObj<typeof meta>

export const NotEligible: Story = {
  args: { entry: baseEntry },
}

export const NeedsReview: Story = {
  args: {
    entry: { ...baseEntry, status: 'NeedsReview', category: 'needs_review', assessments: [] },
    workStyleSettingsHref: '/admin/work-styles',
  },
}

export const Eligible: Story = {
  args: {
    entry: { ...baseEntry, status: 'Eligible', assessments: [{ ...baseEntry.assessments[0], attendance_rate: 92, automatic_result: 'Eligible', final_result: 'Eligible' }] },
  },
}
