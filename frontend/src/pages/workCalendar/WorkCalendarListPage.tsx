import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { CompanyCalendarSettingsModal } from '../../components/CompanyCalendarSettingsModal/CompanyCalendarSettingsModal'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { HolidayCalendarSourceModal } from '../../components/HolidayCalendarSourceModal/HolidayCalendarSourceModal'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { useCreateWorkCalendar, useSetDefaultWorkCalendar, useWorkCalendars } from '../../hooks/useWorkCalendars'

/**
 * UC-C009: 会社カレンダー本体の一覧・作成・デフォルト切替。作成時はカレンダー名のみを
 * 入力し、週起算曜日・年度開始月日は作成後に「設定」から編集する
 * (CompanyCalendarSettingsModal)。祝日iCalendar同期はカレンダーごとの
 * サブ画面(HolidayCalendarSourceModal)から行う。カレンダー年度の作成・公開・複製は
 * 本体ごとの年度一覧ページ(WorkCalendarYearsPage)で行う。
 */
export function WorkCalendarListPage() {
  const { data, isLoading, error } = useWorkCalendars()
  const createCalendar = useCreateWorkCalendar()
  const setDefaultCalendar = useSetDefaultWorkCalendar()

  const [name, setName] = useState('')
  const [settingsTargetId, setSettingsTargetId] = useState<string | null>(null)
  const [holidaySyncTargetId, setHolidaySyncTargetId] = useState<string | null>(null)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />

  const calendars = data ?? []
  const settingsTarget = calendars.find((c) => c.id === settingsTargetId) ?? null
  const holidaySyncTarget = calendars.find((c) => c.id === holidaySyncTargetId) ?? null

  const handleCreate = () => {
    createCalendar.mutate({ name }, { onSuccess: () => setName('') })
  }

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
        {setDefaultCalendar.error && <ErrorMessage error={setDefaultCalendar.error} />}

        {calendars.length === 0 ? (
          <p className="text-sm text-muted-foreground">カレンダーはまだありません。</p>
        ) : (
          <ul className="divide-y divide-border">
            {calendars.map((calendar) => (
              <li key={calendar.id} className="flex flex-wrap items-center gap-3 py-3">
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
                <Badge tone={calendar.is_default ? 'success' : 'neutral'}>
                  {calendar.is_default ? 'デフォルト' : '非デフォルト'}
                </Badge>
                {!calendar.is_default && (
                  <Button
                    variant="secondary"
                    isLoading={setDefaultCalendar.isPending}
                    onClick={() => setDefaultCalendar.mutate(calendar.id)}
                  >
                    デフォルトに設定する
                  </Button>
                )}
                <Button variant="secondary" onClick={() => setSettingsTargetId(calendar.id)}>
                  設定
                </Button>
                <Button variant="secondary" onClick={() => setHolidaySyncTargetId(calendar.id)}>
                  祝日iCalendar同期
                </Button>
                <Button variant="secondary" asChild>
                  <Link to={`/admin/work-calendars/${calendar.id}/years`}>年度一覧</Link>
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {settingsTarget && (
        <CompanyCalendarSettingsModal
          companyCalendar={settingsTarget}
          open
          onOpenChange={(open) => {
            if (!open) setSettingsTargetId(null)
          }}
        />
      )}

      {holidaySyncTarget && (
        <HolidayCalendarSourceModal
          companyCalendar={holidaySyncTarget}
          open
          onOpenChange={(open) => {
            if (!open) setHolidaySyncTargetId(null)
          }}
        />
      )}
    </div>
  )
}
