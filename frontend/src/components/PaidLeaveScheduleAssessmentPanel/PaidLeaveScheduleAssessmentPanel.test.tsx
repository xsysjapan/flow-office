import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { PaidLeaveScheduleEntryDetail } from '../../api/types'
import { PaidLeaveScheduleAssessmentPanel } from './PaidLeaveScheduleAssessmentPanel'

const entry: PaidLeaveScheduleEntryDetail = {
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

describe('PaidLeaveScheduleAssessmentPanel', () => {
  it('shows the latest assessment breakdown', () => {
    render(
      <PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={vi.fn()} onOverride={vi.fn()} />,
    )

    expect(screen.getByText('鈴木一郎')).toBeInTheDocument()
    expect(screen.getByText('240日')).toBeInTheDocument()
    expect(screen.getByText('75%')).toBeInTheDocument()
  })

  it('calls onReassess when the reassess button is clicked', async () => {
    const onReassess = vi.fn()
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={onReassess} onOverride={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '再判定する' }))
    expect(onReassess).toHaveBeenCalled()
  })

  it('shows a field error and does not submit when the override reason is empty', async () => {
    const onOverride = vi.fn()
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={vi.fn()} onOverride={onOverride} />)

    await userEvent.click(screen.getByRole('button', { name: '判定結果を上書きする' }))

    expect(await screen.findByText('上書き理由を入力してください。')).toBeInTheDocument()
    expect(onOverride).not.toHaveBeenCalled()
  })

  it('submits the override with the selected result and reason', async () => {
    const onOverride = vi.fn()
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={vi.fn()} onOverride={onOverride} />)

    await userEvent.selectOptions(screen.getByLabelText('上書き後の判定'), 'Eligible')
    await userEvent.type(screen.getByLabelText('上書き理由'), '出勤簿の記録漏れを確認したため')
    await userEvent.click(screen.getByRole('button', { name: '判定結果を上書きする' }))

    expect(onOverride).toHaveBeenCalledWith({ final_result: 'Eligible', reason: '出勤簿の記録漏れを確認したため' })
  })

  it('shows a link to the WorkStyle settings screen for NeedsReview entries', () => {
    render(
      <PaidLeaveScheduleAssessmentPanel
        entry={{ ...entry, status: 'NeedsReview' }}
        onReassess={vi.fn()}
        onOverride={vi.fn()}
        workStyleSettingsHref="/admin/work-styles"
      />,
    )

    expect(screen.getByRole('link', { name: '原因(勤務形態設定)を確認する' })).toHaveAttribute(
      'href',
      '/admin/work-styles',
    )
  })
})
