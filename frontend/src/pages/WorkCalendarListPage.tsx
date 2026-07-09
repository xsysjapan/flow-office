import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useCreateWorkCalendar, usePublishWorkCalendar, useWorkCalendars } from '../hooks/useWorkCalendars'
import './WorkCalendarListPage.css'

/**
 * UC-C001: 年度カレンダーの一覧・作成・公開。
 */
export function WorkCalendarListPage() {
  const { data, isLoading, error } = useWorkCalendars()
  const createCalendar = useCreateWorkCalendar()
  const publishCalendar = usePublishWorkCalendar()

  const [name, setName] = useState('')
  const [fiscalYear, setFiscalYear] = useState('')
  const [startsOn, setStartsOn] = useState('')
  const [endsOn, setEndsOn] = useState('')
  const [weekStartsOn, setWeekStartsOn] = useState('')

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />

  const calendars = data ?? []
  const actionError = createCalendar.error ?? publishCalendar.error

  const handleCreate = () => {
    createCalendar.mutate(
      {
        name,
        fiscal_year: Number(fiscalYear),
        starts_on: startsOn,
        ends_on: endsOn,
        week_starts_on: weekStartsOn === '' ? undefined : Number(weekStartsOn),
      },
      {
        onSuccess: () => {
          setName('')
          setFiscalYear('')
          setStartsOn('')
          setEndsOn('')
          setWeekStartsOn('')
        },
      },
    )
  }

  return (
    <>
      <Card title="年度カレンダーを作成">
        {actionError && <ErrorMessage error={actionError} />}

        <FormField label="カレンダー名" htmlFor="calendar-name" required>
          <input id="calendar-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>

        <FormField label="年度" htmlFor="calendar-fiscal-year" required>
          <input
            id="calendar-fiscal-year"
            type="number"
            value={fiscalYear}
            onChange={(e) => setFiscalYear(e.target.value)}
          />
        </FormField>

        <FormField label="開始日" htmlFor="calendar-starts-on" required>
          <input
            id="calendar-starts-on"
            type="date"
            value={startsOn}
            onChange={(e) => setStartsOn(e.target.value)}
          />
        </FormField>

        <FormField label="終了日" htmlFor="calendar-ends-on" required>
          <input id="calendar-ends-on" type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} />
        </FormField>

        <FormField label="週の開始日(0=日曜)" htmlFor="calendar-week-starts-on">
          <input
            id="calendar-week-starts-on"
            type="number"
            min={0}
            max={6}
            value={weekStartsOn}
            onChange={(e) => setWeekStartsOn(e.target.value)}
          />
        </FormField>

        <Button
          isLoading={createCalendar.isPending}
          disabled={!name || !fiscalYear || !startsOn || !endsOn}
          onClick={handleCreate}
        >
          作成する
        </Button>
      </Card>

      <Card title="年度カレンダー一覧">
        {calendars.length === 0 ? (
          <p>カレンダーはまだありません。</p>
        ) : (
          <ul className="work-calendar-list">
            {calendars.map((calendar) => (
              <li key={calendar.id}>
                <div className="work-calendar-list__main">
                  <Link to={`/admin/work-calendars/${calendar.id}/days`}>{calendar.name}</Link>
                  <span className="work-calendar-list__meta">
                    {calendar.fiscal_year}年度 ({calendar.starts_on}〜{calendar.ends_on})
                  </span>
                </div>
                <Badge tone={calendar.status === 'published' ? 'success' : 'neutral'}>
                  {calendar.status === 'published' ? '公開済み' : '未公開'}
                </Badge>
                {calendar.status === 'draft' && (
                  <Button
                    variant="secondary"
                    isLoading={publishCalendar.isPending}
                    onClick={() => publishCalendar.mutate(calendar.id)}
                  >
                    公開する
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
