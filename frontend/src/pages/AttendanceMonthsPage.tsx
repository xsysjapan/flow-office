import { useState } from 'react'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { UserPicker } from '../components/UserPicker/UserPicker'
import { useMyMonths, useSubmitMonth } from '../hooks/useAttendance'
import type { AttendanceMonth } from '../api/types'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../utils/statusLabels'

interface MonthRowProps {
  month: AttendanceMonth
}

/**
 * `useSubmitMonth`はyear_monthをhook引数として受け取るため、`.map()`の中で条件付きに
 * hookを呼べない。行ごとにコンポーネントを切り出し、行1つにつきhook呼び出しを1回に保つ。
 */
function MonthRow({ month }: MonthRowProps) {
  const [approverUserId, setApproverUserId] = useState<number | undefined>(undefined)
  const submitMonth = useSubmitMonth(month.year_month)
  const { label, tone } = attendanceMonthStatusLabel(month.status)
  const canSubmit = month.status === 'not_submitted' || month.status === 'returned'

  return (
    <li className="py-3">
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-semibold text-foreground">{month.year_month}</span>
        <Badge tone={tone}>{label}</Badge>
      </div>

      {month.legal_holiday_warnings.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-2">
          {month.legal_holiday_warnings.map((warning) => (
            <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
              {legalHolidayWarningLabel(warning)}
            </Badge>
          ))}
        </div>
      )}

      {submitMonth.error && <ErrorMessage error={submitMonth.error} />}

      {canSubmit && (
        <div className="mt-2 flex items-center gap-2">
          <UserPicker id={`approver-${month.id}`} value={approverUserId} onChange={setApproverUserId} />
          <Button
            isLoading={submitMonth.isPending}
            disabled={!approverUserId}
            onClick={() => submitMonth.mutate(approverUserId as number)}
          >
            提出する
          </Button>
        </div>
      )}
    </li>
  )
}

/**
 * UC-A008: 自分の勤怠月次を確認し提出する。
 */
export function AttendanceMonthsPage() {
  const { data, isLoading, error } = useMyMonths()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="勤怠月次の取得に失敗しました。" />

  const months = data ?? []

  return (
    <Card title="自分の勤怠月次">
      {months.length === 0 ? (
        <p className="text-sm text-muted-foreground">勤怠月次はまだありません。</p>
      ) : (
        <ul className="divide-y divide-border">
          {months.map((month) => (
            <MonthRow key={month.id} month={month} />
          ))}
        </ul>
      )}
    </Card>
  )
}
