import { useEffect, useState } from 'react'
import type { EditPaidLeaveScheduleEntryInput } from '../../api/paidLeave'
import type { PaidLeaveScheduleEntryDetail } from '../../api/types'
import { paidLeaveScheduleCategoryLabel, paidLeaveScheduleStatusLabel } from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Separator } from '../ui/separator'
import { Textarea } from '../ui/textarea'

/** 区分の修正で選べる値(needs_reviewは判定の結果であり、修正の選択肢には出さない)。 */
type EditableCategory = NonNullable<EditPaidLeaveScheduleEntryInput['category']>
const EDITABLE_CATEGORIES: EditableCategory[] = ['normal', 'proportional', 'shift']

/** 区分・候補日数のフォーム値。区分は未選択を空文字で表す(needs_reviewの行の初期値)。 */
interface EditFormValues {
  category: EditableCategory | ''
  days: string
}

function initialEditValues(entry: PaidLeaveScheduleEntryDetail): EditFormValues {
  return {
    category: entry.category === 'needs_review' ? '' : entry.category,
    days: entry.candidate_grant_days === null ? '' : String(entry.candidate_grant_days),
  }
}

export interface PaidLeaveScheduleAssessmentPanelProps {
  entry: PaidLeaveScheduleEntryDetail
  onReassess: () => void
  reassessIsPending?: boolean
  reassessError?: unknown
  onOverride: (input: { final_result: 'Eligible' | 'NotEligible'; reason: string }) => void
  overrideIsPending?: boolean
  overrideError?: unknown
  /**
   * 区分・候補日数の修正(spec.md論点3)。成功時にcallbacks.onSuccessを呼ぶと、修正フォームの
   * 入力を保存済みとして扱う(理由を空に戻し、未保存の判定から外す)。
   */
  onEdit: (input: EditPaidLeaveScheduleEntryInput, callbacks: { onSuccess: () => void }) => void
  editIsPending?: boolean
  editError?: unknown
  /** 対象社員のWorkStyle設定画面へのリンク(NeedsReview判定の原因確認・修正導線、spec.md論点15-3)。 */
  workStyleSettingsHref?: string
  /** 上書き・修正フォームに未保存の入力があるかを親へ伝える。 */
  onDirtyChange?: (dirty: boolean) => void
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
 * 「判定結果を上書き」→「区分・日数を修正」の4ステップを縦に並べる(依頼書§40・spec.md論点13、
 * Sheet化はspec.md論点15-4、修正ステップはspec.md論点3)。各ステップの操作はIdle→Submitting→Success/Errorの
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
  onEdit,
  editIsPending = false,
  editError,
  workStyleSettingsHref,
  onDirtyChange,
}: PaidLeaveScheduleAssessmentPanelProps) {
  const [finalResult, setFinalResult] = useState<'Eligible' | 'NotEligible'>('Eligible')
  const [reason, setReason] = useState('')
  const [reasonTouched, setReasonTouched] = useState(false)

  // 修正フォームは「保存済みの値」(初期値、成功後は保存した値)との差分で変更を判定する。
  const [editSaved, setEditSaved] = useState<EditFormValues>(() => initialEditValues(entry))
  const [editCategory, setEditCategory] = useState<EditableCategory | ''>(editSaved.category)
  const [editDays, setEditDays] = useState(editSaved.days)
  const [editReason, setEditReason] = useState('')
  const [editReasonTouched, setEditReasonTouched] = useState(false)

  const latestAssessment = entry.assessments.length > 0 ? entry.assessments[entry.assessments.length - 1] : null
  const statusMeta = paidLeaveScheduleStatusLabel(entry.status)
  const reasonError = reasonTouched && reason.trim() === '' ? '上書き理由を入力してください。' : undefined
  const isDirtyOverride = reason !== '' || finalResult !== 'Eligible'

  const showEditStep = entry.status !== 'Granted' && entry.status !== 'Cancelled'
  const categoryChanged = editCategory !== editSaved.category
  const daysChanged = editDays !== editSaved.days
  const hasEditChanges = (categoryChanged && editCategory !== '') || daysChanged
  const daysEmpty = daysChanged && editDays.trim() === ''
  const editDaysError = daysEmpty ? '付与候補日数を入力してください。' : undefined
  const editReasonError = editReasonTouched && editReason.trim() === '' ? '修正理由を入力してください。' : undefined
  const isDirtyEdit = showEditStep && (editReason !== '' || hasEditChanges)
  const isDirty = isDirtyOverride || isDirtyEdit

  useEffect(() => {
    onDirtyChange?.(isDirty)
  }, [isDirty, onDirtyChange])

  // パネルが閉じられて外れたら、親の未保存フラグを戻す。
  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange])

  function handleOverrideSubmit() {
    setReasonTouched(true)
    if (reason.trim() === '') return
    onOverride({ final_result: finalResult, reason: reason.trim() })
  }

  function handleEditSubmit() {
    setEditReasonTouched(true)
    if (editReason.trim() === '' || !hasEditChanges || daysEmpty) return

    // 現在値から変わった項目だけを送る。区分が未選択(空)のままなら区分は送らない。
    const input: EditPaidLeaveScheduleEntryInput = { reason: editReason.trim() }
    if (categoryChanged && editCategory !== '') input.category = editCategory
    if (daysChanged) input.candidate_grant_days = Number(editDays)

    const submitted: EditFormValues = { category: editCategory, days: editDays }
    onEdit(input, {
      onSuccess: () => {
        setEditSaved(submitted)
        setEditReason('')
        setEditReasonTouched(false)
      },
    })
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
          <>
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
            {latestAssessment.deemed_attendance_days > 0 && (
              <>
                <dt className="font-medium text-muted-foreground">みなし出勤</dt>
                <dd className="text-foreground">{latestAssessment.deemed_attendance_days}日</dd>
              </>
            )}
            <dt className="font-medium text-muted-foreground">出勤率</dt>
            <dd className="text-foreground">
              {latestAssessment.attendance_rate !== null ? `${latestAssessment.attendance_rate}%` : '-'}
            </dd>
            <dt className="font-medium text-muted-foreground">自動判定</dt>
            <dd className="text-foreground">
              {latestAssessment.automatic_result ? paidLeaveScheduleStatusLabel(latestAssessment.automatic_result).label : '-'}
            </dd>
          </dl>
          {latestAssessment.deemed_attendance_days > 0 && (
            <p className="text-xs text-muted-foreground">
              前倒し付与で短縮された期間は全出勤とみなしています(社労士確認が前提)。
            </p>
          )}
          </>
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

      {showEditStep && (
        <>
          <Separator />

          <div className="flex flex-col gap-2">
            <SectionHeading step={4}>区分・日数を修正</SectionHeading>
            <p className="text-sm text-muted-foreground">
              付与ルール・法定付与日数が変更されると、未確定の付与予定はこの修正も含めて新しい内容で作り直されます。
            </p>
            {entry.status === 'NeedsReview' && (
              <p className="text-sm text-muted-foreground">区分を修正した後、再判定または判定結果の上書きを行ってください。</p>
            )}
            {entry.is_manually_overridden && entry.manual_override_reason && (
              <p className="text-sm text-muted-foreground">修正理由: {entry.manual_override_reason}</p>
            )}
            {Boolean(editError) && <ErrorMessage error={editError} fallback="区分・日数の修正に失敗しました。" />}
            <FormField label="区分" htmlFor="schedule-edit-category">
              <NativeSelect
                id="schedule-edit-category"
                value={editCategory}
                onChange={(e) => setEditCategory(e.target.value as EditableCategory | '')}
              >
                {editSaved.category === '' && <option value="">選択してください</option>}
                {EDITABLE_CATEGORIES.map((category) => (
                  <option key={category} value={category}>
                    {paidLeaveScheduleCategoryLabel(category)}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
            <FormField label="付与候補日数" htmlFor="schedule-edit-candidate-days" error={editDaysError}>
              <Input
                id="schedule-edit-candidate-days"
                type="number"
                step={0.5}
                min={0.5}
                value={editDays}
                onChange={(e) => setEditDays(e.target.value)}
              />
            </FormField>
            <FormField label="修正理由" htmlFor="schedule-edit-reason" required error={editReasonError}>
              <Textarea id="schedule-edit-reason" value={editReason} onChange={(e) => setEditReason(e.target.value)} />
            </FormField>
            <div>
              <Button
                variant="secondary"
                isLoading={editIsPending}
                disabled={!hasEditChanges || daysEmpty}
                onClick={handleEditSubmit}
              >
                区分・日数を修正する
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
