import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { NativeSelect } from '../../components/ui/native-select'
import { useMyMonths } from '../../hooks/useAttendance'
import { employmentYearMonths } from '../../utils/employmentPeriod'
import { formatDate } from '../../utils/weekDates'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../../utils/statusLabels'

const PAGE_SIZE = 6

/**
 * UC-A008: 自分の月次勤怠の一覧。月を選ぶと詳細画面(日別の内訳・提出)に遷移する
 * (オブジェクト指向UI)。
 */
export function AttendanceMonthsPage() {
  const { user } = useAuth()
  const { data, isLoading, error } = useMyMonths()
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const allYearMonths = employmentYearMonths(user?.hire_date, user?.termination_date, currentYearMonth)
  const years = [...new Set(allYearMonths.map((yearMonth) => yearMonth.slice(0, 4)))].reverse()
  const [selectedYear, setSelectedYear] = useState('')
  const [page, setPage] = useState(1)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="月次勤怠の取得に失敗しました。" />

  const monthsByYearMonth = new Map((data ?? []).map((month) => [month.year_month, month]))
  const months = allYearMonths
    .filter((yearMonth) => selectedYear === '' || yearMonth.startsWith(`${selectedYear}-`))
    .reverse()
    .map((yearMonth) => ({ year_month: yearMonth, month: monthsByYearMonth.get(yearMonth) }))
  const pageCount = Math.max(1, Math.ceil(months.length / PAGE_SIZE))
  const currentPage = Math.min(page, pageCount)
  const displayedMonths = months.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  const handleYearChange = (year: string) => {
    setSelectedYear(year)
    setPage(1)
  }

  return (
    <Card
      title="自分の月次勤怠"
      actions={
        years.length > 0 ? (
          <NativeSelect aria-label="表示する年" className="w-auto" value={selectedYear} onChange={(event) => handleYearChange(event.target.value)}>
            <option value="">すべての年</option>
            {years.map((year) => (
              <option key={year} value={year}>
                {year}年
              </option>
            ))}
          </NativeSelect>
        ) : undefined
      }
    >
      {months.length === 0 ? (
        <p className="text-sm text-muted-foreground">入社日を設定すると、その月以降の月次勤怠を確認できます。</p>
      ) : (
        <>
          <ul className="divide-y divide-border">
            {displayedMonths.map(({ year_month, month }) => {
              const status = month ? attendanceMonthStatusLabel(month.status) : { label: '未提出', tone: 'neutral' as const }
              return (
                <li key={year_month}>
                  <Link
                    to={`/attendance/months/${year_month}`}
                    className="flex flex-wrap items-center gap-2.5 rounded-md px-2 py-3 transition-colors hover:bg-accent"
                  >
                    <span className="text-sm font-semibold text-foreground">{year_month}</span>
                    <Badge tone={status.tone}>{status.label}</Badge>
                    {month && month.legal_holiday_warnings.length > 0 && (
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
          {pageCount > 1 && (
            <div className="mt-4 flex items-center justify-between gap-3">
              <span className="text-sm text-muted-foreground">{months.length}件 ({currentPage}/{pageCount}ページ)</span>
              <div className="flex gap-2">
                <Button variant="secondary" size="icon" title="前のページ" aria-label="前のページ" disabled={currentPage === 1} onClick={() => setPage((previous) => previous - 1)}>
                  <ChevronLeft aria-hidden="true" />
                </Button>
                <Button variant="secondary" size="icon" title="次のページ" aria-label="次のページ" disabled={currentPage === pageCount} onClick={() => setPage((previous) => previous + 1)}>
                  <ChevronRight aria-hidden="true" />
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </Card>
  )
}
