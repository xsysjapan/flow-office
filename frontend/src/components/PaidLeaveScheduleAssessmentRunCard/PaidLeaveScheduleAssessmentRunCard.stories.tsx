import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { PaidLeaveScheduleAssessmentRun, PaidLeaveScheduleAssessmentRunStatus } from '../../api/types'
import { PaidLeaveScheduleAssessmentRunCard } from './PaidLeaveScheduleAssessmentRunCard'

const nextRunAt = '2026-11-01T03:00:00'

const succeededRun: PaidLeaveScheduleAssessmentRun = {
  id: 'run-1',
  status: 'succeeded',
  trigger: 'schedule',
  requested_by_name: null,
  started_at: '2026-10-01T03:00:00',
  finished_at: '2026-10-01T03:02:00',
  summary: { targets: 12, assessed: 12, eligible: 10, not_eligible: 1, needs_review: 1, failed: 0 },
  error_message: null,
}

const status = (latestRun: PaidLeaveScheduleAssessmentRun | null): PaidLeaveScheduleAssessmentRunStatus => ({
  next_run_at: nextRunAt,
  latest_run: latestRun,
})

const meta = {
  title: 'Components/PaidLeaveScheduleAssessmentRunCard',
  component: PaidLeaveScheduleAssessmentRunCard,
  args: {
    onStart: fn(),
    onConfirmOpenChange: fn(),
  },
} satisfies Meta<typeof PaidLeaveScheduleAssessmentRunCard>

export default meta
type Story = StoryObj<typeof meta>

/** 記録なし: 次回の定期実行だけを出す。 */
export const NoRecord: Story = {
  args: { status: status(null) },
}

/** 待機中: 実行中でなく、前回の完了の結果を出している(次回の定期実行を表示)。 */
export const Waiting: Story = {
  args: { status: status(succeededRun) },
}

/** 実行中(queued): 「判定を実行中です」と、押せない理由。 */
export const Queued: Story = {
  args: { status: status({ ...succeededRun, id: 'run-2', status: 'queued', trigger: 'manual', requested_by_name: '山田花子', finished_at: null, summary: null }) },
}

export const Running: Story = {
  args: { status: status({ ...succeededRun, id: 'run-2', status: 'running', trigger: 'manual', requested_by_name: '山田花子', finished_at: null, summary: null }) },
}

/** 完了(手動の実行): 依頼者名を出す。 */
export const SucceededManual: Story = {
  args: { status: status({ ...succeededRun, trigger: 'manual', requested_by_name: '山田花子', finished_at: '2026-10-20T14:05:00' }) },
}

/** 失敗: 警告の見た目で失敗の理由を出す。 */
export const Failed: Story = {
  args: {
    status: status({
      ...succeededRun,
      status: 'failed',
      summary: { targets: 12, assessed: 11, eligible: 10, not_eligible: 0, needs_review: 1, failed: 1 },
      error_message: '1件の判定に失敗しました。',
    }),
  },
}

/** 判定の開始を受け付けたあとの確認ダイアログ内での受付中。 */
export const Starting: Story = {
  args: { status: status(succeededRun), isStarting: true },
}

/** 409(実行中): 確認ダイアログ内に理由を表示する。 */
export const StartConflict: Story = {
  args: { status: status(succeededRun), startError: new Error('判定は既に実行中です。') },
}

/** 取得エラー: 状態が取れないときは、エラーを出しつつ判定の操作だけは残す。 */
export const StatusError: Story = {
  args: { status: undefined, statusError: new Error('通信に失敗しました。') },
}
