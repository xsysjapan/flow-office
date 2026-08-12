import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import type { WorkCalendarYearStatus } from '../../api/types'
import {
  useArchiveWorkCalendarYear,
  useCreateWorkCalendarYear,
  useDuplicateWorkCalendarYear,
  usePublishWorkCalendarYear,
  useUnpublishWorkCalendarYear,
  useWorkCalendarYears,
  useWorkCalendars,
} from '../../hooks/useWorkCalendars'

function formatDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

/**
 * カレンダー本体の年度開始月日(システム設定)から、入力した年度番号(例: 2026)に対応する
 * 開始日・終了日を計算する(例: 開始月日が4/1なら2026-04-01〜2027-03-31)。
 */
export function calculateFiscalYearRange(
  fiscalYear: number,
  fiscalYearStartMonth: number,
  fiscalYearStartDay: number,
): { startsOn: string; endsOn: string } {
  const startsOn = new Date(fiscalYear, fiscalYearStartMonth - 1, fiscalYearStartDay)
  const endsOn = new Date(fiscalYear + 1, fiscalYearStartMonth - 1, fiscalYearStartDay)
  endsOn.setDate(endsOn.getDate() - 1)

  return { startsOn: formatDate(startsOn), endsOn: formatDate(endsOn) }
}

const STATUS_LABEL: Record<WorkCalendarYearStatus, string> = {
  draft: '未公開',
  published: '公開済み',
  archived: '廃止',
}

const STATUS_TONE: Record<WorkCalendarYearStatus, 'neutral' | 'success' | 'danger'> = {
  draft: 'neutral',
  published: 'success',
  archived: 'danger',
}

/**
 * UC-C009: 会社カレンダー本体配下のカレンダー年度一覧。年度の作成・公開・公開取消・廃止・
 * 複製(手順4)を行う。日別編集(WorkCalendarDaysPage)への遷移リンクも持つ。
 */
export function WorkCalendarYearsPage() {
  const { id: companyCalendarId } = useParams<{ id: string }>()
  const { data: calendars, isLoading: isLoadingCalendars } = useWorkCalendars()
  const { data: years, isLoading, error } = useWorkCalendarYears(companyCalendarId ?? '')
  const createYear = useCreateWorkCalendarYear(companyCalendarId ?? '')
  const publishYear = usePublishWorkCalendarYear(companyCalendarId ?? '')
  const unpublishYear = useUnpublishWorkCalendarYear(companyCalendarId ?? '')
  const archiveYear = useArchiveWorkCalendarYear(companyCalendarId ?? '')
  const duplicateYear = useDuplicateWorkCalendarYear(companyCalendarId ?? '')

  const [fiscalYear, setFiscalYear] = useState('')
  const [startsOn, setStartsOn] = useState('')
  const [endsOn, setEndsOn] = useState('')

  if (!companyCalendarId) return <p className="text-sm text-muted-foreground">カレンダーが見つかりません。</p>
  if (isLoading || isLoadingCalendars) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー年度一覧の取得に失敗しました。" />

  const calendar = calendars?.find((c) => c.id === companyCalendarId)
  const yearList = years ?? []
  const actionError =
    createYear.error ?? publishYear.error ?? unpublishYear.error ?? archiveYear.error ?? duplicateYear.error

  const handleFiscalYearChange = (value: string) => {
    setFiscalYear(value)

    const parsed = Number(value)
    if (!value || !Number.isInteger(parsed) || !calendar) return

    const range = calculateFiscalYearRange(parsed, calendar.fiscal_year_start_month, calendar.fiscal_year_start_day)
    setStartsOn(range.startsOn)
    setEndsOn(range.endsOn)
  }

  const handleCreate = () => {
    createYear.mutate(
      { fiscal_year: Number(fiscalYear), starts_on: startsOn, ends_on: endsOn },
      {
        onSuccess: () => {
          setFiscalYear('')
          setStartsOn('')
          setEndsOn('')
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <Card title={calendar ? `${calendar.name} の年度一覧` : 'カレンダー年度一覧'}>
        {actionError && <ErrorMessage error={actionError} />}

        <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <FormField label="年度" htmlFor="year-fiscal-year" required>
            <Input
              id="year-fiscal-year"
              type="number"
              value={fiscalYear}
              onChange={(e) => handleFiscalYearChange(e.target.value)}
            />
          </FormField>

          <FormField label="開始日" htmlFor="year-starts-on" required>
            <DatePicker id="year-starts-on" value={startsOn || undefined} onChange={(date) => setStartsOn(date ?? '')} />
          </FormField>

          <FormField label="終了日" htmlFor="year-ends-on" required>
            <DatePicker id="year-ends-on" value={endsOn || undefined} onChange={(date) => setEndsOn(date ?? '')} />
          </FormField>
        </div>

        <p className="mb-4 text-xs text-muted-foreground">
          年度を入力すると、このカレンダーの年度開始月日(設定は「設定」から変更できます)から開始日・終了日を自動計算します。開始日・終了日は個別に変更できます。
        </p>

        <Button
          isLoading={createYear.isPending}
          disabled={!fiscalYear || !startsOn || !endsOn}
          onClick={handleCreate}
        >
          年度を作成する
        </Button>
      </Card>

      <Card title="カレンダー年度">
        {yearList.length === 0 ? (
          <p className="text-sm text-muted-foreground">年度はまだありません。</p>
        ) : (
          <ul className="divide-y divide-border">
            {yearList.map((year) => (
              <li key={year.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="flex flex-1 flex-col">
                  <Link
                    to={`/admin/work-calendar-years/${year.id}/days`}
                    className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                  >
                    {year.fiscal_year}年度
                  </Link>
                  <span className="text-sm text-muted-foreground">
                    {year.starts_on}〜{year.ends_on}
                  </span>
                </div>
                <Badge tone={STATUS_TONE[year.status]}>{STATUS_LABEL[year.status]}</Badge>

                <div className="flex flex-wrap gap-2">
                  {year.status === 'draft' && (
                    <Button
                      variant="secondary"
                      isLoading={publishYear.isPending}
                      onClick={() => publishYear.mutate(year.id)}
                    >
                      公開する
                    </Button>
                  )}
                  {year.status === 'published' && (
                    <Button
                      variant="secondary"
                      isLoading={unpublishYear.isPending}
                      onClick={() => unpublishYear.mutate(year.id)}
                    >
                      公開を取消す
                    </Button>
                  )}
                  {year.status !== 'archived' && (
                    <Button
                      variant="danger"
                      isLoading={archiveYear.isPending}
                      onClick={() => archiveYear.mutate(year.id)}
                    >
                      廃止する
                    </Button>
                  )}
                  <Button
                    variant="secondary"
                    isLoading={duplicateYear.isPending}
                    onClick={() => duplicateYear.mutate(year.id)}
                  >
                    複製して翌年度を作成
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
