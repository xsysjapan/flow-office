import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useForwardState, useSearchParamsKeepingBackState } from '../../navigation/backState'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { Pagination } from '../../components/Pagination/Pagination'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { FormField } from '../../components/FormField/FormField'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { filterWidth } from '../../components/ListFilterBar/filterWidth'
import { NativeSelect } from '../../components/ui/native-select'
import { useMyMonths } from '../../hooks/useAttendance'
import { employmentYearMonths } from '../../utils/employmentPeriod'
import { isValidPageParam, isValidYearParam } from '../../utils/searchParamValidators'
import { formatDate } from '../../utils/weekDates'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../../utils/statusLabels'

const PAGE_SIZE = 6

/**
 * UC-A008: 自分の月次勤怠の一覧。月を選ぶと詳細画面(日別の内訳・提出)に遷移する
 * (オブジェクト指向UI)。表示年・ページはURLに反映し、Browser Back・リロード・URL共有で
 * 状態を維持する(`ui-interaction-patterns` §2.10)。
 */
export function AttendanceMonthsPage() {
  const { user } = useAuth()
  const { data, isLoading, error } = useMyMonths()
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const allYearMonths = employmentYearMonths(user?.hire_date, user?.termination_date, currentYearMonth)
  const years = [...new Set(allYearMonths.map((yearMonth) => yearMonth.slice(0, 4)))].reverse()
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()
  const forwardState = useForwardState()
  const yearParam = searchParams.get('year')
  const pageParam = searchParams.get('page')
  // 選択肢・ページ数は入社日から決まる。ログインユーザーが取得できるまでは年・ページの判定をしない
  const isReady = user != null
  const isInvalidYear = yearParam !== null && (!isValidYearParam(yearParam) || (isReady && !years.includes(yearParam)))
  // 不正な年は未指定(キー無しの既定表示)として扱い、URLからもキーを消す(表示とURLを一致させる)
  const selectedYear = yearParam === null || isInvalidYear ? '' : yearParam
  const page = isValidPageParam(pageParam) ? Number(pageParam) : 1
  const filteredCount = allYearMonths.filter((yearMonth) => selectedYear === '' || yearMonth.startsWith(`${selectedYear}-`)).length
  const pageCount = Math.max(1, Math.ceil(filteredCount / PAGE_SIZE))
  const currentPage = Math.min(page, pageCount)
  // 不正なpage、または最終ページを超えるpageは、最終ページ(1ならキー無し)に合わせる
  const isInvalidPage = pageParam !== null && (!isValidPageParam(pageParam) || (isReady && page > pageCount))

  useEffect(() => {
    if (!isInvalidYear && !isInvalidPage) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (isInvalidYear) next.delete('year')
        if (isInvalidPage) {
          if (currentPage <= 1 || !isValidPageParam(pageParam)) next.delete('page')
          else next.set('page', String(currentPage))
        }
        return next
      },
      { replace: true },
    )
  }, [isInvalidYear, isInvalidPage, currentPage, pageParam, setSearchParams])

  if (isLoading) return <LoadingState />

  const monthsByYearMonth = new Map((data ?? []).map((month) => [month.year_month, month]))
  const months = allYearMonths
    .filter((yearMonth) => selectedYear === '' || yearMonth.startsWith(`${selectedYear}-`))
    .reverse()
    .map((yearMonth) => ({ year_month: yearMonth, month: monthsByYearMonth.get(yearMonth) }))
  const displayedMonths = months.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  const handleYearChange = (year: string) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (year) next.set('year', year)
        else next.delete('year')
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }

  const clearConditions = () => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('year')
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }

  const handlePageChange = (nextPage: number) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (nextPage <= 1) next.delete('page')
        else next.set('page', String(nextPage))
        return next
      },
      { replace: true },
    )
  }

  return (
    <Card title="月次勤怠">
      {years.length > 0 && (
        <ListFilterBar className="mb-4" isFiltered={selectedYear !== ''} onClear={clearConditions}>
          <FormField label="年" htmlFor="months-year-filter" className={filterWidth.select}>
            <NativeSelect id="months-year-filter" value={selectedYear} onChange={(event) => handleYearChange(event.target.value)}>
              <option value="">すべて</option>
              {years.map((year) => (
                <option key={year} value={year}>
                  {year}年
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </ListFilterBar>
      )}
      {error ? (
        <ErrorMessage error={error} fallback="月次勤怠の取得に失敗しました。" />
      ) : months.length === 0 ? (
        <EmptyState title="入社日を設定すると、その月以降の月次勤怠を確認できます。" />
      ) : (
        <>
          <ul className="divide-y divide-border">
            {displayedMonths.map(({ year_month, month }) => {
              const status = month ? attendanceMonthStatusLabel(month.status) : { label: '未提出', tone: 'neutral' as const }
              return (
                <li key={year_month}>
                  <Link
                    to={`/attendance/months/${year_month}`}
                    state={forwardState}
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
          <Pagination currentPage={currentPage} lastPage={pageCount} total={months.length} onPageChange={handlePageChange} />
        </>
      )}
    </Card>
  )
}
