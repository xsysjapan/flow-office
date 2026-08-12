import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { Input } from '../../components/ui/input'
import { useCreateWorkCalendar, useDeleteWorkCalendar, useWorkCalendarsPage } from '../../hooks/useWorkCalendars'

const PER_PAGE = 20

/**
 * UC-C009: 会社カレンダー本体の一覧・作成・削除。デフォルト切替・週起算曜日/年度開始月日の
 * 設定・祝日iCalendar同期は、カレンダー名のリンク先の本体詳細画面(WorkCalendarDetailPage)に
 * まとめてある。カレンダー年度の作成・公開・複製は本体ごとの年度一覧ページ
 * (WorkCalendarYearsPage)で行う。
 */
export function WorkCalendarListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useWorkCalendarsPage(page, PER_PAGE)
  const createCalendar = useCreateWorkCalendar()
  const deleteCalendar = useDeleteWorkCalendar()

  const [name, setName] = useState('')

  const handleCreate = () => {
    createCalendar.mutate({ name }, { onSuccess: () => setName('') })
  }

  const handleDelete = (calendarId: string, calendarName: string) => {
    if (!window.confirm(`「${calendarName}」を削除します。よろしいですか?`)) return
    deleteCalendar.mutate(calendarId)
  }

  const calendars = data?.data ?? []

  return (
    <div className="flex flex-col gap-6">
      <Card title="会社カレンダーを作成">
        {createCalendar.error && <ErrorMessage error={createCalendar.error} />}

        <div className="flex flex-wrap items-end gap-4">
          <FormField label="カレンダー名" htmlFor="calendar-name" required>
            <Input id="calendar-name" value={name} onChange={(e) => setName(e.target.value)} />
          </FormField>

          <Button isLoading={createCalendar.isPending} disabled={!name} onClick={handleCreate}>
            作成する
          </Button>
        </div>
      </Card>

      <Card title="会社カレンダー一覧">
        {deleteCalendar.error && <ErrorMessage error={deleteCalendar.error} fallback="カレンダーの削除に失敗しました。" />}

        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />
        ) : calendars.length === 0 ? (
          <p className="text-sm text-muted-foreground">カレンダーはまだありません。</p>
        ) : (
          <>
            <ul className="divide-y divide-border">
              {calendars.map((calendar) => (
                <li key={calendar.id} className="flex flex-wrap items-center gap-3 py-3">
                  <div className="flex flex-1 flex-col">
                    <Link
                      to={`/admin/work-calendars/${calendar.id}`}
                      className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {calendar.name}
                    </Link>
                    <span className="text-sm text-muted-foreground">
                      週開始: {calendar.week_starts_on} / 年度開始: {calendar.fiscal_year_start_month}月
                      {calendar.fiscal_year_start_day}日
                    </span>
                  </div>
                  <Badge tone={calendar.is_default ? 'success' : 'neutral'}>
                    {calendar.is_default ? 'デフォルト' : '非デフォルト'}
                  </Badge>
                  <Button
                    variant="danger"
                    isLoading={deleteCalendar.isPending}
                    onClick={() => handleDelete(calendar.id, calendar.name)}
                  >
                    削除
                  </Button>
                </li>
              ))}
            </ul>

            {data && (
              <Pagination
                currentPage={data.meta.current_page}
                lastPage={data.meta.last_page}
                total={data.meta.total}
                onPageChange={setPage}
              />
            )}
          </>
        )}
      </Card>
    </div>
  )
}
