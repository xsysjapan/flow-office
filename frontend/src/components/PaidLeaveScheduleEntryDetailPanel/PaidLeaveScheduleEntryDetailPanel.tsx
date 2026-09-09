import { useState } from 'react'
import type { PaidLeaveScheduleEntry } from '../../api/types'
import { paidLeaveScheduleEntryStatusLabel } from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { NativeSelect } from '../ui/native-select'
import { Separator } from '../ui/separator'
import { Textarea } from '../ui/textarea'

// バックエンド(GrantCategory)の実際の値は「通常」「比例」「シフト」という日本語文字列
// そのもの、判定不能時のみ`NeedsReview`という英語の内部値になる。英語キーへの
// マッピングは実データと噛み合わないため行わず、`NeedsReview`だけ表示用ラベルへ変換する。
function categoryLabel(category: string | null): string {
  if (!category) return '未判定'
  if (category === 'NeedsReview') return '要確認'
  return category
}

function formatDays(days: number | null): string {
  return days !== null ? `${days.toFixed(1)}日` : '—'
}

function formatRate(rate: number | null): string {
  return rate !== null ? `${Math.round(rate * 100)}%` : '算出不可'
}

export interface PaidLeaveScheduleEntryDetailPanelProps {
  entry: PaidLeaveScheduleEntry
  onReassess: () => void
  reassessIsPending?: boolean
  reassessError?: Error | null
  onOverride: (input: { final_result: 'Eligible' | 'NotEligible'; reason: string }) => void
  overrideIsPending?: boolean
  overrideError?: Error | null
}

/**
 * 付与予定1件の詳細(spec.md画面設計のワイヤーフレーム通り)。
 * 「勤怠データを確認」(Assessment内訳の閲覧専用属性表示)→「再判定」→
 * 「自動判定/最終判定の表示」→ 既定で折りたたまれた「判定結果を上書き」の順に並べる
 * (`ui-interaction-patterns`§2.13: 状態の表示とForm Controlによる変更を分離する)。
 */
export function PaidLeaveScheduleEntryDetailPanel({
  entry,
  onReassess,
  reassessIsPending = false,
  reassessError,
  onOverride,
  overrideIsPending = false,
  overrideError,
}: PaidLeaveScheduleEntryDetailPanelProps) {
  const [isOverrideOpen, setIsOverrideOpen] = useState(false)
  const [finalResult, setFinalResult] = useState<'Eligible' | 'NotEligible'>('Eligible')
  const [reason, setReason] = useState('')

  const { label: statusLabel, tone: statusTone } = paidLeaveScheduleEntryStatusLabel(entry.status)
  const assessment = entry.assessment

  function handleConfirmOverride() {
    if (!reason.trim()) return
    onOverride({ final_result: finalResult, reason })
  }

  function closeOverrideForm() {
    setIsOverrideOpen(false)
    setReason('')
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={statusTone}>{statusLabel}</Badge>
        {entry.needs_review_due_to_conflict && <Badge tone="danger">変更あり</Badge>}
      </div>

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">区分</dt>
        <dd className="text-foreground">{categoryLabel(entry.category)}</dd>
        <dt className="font-medium text-muted-foreground">候補日数</dt>
        <dd className="text-foreground">{formatDays(entry.candidate_grant_days)}</dd>
        <dt className="font-medium text-muted-foreground">付与予定日</dt>
        <dd className="text-foreground">{entry.scheduled_on ?? '—'}</dd>
      </dl>

      <Separator />

      <div className="flex flex-col gap-2">
        <h3 className="text-sm font-semibold text-foreground">勤怠データを確認</h3>
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          <dt className="font-medium text-muted-foreground">算定期間</dt>
          <dd className="text-foreground">
            {assessment.period_start && assessment.period_end ? `${assessment.period_start} 〜 ${assessment.period_end}` : '—'}
          </dd>
          <dt className="font-medium text-muted-foreground">分母(所定労働日)</dt>
          <dd className="text-foreground">{assessment.denominator_days !== null ? `${assessment.denominator_days}日` : '—'}</dd>
          <dt className="font-medium text-muted-foreground">分子(出勤日)</dt>
          <dd className="text-foreground">{assessment.attendance_days !== null ? `${assessment.attendance_days}日` : '—'}</dd>
          <dt className="font-medium text-muted-foreground">除外日</dt>
          <dd className="text-foreground">{assessment.excluded_days !== null ? `${assessment.excluded_days}日` : '—'}</dd>
          <dt className="font-medium text-muted-foreground">出勤率</dt>
          <dd className="text-foreground">{formatRate(assessment.attendance_rate)}</dd>
          <dt className="font-medium text-muted-foreground">判定Policyバージョン</dt>
          <dd className="text-foreground">{assessment.policy_version ?? '—'}</dd>
        </dl>

        {reassessError && <ErrorMessage error={reassessError} fallback="再判定に失敗しました。" />}
        <div>
          <Button variant="secondary" size="sm" isLoading={reassessIsPending} onClick={onReassess}>
            再判定
          </Button>
        </div>
      </div>

      <Separator />

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">自動判定</dt>
        <dd className="text-foreground">
          {assessment.automatic_result ? paidLeaveScheduleEntryStatusLabel(assessment.automatic_result).label : '—'}
        </dd>
        <dt className="font-medium text-muted-foreground">最終判定</dt>
        <dd className="text-foreground">
          {assessment.final_result ? paidLeaveScheduleEntryStatusLabel(assessment.final_result).label : '—'}
          {entry.status === 'NeedsReview' && '(未確定)'}
        </dd>
        {entry.manual_override_reason && (
          <>
            <dt className="font-medium text-muted-foreground">上書き理由</dt>
            <dd className="text-foreground">{entry.manual_override_reason}</dd>
          </>
        )}
      </dl>

      {overrideError && <ErrorMessage error={overrideError} fallback="判定結果の上書きに失敗しました。" />}

      {!isOverrideOpen ? (
        <div>
          <Button variant="secondary" size="sm" onClick={() => setIsOverrideOpen(true)}>
            判定結果を上書き
          </Button>
        </div>
      ) : (
        <div className="flex flex-col gap-3 rounded-md border border-border p-3">
          <FormField label="最終判定" htmlFor="schedule-entry-override-final-result" required>
            <NativeSelect
              id="schedule-entry-override-final-result"
              value={finalResult}
              onChange={(e) => setFinalResult(e.target.value as 'Eligible' | 'NotEligible')}
            >
              <option value="Eligible">付与対象(Eligible)</option>
              <option value="NotEligible">対象外(NotEligible)</option>
            </NativeSelect>
          </FormField>
          <FormField label="理由" htmlFor="schedule-entry-override-reason" required>
            <Textarea
              id="schedule-entry-override-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="育休・休職等の特殊事情や、確認した勤怠データの内容を記録する"
            />
          </FormField>
          <div className="flex items-center gap-2">
            <Button size="sm" isLoading={overrideIsPending} disabled={!reason.trim()} onClick={handleConfirmOverride}>
              確定
            </Button>
            <Button variant="secondary" size="sm" onClick={closeOverrideForm} disabled={overrideIsPending}>
              キャンセル
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
