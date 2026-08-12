import { useEffect, useState } from 'react'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../ui/dialog'
import { Input } from '../ui/input'
import { useUpdateWorkCalendar } from '../../hooks/useWorkCalendars'
import type { WorkCalendar } from '../../api/types'

interface CompanyCalendarSettingsModalProps {
  companyCalendar: WorkCalendar
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * UC-C009: 会社カレンダー本体の名称・週起算曜日・年度開始月日を編集する。作成時は
 * 名称のみを入力するため、これらの設定はこのサブ画面(モーダル)から後で入力・変更する。
 */
export function CompanyCalendarSettingsModal({
  companyCalendar,
  open,
  onOpenChange,
}: CompanyCalendarSettingsModalProps) {
  const updateCalendar = useUpdateWorkCalendar()

  const [name, setName] = useState(companyCalendar.name)
  const [weekStartsOn, setWeekStartsOn] = useState(String(companyCalendar.week_starts_on))
  const [fiscalYearStartMonth, setFiscalYearStartMonth] = useState(String(companyCalendar.fiscal_year_start_month))
  const [fiscalYearStartDay, setFiscalYearStartDay] = useState(String(companyCalendar.fiscal_year_start_day))

  useEffect(() => {
    if (!open) return
    setName(companyCalendar.name)
    setWeekStartsOn(String(companyCalendar.week_starts_on))
    setFiscalYearStartMonth(String(companyCalendar.fiscal_year_start_month))
    setFiscalYearStartDay(String(companyCalendar.fiscal_year_start_day))
  }, [open, companyCalendar])

  const handleSave = () => {
    updateCalendar.mutate(
      {
        id: companyCalendar.id,
        input: {
          name,
          week_starts_on: Number(weekStartsOn),
          fiscal_year_start_month: Number(fiscalYearStartMonth),
          fiscal_year_start_day: Number(fiscalYearStartDay),
          holiday_calendar_source_id: companyCalendar.holiday_calendar_source_id,
        },
      },
      { onSuccess: () => onOpenChange(false) },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>会社カレンダーの設定</DialogTitle>
        </DialogHeader>

        {updateCalendar.error && <ErrorMessage error={updateCalendar.error} />}

        <div className="flex flex-col gap-4">
          <FormField label="カレンダー名" htmlFor="company-calendar-name" required>
            <Input id="company-calendar-name" value={name} onChange={(e) => setName(e.target.value)} />
          </FormField>

          <FormField label="週の開始日(0=日曜)" htmlFor="company-calendar-week-starts-on">
            <Input
              id="company-calendar-week-starts-on"
              type="number"
              min={0}
              max={6}
              value={weekStartsOn}
              onChange={(e) => setWeekStartsOn(e.target.value)}
            />
          </FormField>

          <div className="grid grid-cols-2 gap-4">
            <FormField label="年度開始月" htmlFor="company-calendar-fiscal-year-start-month">
              <Input
                id="company-calendar-fiscal-year-start-month"
                type="number"
                min={1}
                max={12}
                value={fiscalYearStartMonth}
                onChange={(e) => setFiscalYearStartMonth(e.target.value)}
              />
            </FormField>

            <FormField label="年度開始日" htmlFor="company-calendar-fiscal-year-start-day">
              <Input
                id="company-calendar-fiscal-year-start-day"
                type="number"
                min={1}
                max={31}
                value={fiscalYearStartDay}
                onChange={(e) => setFiscalYearStartDay(e.target.value)}
              />
            </FormField>
          </div>
        </div>

        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            キャンセル
          </Button>
          <Button isLoading={updateCalendar.isPending} disabled={!name} onClick={handleSave}>
            保存する
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
