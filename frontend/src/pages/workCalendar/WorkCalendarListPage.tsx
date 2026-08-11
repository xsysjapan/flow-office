import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { useCreateWorkCalendar, useWorkCalendars } from '../../hooks/useWorkCalendars'

/**
 * UC-C009: 会社カレンダー本体の一覧・作成。カレンダー年度の作成・公開・複製は
 * 本体ごとの年度一覧ページ(WorkCalendarYearsPage)で行う。
 */
export function WorkCalendarListPage() {
  const { data, isLoading, error } = useWorkCalendars()
  const createCalendar = useCreateWorkCalendar()

  const [name, setName] = useState('')
  const [weekStartsOn, setWeekStartsOn] = useState('')
  const [fiscalYearStartMonth, setFiscalYearStartMonth] = useState('')
  const [fiscalYearStartDay, setFiscalYearStartDay] = useState('')

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />

  const calendars = data ?? []

  const handleCreate = () => {
    createCalendar.mutate(
      {
        name,
        week_starts_on: weekStartsOn === '' ? undefined : Number(weekStartsOn),
        fiscal_year_start_month: fiscalYearStartMonth === '' ? undefined : Number(fiscalYearStartMonth),
        fiscal_year_start_day: fiscalYearStartDay === '' ? undefined : Number(fiscalYearStartDay),
      },
      {
        onSuccess: () => {
          setName('')
          setWeekStartsOn('')
          setFiscalYearStartMonth('')
          setFiscalYearStartDay('')
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <Card title="会社カレンダーを作成">
        {createCalendar.error && <ErrorMessage error={createCalendar.error} />}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="カレンダー名" htmlFor="calendar-name" required>
            <Input id="calendar-name" value={name} onChange={(e) => setName(e.target.value)} />
          </FormField>

          <FormField label="週の開始日(0=日曜)" htmlFor="calendar-week-starts-on">
            <Input
              id="calendar-week-starts-on"
              type="number"
              min={0}
              max={6}
              value={weekStartsOn}
              onChange={(e) => setWeekStartsOn(e.target.value)}
            />
          </FormField>

          <FormField label="年度開始月" htmlFor="calendar-fiscal-year-start-month">
            <Input
              id="calendar-fiscal-year-start-month"
              type="number"
              min={1}
              max={12}
              value={fiscalYearStartMonth}
              onChange={(e) => setFiscalYearStartMonth(e.target.value)}
            />
          </FormField>

          <FormField label="年度開始日" htmlFor="calendar-fiscal-year-start-day">
            <Input
              id="calendar-fiscal-year-start-day"
              type="number"
              min={1}
              max={31}
              value={fiscalYearStartDay}
              onChange={(e) => setFiscalYearStartDay(e.target.value)}
            />
          </FormField>
        </div>

        <Button isLoading={createCalendar.isPending} disabled={!name} onClick={handleCreate}>
          作成する
        </Button>
      </Card>

      <Card title="会社カレンダー一覧">
        {calendars.length === 0 ? (
          <p className="text-sm text-muted-foreground">カレンダーはまだありません。</p>
        ) : (
          <ul className="divide-y divide-border">
            {calendars.map((calendar) => (
              <li key={calendar.id} className="flex items-center gap-3 py-3">
                <div className="flex flex-1 flex-col">
                  <Link
                    to={`/admin/work-calendars/${calendar.id}/years`}
                    className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                  >
                    {calendar.name}
                  </Link>
                  <span className="text-sm text-muted-foreground">
                    週開始: {calendar.week_starts_on} / 年度開始: {calendar.fiscal_year_start_month}月
                    {calendar.fiscal_year_start_day}日
                  </span>
                </div>
                <Button variant="secondary" asChild>
                  <Link to={`/admin/work-calendars/${calendar.id}/years`}>年度一覧</Link>
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
