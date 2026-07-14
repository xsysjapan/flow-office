import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { AttendanceDayRow } from '../components/AttendanceDayRow/AttendanceDayRow'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { UserPicker } from '../components/UserPicker/UserPicker'
import { useAttendanceMonth, useSubmitMonth } from '../hooks/useAttendance'
import { dayWarnings } from '../utils/attendanceDayWarnings'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../utils/statusLabels'
import { datesInMonth, formatDate } from '../utils/weekDates'

/**
 * UC-A007: 月次勤怠を確認する。日別の内訳を一覧表示し、問題がある日は行を選んで
 * 日次画面(実績の作成・編集・打刻履歴)に遷移できる(オブジェクト指向UI)。
 */
export function AttendanceMonthDetailPage() {
  const { yearMonth } = useParams<{ yearMonth: string }>()
  const [approverUserId, setApproverUserId] = useState<number | undefined>(undefined)
  const { data, isLoading, error } = useAttendanceMonth(yearMonth ?? '')
  const submitMonth = useSubmitMonth(yearMonth ?? '')

  if (!yearMonth) return null
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="勤怠月次の取得に失敗しました。" />

  const month = data?.month
  const monthMeta = month ? attendanceMonthStatusLabel(month.status) : null
  const canSubmit = month?.status === 'not_submitted' || month?.status === 'returned'
  const daysByDate = new Map((data?.days ?? []).map((day) => [day.work_date, day]))
  const dates = datesInMonth(yearMonth)
  const today = formatDate(new Date())

  return (
    <div className="flex flex-col gap-6">
      <Card
        title={`${yearMonth}の勤怠月次`}
        actions={monthMeta ? <Badge tone={monthMeta.tone}>{monthMeta.label}</Badge> : undefined}
      >
        {month && month.legal_holiday_warnings.length > 0 && (
          <div className="mb-3 flex flex-wrap gap-2">
            {month.legal_holiday_warnings.map((warning) => (
              <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
                {legalHolidayWarningLabel(warning)}
              </Badge>
            ))}
          </div>
        )}

        {submitMonth.error && <ErrorMessage error={submitMonth.error} />}

        {canSubmit && (
          <div className="flex items-center gap-2">
            <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
            <Button
              isLoading={submitMonth.isPending}
              disabled={!approverUserId}
              onClick={() => submitMonth.mutate(approverUserId as number)}
            >
              提出する
            </Button>
          </div>
        )}
      </Card>

      <Card title="日別の内訳">
        <ul className="divide-y divide-border">
          {dates.map((date) => (
            <AttendanceDayRow
              key={date}
              date={date}
              day={daysByDate.get(date)}
              warnings={dayWarnings(date, daysByDate.get(date), today)}
            />
          ))}
        </ul>
      </Card>
    </div>
  )
}
