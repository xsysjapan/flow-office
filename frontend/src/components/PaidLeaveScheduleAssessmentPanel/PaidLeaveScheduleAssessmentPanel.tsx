import { useState } from 'react'
import type { PaidLeaveScheduleEntryDetail } from '../../api/types'
import { paidLeaveScheduleCategoryLabel, paidLeaveScheduleStatusLabel } from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { NativeSelect } from '../ui/native-select'
import { Separator } from '../ui/separator'
import { Textarea } from '../ui/textarea'

export interface PaidLeaveScheduleAssessmentPanelProps {
  entry: PaidLeaveScheduleEntryDetail
  onReassess: () => void
  reassessIsPending?: boolean
  reassessError?: unknown
  onOverride: (input: { final_result: 'Eligible' | 'NotEligible'; reason: string }) => void
  overrideIsPending?: boolean
  overrideError?: unknown
  /** 対象社員のWorkStyle設定画面へのリンク(NeedsReview判定の原因確認・修正導線、spec.md論点15-3)。 */
  workStyleSettingsHref?: string
}

function SectionHeading({ step, children }: { step: number; children: string }) {
  return (
    <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
      <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground">
        {step}
      </span>
      {children}
    </h3>
  )
}

/**
 * 付与予定Scheduleエントリの詳細Sheetの中身。「勤怠データを確認」→「再判定」→
 * 「判定結果を上書き」の3ステップを縦に並べる(依頼書§40・spec.md論点13、
 * Sheet化はspec.md論点15-4)。各ステップの操作はIdle→Submitting→Success/Errorの
 * フィードバックを持つ(ui-interaction-patterns §2.18)。
 */
export function PaidLeaveScheduleAssessmentPanel({
  entry,
  onReassess,
  reassessIsPending = false,
  reassessError,
  onOverride,
  overrideIsPending = false,
  overrideError,
  workStyleSettingsHref,
}: PaidLeaveScheduleAssessmentPanelProps) {
  const [finalResult, setFinalResult] = useState<'Eligible' | 'NotEligible'>('Eligible')
  const [reason, setReason] = useState('')
  const [reasonTouched, setReasonTouched] = useState(false)

  const latestAssessment = entry.assessments.length > 0 ? entry.assessments[entry.assessments.length - 1] : null
  const statusMeta = paidLeaveScheduleStatusLabel(entry.status)
  const reasonError = reasonTouched && reason.trim() === '' ? '上書き理由を入力してください。' : undefined

  function handleOverrideSubmit() {
    setReasonTouched(true)
    if (reason.trim() === '') return
    onOverride({ final_result: finalResult, reason: reason.trim() })
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium text-foreground">{entry.user_name ?? entry.user_id}</span>
          <Badge tone="neutral">{paidLeaveScheduleCategoryLabel(entry.category)}</Badge>
          <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>
        </div>
        <p className="mt-1 text-sm text-muted-foreground">
          付与予定日 {entry.scheduled_on} / 付与候補日数 {entry.candidate_grant_days ?? '-'}日
        </p>
        {entry.is_manually_overridden && entry.manual_override_reason && (
          <p className="mt-1 text-sm text-muted-foreground">上書き理由: {entry.manual_override_reason}</p>
        )}
        {entry.status === 'NeedsReview' && workStyleSettingsHref && (
          <a href={workStyleSettingsHref} className="mt-2 inline-block text-sm text-primary hover:underline">
            原因(勤務形態設定)を確認する
          </a>
        )}
      </div>

      <Separator />

      <div className="flex flex-col gap-2">
        <SectionHeading step={1}>勤怠データを確認</SectionHeading>
        {latestAssessment ? (
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt className="font-medium text-muted-foreground">対象期間</dt>
            <dd className="text-foreground">
              {latestAssessment.period_start} 〜 {latestAssessment.period_end}
            </dd>
            <dt className="font-medium text-muted-foreground">分母日数</dt>
            <dd className="text-foreground">{latestAssessment.denominator_days ?? '-'}日</dd>
            <dt className="font-medium text-muted-foreground">出勤日数</dt>
            <dd className="text-foreground">{latestAssessment.attendance_days ?? '-'}日</dd>
            <dt className="font-medium text-muted-foreground">除外日数</dt>
            <dd className="text-foreground">{latestAssessment.excluded_days ?? '-'}日</dd>
            <dt className="font-medium text-muted-foreground">出勤率</dt>
            <dd className="text-foreground">
              {latestAssessment.attendance_rate !== null ? `${latestAssessment.attendance_rate}%` : '-'}
            </dd>
            <dt className="font-medium text-muted-foreground">自動判定</dt>
            <dd className="text-foreground">
              {latestAssessment.automatic_result ? paidLeaveScheduleStatusLabel(latestAssessment.automatic_result).label : '-'}
            </dd>
          </dl>
        ) : (
          <p className="text-sm text-muted-foreground">まだ判定が実行されていません。</p>
        )}
      </div>

      <Separator />

      <div className="flex flex-col gap-2">
        <SectionHeading step={2}>再判定</SectionHeading>
        <p className="text-sm text-muted-foreground">同一条件で出勤率判定を再実行します。</p>
        {Boolean(reassessError) && <ErrorMessage error={reassessError} fallback="再判定に失敗しました。" />}
        <div>
          <Button variant="secondary" isLoading={reassessIsPending} onClick={onReassess}>
            再判定する
          </Button>
        </div>
      </div>

      <Separator />

      <div className="flex flex-col gap-2">
        <SectionHeading step={3}>判定結果を上書き</SectionHeading>
        {Boolean(overrideError) && <ErrorMessage error={overrideError} fallback="上書きに失敗しました。" />}
        <FormField label="上書き後の判定" htmlFor="schedule-override-final-result">
          <NativeSelect
            id="schedule-override-final-result"
            value={finalResult}
            onChange={(e) => setFinalResult(e.target.value as 'Eligible' | 'NotEligible')}
          >
            <option value="Eligible">付与対象(Eligible)</option>
            <option value="NotEligible">対象外(NotEligible)</option>
          </NativeSelect>
        </FormField>
        <FormField label="上書き理由" htmlFor="schedule-override-reason" required error={reasonError}>
          <Textarea
            id="schedule-override-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            onBlur={() => setReasonTouched(true)}
          />
        </FormField>
        <div>
          <Button variant="danger" isLoading={overrideIsPending} onClick={handleOverrideSubmit}>
            判定結果を上書きする
          </Button>
        </div>
      </div>
    </div>
  )
}
