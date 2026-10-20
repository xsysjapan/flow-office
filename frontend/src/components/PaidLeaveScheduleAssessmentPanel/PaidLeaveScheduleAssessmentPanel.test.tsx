import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { PaidLeaveScheduleEntryDetail } from '../../api/types'
import { PaidLeaveScheduleAssessmentPanel, type PaidLeaveScheduleAssessmentPanelProps } from './PaidLeaveScheduleAssessmentPanel'

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

const EDIT_BUTTON = '区分・日数を修正する'

describe('PaidLeaveScheduleAssessmentPanel', () => {
  it('shows the latest assessment breakdown', () => {
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={vi.fn()} onOverride={vi.fn()} onEdit={vi.fn()} />)

    expect(screen.getByText('鈴木一郎')).toBeInTheDocument()
    expect(screen.getByText('240日')).toBeInTheDocument()
    expect(screen.getByText('75%')).toBeInTheDocument()
  })

  it('calls onReassess when the reassess button is clicked', async () => {
    const onReassess = vi.fn()
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={onReassess} onOverride={vi.fn()} onEdit={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '再判定する' }))
    expect(onReassess).toHaveBeenCalled()
  })

  it('shows a field error and does not submit when the override reason is empty', async () => {
    const onOverride = vi.fn()
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={vi.fn()} onOverride={onOverride} onEdit={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '判定結果を上書きする' }))

    expect(await screen.findByText('上書き理由を入力してください。')).toBeInTheDocument()
    expect(onOverride).not.toHaveBeenCalled()
  })

  it('submits the override with the selected result and reason', async () => {
    const onOverride = vi.fn()
    render(<PaidLeaveScheduleAssessmentPanel entry={entry} onReassess={vi.fn()} onOverride={onOverride} onEdit={vi.fn()} />)

    await userEvent.selectOptions(screen.getByLabelText('上書き後の判定'), 'Eligible')
    await userEvent.type(screen.getByLabelText('上書き理由'), '出勤簿の記録漏れを確認したため')
    await userEvent.click(screen.getByRole('button', { name: '判定結果を上書きする' }))

    expect(onOverride).toHaveBeenCalledWith({ final_result: 'Eligible', reason: '出勤簿の記録漏れを確認したため' })
  })

  it('reports unsaved override input to the parent and clears it on unmount', async () => {
    const onDirtyChange = vi.fn()
    const { unmount } = render(
      <PaidLeaveScheduleAssessmentPanel
        entry={entry}
        onReassess={vi.fn()}
        onOverride={vi.fn()}
        onEdit={vi.fn()}
        onDirtyChange={onDirtyChange}
      />,
    )
    expect(onDirtyChange).toHaveBeenLastCalledWith(false)

    await userEvent.type(screen.getByLabelText('上書き理由'), 'a')
    expect(onDirtyChange).toHaveBeenLastCalledWith(true)

    unmount()
    expect(onDirtyChange).toHaveBeenLastCalledWith(false)
  })

  it('reports unsaved input when the override result differs from the initial selection', async () => {
    const onDirtyChange = vi.fn()
    render(
      <PaidLeaveScheduleAssessmentPanel
        entry={entry}
        onReassess={vi.fn()}
        onOverride={vi.fn()}
        onEdit={vi.fn()}
        onDirtyChange={onDirtyChange}
      />,
    )

    await userEvent.selectOptions(screen.getByLabelText('上書き後の判定'), 'NotEligible')
    expect(onDirtyChange).toHaveBeenLastCalledWith(true)
  })

  it('shows a link to the WorkStyle settings screen for NeedsReview entries', () => {
    render(
      <PaidLeaveScheduleAssessmentPanel
        entry={{ ...entry, status: 'NeedsReview' }}
        onReassess={vi.fn()}
        onOverride={vi.fn()}
        onEdit={vi.fn()}
        workStyleSettingsHref="/admin/work-styles"
      />,
    )

    expect(screen.getByRole('link', { name: '原因(勤務形態設定)を確認する' })).toHaveAttribute(
      'href',
      '/admin/work-styles',
    )
  })

  describe('区分・日数を修正(step 4)', () => {
    function renderPanel(
      props: Partial<Omit<PaidLeaveScheduleAssessmentPanelProps, 'entry'>> & {
        entryOverride?: Partial<PaidLeaveScheduleEntryDetail>
      } = {},
    ) {
      const onEdit = vi.fn(props.onEdit)
      const onDirtyChange = props.onDirtyChange ?? vi.fn()
      const view = render(
        <PaidLeaveScheduleAssessmentPanel
          entry={{ ...entry, ...props.entryOverride }}
          onReassess={vi.fn()}
          onOverride={vi.fn()}
          onEdit={onEdit}
          onDirtyChange={onDirtyChange}
          editIsPending={props.editIsPending}
          editError={props.editError}
        />,
      )
      return { ...view, onEdit, onDirtyChange }
    }

    it('does not show the edit step for Granted or Cancelled entries', () => {
      const { unmount } = renderPanel({ entryOverride: { status: 'Granted' } })
      expect(screen.queryByRole('heading', { name: /区分・日数を修正/ })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: EDIT_BUTTON })).not.toBeInTheDocument()
      unmount()

      renderPanel({ entryOverride: { status: 'Cancelled' } })
      expect(screen.queryByRole('button', { name: EDIT_BUTTON })).not.toBeInTheDocument()
    })

    it('shows the edit step with the current values as initial values', () => {
      renderPanel()

      expect(screen.getByRole('heading', { name: /区分・日数を修正/ })).toBeInTheDocument()
      expect(screen.getByLabelText('区分')).toHaveValue('normal')
      expect(screen.getByLabelText('付与候補日数')).toHaveValue(11)
      expect(screen.getByText('付与ルール・法定付与日数が変更されると、未確定の付与予定はこの修正も含めて新しい内容で作り直されます。')).toBeInTheDocument()
    })

    it('disables the submit button while nothing has changed', () => {
      renderPanel()
      expect(screen.getByRole('button', { name: EDIT_BUTTON })).toBeDisabled()
    })

    it('shows a field error and does not submit when the reason is empty', async () => {
      const { onEdit } = renderPanel()

      await userEvent.clear(screen.getByLabelText('付与候補日数'))
      await userEvent.type(screen.getByLabelText('付与候補日数'), '12')
      await userEvent.click(screen.getByRole('button', { name: EDIT_BUTTON }))

      expect(await screen.findByText('修正理由を入力してください。')).toBeInTheDocument()
      expect(onEdit).not.toHaveBeenCalled()
    })

    it('sends only the changed candidate days when only the days were changed', async () => {
      const { onEdit } = renderPanel()

      await userEvent.clear(screen.getByLabelText('付与候補日数'))
      await userEvent.type(screen.getByLabelText('付与候補日数'), '12.5')
      await userEvent.type(screen.getByLabelText('修正理由'), '勤務実績に合わせて日数を補正')
      await userEvent.click(screen.getByRole('button', { name: EDIT_BUTTON }))

      expect(onEdit).toHaveBeenCalledTimes(1)
      const [input] = onEdit.mock.calls[0]
      expect(input).toEqual({ candidate_grant_days: 12.5, reason: '勤務実績に合わせて日数を補正' })
      expect(input).not.toHaveProperty('category')
    })

    it('sends only the changed category when only the category was changed', async () => {
      const { onEdit } = renderPanel()

      await userEvent.selectOptions(screen.getByLabelText('区分'), 'shift')
      await userEvent.type(screen.getByLabelText('修正理由'), 'シフト勤務に変更')
      await userEvent.click(screen.getByRole('button', { name: EDIT_BUTTON }))

      expect(onEdit).toHaveBeenCalledTimes(1)
      const [input] = onEdit.mock.calls[0]
      expect(input).toEqual({ category: 'shift', reason: 'シフト勤務に変更' })
      expect(input).not.toHaveProperty('candidate_grant_days')
    })

    it('starts with an empty category for needs_review entries and does not send it while unselected', async () => {
      const { onEdit } = renderPanel({
        entryOverride: { status: 'NeedsReview', category: 'needs_review', candidate_grant_days: null, assessments: [] },
      })

      expect(screen.getByLabelText('区分')).toHaveValue('')
      expect(screen.getByRole('option', { name: '選択してください' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: EDIT_BUTTON })).toBeDisabled()

      await userEvent.selectOptions(screen.getByLabelText('区分'), 'proportional')
      await userEvent.type(screen.getByLabelText('修正理由'), '比例付与の対象')
      await userEvent.click(screen.getByRole('button', { name: EDIT_BUTTON }))

      const [input] = onEdit.mock.calls[0]
      expect(input).toEqual({ category: 'proportional', reason: '比例付与の対象' })
    })

    it('shows the NeedsReview guidance for needs_review entries', () => {
      renderPanel({ entryOverride: { status: 'NeedsReview' } })
      expect(screen.getByText('区分を修正した後、再判定または判定結果の上書きを行ってください。')).toBeInTheDocument()
    })

    it('does not show the NeedsReview guidance for other statuses', () => {
      renderPanel()
      expect(screen.queryByText('区分を修正した後、再判定または判定結果の上書きを行ってください。')).not.toBeInTheDocument()
    })

    it('shows the manual edit reason for overridden entries', () => {
      renderPanel({
        entryOverride: { is_manually_overridden: true, manual_override_reason: '就業規則の改定に合わせて修正' },
      })
      expect(screen.getByText('修正理由: 就業規則の改定に合わせて修正')).toBeInTheDocument()
    })

    it('does not show a manual edit reason for entries that were not edited', () => {
      renderPanel()
      expect(screen.queryByText(/修正理由: /)).not.toBeInTheDocument()
    })

    it('blocks submission and shows a message when the days input is cleared', async () => {
      const { onEdit } = renderPanel()

      await userEvent.clear(screen.getByLabelText('付与候補日数'))

      expect(screen.getByText('付与候補日数を入力してください。')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: EDIT_BUTTON })).toBeDisabled()
      expect(onEdit).not.toHaveBeenCalled()
    })

    it('shows the edit error from the parent', () => {
      renderPanel({ editError: new Error('確定済みの付与予定は修正できません。') })
      expect(screen.getByText('確定済みの付与予定は修正できません。')).toBeInTheDocument()
    })

    it('reports unsaved edit input to the parent', async () => {
      const { onDirtyChange } = renderPanel()
      expect(onDirtyChange).toHaveBeenLastCalledWith(false)

      await userEvent.type(screen.getByLabelText('修正理由'), 'a')
      expect(onDirtyChange).toHaveBeenLastCalledWith(true)
    })

    it('reports unsaved input when the days differ from the initial value', async () => {
      const { onDirtyChange } = renderPanel()

      await userEvent.clear(screen.getByLabelText('付与候補日数'))
      await userEvent.type(screen.getByLabelText('付与候補日数'), '10')
      expect(onDirtyChange).toHaveBeenLastCalledWith(true)
    })

    it('clears the edit dirty state after a successful edit', async () => {
      const onEdit = vi.fn((_input: unknown, callbacks: { onSuccess: () => void }) => callbacks.onSuccess())
      const { onDirtyChange } = renderPanel({ onEdit })

      await userEvent.clear(screen.getByLabelText('付与候補日数'))
      await userEvent.type(screen.getByLabelText('付与候補日数'), '10')
      await userEvent.type(screen.getByLabelText('修正理由'), '日数を補正')
      expect(onDirtyChange).toHaveBeenLastCalledWith(true)

      await userEvent.click(screen.getByRole('button', { name: EDIT_BUTTON }))

      expect(onEdit).toHaveBeenCalledTimes(1)
      expect(screen.getByLabelText('修正理由')).toHaveValue('')
      expect(onDirtyChange).toHaveBeenLastCalledWith(false)
      expect(screen.getByRole('button', { name: EDIT_BUTTON })).toBeDisabled()
    })
  })
})
