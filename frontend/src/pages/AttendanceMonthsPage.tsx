import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useMyMonths } from '../hooks/useAttendance'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../utils/statusLabels'

/**
 * UC-A008: 自分の勤怠月次の一覧。月を選ぶと詳細画面(日別の内訳・提出)に遷移する
 * (オブジェクト指向UI)。
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
          {months.map((month) => {
            const { label, tone } = attendanceMonthStatusLabel(month.status)
            return (
              <li key={month.id}>
                <Link
                  to={`/attendance/months/${month.year_month}`}
                  className="flex flex-wrap items-center gap-2.5 rounded-md px-2 py-3 transition-colors hover:bg-accent"
                >
                  <span className="text-sm font-semibold text-foreground">{month.year_month}</span>
                  <Badge tone={tone}>{label}</Badge>
                  {month.legal_holiday_warnings.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                      {month.legal_holiday_warnings.map((warning) => (
                        <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
                          {legalHolidayWarningLabel(warning)}
                        </Badge>
                      ))}
                    </div>
                  )}
                  <span className="ml-auto text-sm text-muted-foreground">詳細を見る ›</span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
