import { useState } from 'react'
import {
  useAttendanceSubmissionReminderExclusions,
  useExcludeAttendanceSubmissionReminder,
} from '../../hooks/useAttendanceSubmissionReminderExclusions'
import { Button } from '../Button/Button'
import { EmptyState } from '../EmptyState/EmptyState'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { LoadingState } from '../LoadingState/LoadingState'
import { Textarea } from '../ui/textarea'
import { YearMonthPicker } from '../YearMonthPicker/YearMonthPicker'

export interface AttendanceSubmissionReminderExclusionPanelProps {
  userId: string
}

/**
 * 勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の対象から、特定の年月を個別に
 * 除外する管理機能。usage_start_date/hire_dateによる除外条件では
 * 対応できない誤送信ケース(例: 実際には利用開始日より前の月を誤って対象にしてしまった)
 * 向けの例外的対応。
 */
export function AttendanceSubmissionReminderExclusionPanel({ userId }: AttendanceSubmissionReminderExclusionPanelProps) {
  const { data: exclusions, isLoading, error } = useAttendanceSubmissionReminderExclusions(userId)
  const excludeReminder = useExcludeAttendanceSubmissionReminder()

  const [yearMonth, setYearMonth] = useState<string | undefined>(undefined)
  const [reason, setReason] = useState('')

  const handleExclude = () => {
    if (!yearMonth || !reason.trim()) return

    excludeReminder.mutate(
      { user_id: userId, year_month: yearMonth, reason },
      {
        onSuccess: () => {
          setYearMonth(undefined)
          setReason('')
        },
      },
    )
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="除外設定の取得に失敗しました。" />

  const list = exclusions ?? []

  return (
    <div>
      <h3 className="mb-1 text-sm font-semibold text-foreground">勤怠未提出督促の個別除外</h3>
      <p className="mb-3 text-xs text-muted-foreground">
        通常は当該月の勤怠を提出すれば督促は自動的に止まりますが、そもそもその月が提出対象では
        なかった場合(誤送信)、社員が提出することはなく督促が止まりません。この場合に限り、
        特定の年月を督促対象から個別に除外できます。
      </p>

      {excludeReminder.error && <ErrorMessage error={excludeReminder.error} />}

      <div className="mb-4 rounded-md border border-border p-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="対象年月" htmlFor="attendance-reminder-exclusion-year-month" required>
            <YearMonthPicker
              id="attendance-reminder-exclusion-year-month"
              value={yearMonth}
              onChange={setYearMonth}
            />
          </FormField>
        </div>
        <FormField label="除外理由" htmlFor="attendance-reminder-exclusion-reason" required>
          <Textarea
            id="attendance-reminder-exclusion-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="例: 利用開始日より前の月を誤って督促対象にしていたため"
          />
        </FormField>
        <Button
          isLoading={excludeReminder.isPending}
          disabled={!yearMonth || !reason.trim()}
          onClick={handleExclude}
        >
          この月の督促を対象外にする
        </Button>
      </div>

      {list.length === 0 ? (
        <EmptyState title="除外設定はまだありません。" />
      ) : (
        <ul className="divide-y divide-border text-sm">
          {list.map((exclusion) => (
            <li key={exclusion.id} className="py-2">
              <span className="font-medium text-foreground">{exclusion.year_month}</span>
              <span className="ml-2 text-muted-foreground">{exclusion.reason}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
