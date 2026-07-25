import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { useGenerateAttendancePattern, usePreviewAttendancePattern } from '../../hooks/useAttendance'
import { browserOffsetString } from '../../utils/offsetDateTime'
import { datesInMonth } from '../../utils/weekDates'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { Checkbox } from '../ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../ui/dialog'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import {
  buildWeeklyPattern,
  defaultWeeklyPatternState,
  WEEKDAYS,
  weekdayEntry,
  WeekdayScheduleFields,
  type WeekdayRowState,
} from '../WeekdayScheduleFields/WeekdayScheduleFields'
import type { AttendanceDayOverrides } from '../../api/attendance'

interface DayOverrideRowState {
  enabled: boolean
  startTime: string
  endTime: string
  breakEnabled: boolean
  breakStartTime: string
  breakEndTime: string
}

export interface MonthlyAttendanceBulkEntryModalProps {
  /** 呼び出し元(月次勤怠画面)が表示している対象年月("YYYY-MM")。 */
  yearMonth: string
  /** 一覧から個別にトリガーボタンを描画したくない場合(制御されたopen/onOpenChange)向け。省略時は自前のトリガーボタンを表示する。 */
  open?: boolean
  onOpenChange?: (open: boolean) => void
}

/**
 * 月次勤怠画面(AttendanceMonthDetailPage)から開く一括入力モーダル。曜日ごとの
 * 既定の出退勤・休憩時刻に加えて、月内の特定日だけ個別に時刻を変更できる。
 * 対象は常に本人。
 */
export function MonthlyAttendanceBulkEntryModal({
  yearMonth,
  open: controlledOpen,
  onOpenChange,
}: MonthlyAttendanceBulkEntryModalProps) {
  const { user } = useAuth()
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false)
  const isControlled = controlledOpen !== undefined
  const isOpen = isControlled ? controlledOpen : uncontrolledOpen

  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const [weeklyPatternState, setWeeklyPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [dayOverrideState, setDayOverrideState] = useState<Record<string, DayOverrideRowState>>({})
  const [overwriteMode, setOverwriteMode] = useState<'skip_existing' | 'overwrite_existing'>('skip_existing')

  const previewPattern = usePreviewAttendancePattern()
  const generatePattern = useGenerateAttendancePattern()

  const dates = datesInMonth(yearMonth)
  const from = dates[0]
  const to = dates[dates.length - 1]

  const handleOpenChange = (next: boolean) => {
    if (!isControlled) setUncontrolledOpen(next)
    onOpenChange?.(next)
    if (next) {
      setReason('')
      setDayOverrideState({})
      previewPattern.reset()
      generatePattern.reset()
    }
  }

  const handleWeekdayChange = (iso: number, patch: Partial<WeekdayRowState>) => {
    setWeeklyPatternState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))
  }

  const handleDayOverrideChange = (date: string, patch: Partial<DayOverrideRowState>) => {
    setDayOverrideState((prev) => ({
      ...prev,
      [date]: {
        enabled: prev[date]?.enabled ?? false,
        startTime: prev[date]?.startTime ?? '09:00',
        endTime: prev[date]?.endTime ?? '18:00',
        breakEnabled: prev[date]?.breakEnabled ?? true,
        breakStartTime: prev[date]?.breakStartTime ?? '12:00',
        breakEndTime: prev[date]?.breakEndTime ?? '13:00',
        ...patch,
      },
    }))
  }

  const buildDayOverrides = (): AttendanceDayOverrides => {
    const overrides: AttendanceDayOverrides = {}
    for (const [date, row] of Object.entries(dayOverrideState)) {
      if (!row.enabled) continue
      overrides[date] = weekdayEntry(row)
    }
    return overrides
  }

  const handlePreview = () => {
    if (!from || !to) return
    previewPattern.mutate({
      from,
      to,
      utc_offset: offset,
      weekly_pattern: buildWeeklyPattern(weeklyPatternState),
      day_overrides: buildDayOverrides(),
    })
  }

  const handleGenerate = () => {
    if (!user || !from || !to || !reason) return
    generatePattern.mutate({
      user_id: user.id,
      from,
      to,
      utc_offset: offset,
      weekly_pattern: buildWeeklyPattern(weeklyPatternState),
      day_overrides: buildDayOverrides(),
      overwrite_mode: overwriteMode,
      reason,
    })
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      {!isControlled && (
        <Button variant="secondary" onClick={() => handleOpenChange(true)}>
          一括入力
        </Button>
      )}
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>月次の一括入力</DialogTitle>
          <DialogDescription>{yearMonth}: 曜日ごとの既定に加えて、日単位でも個別に設定できる。</DialogDescription>
        </DialogHeader>

        {previewPattern.error && <ErrorMessage error={previewPattern.error} />}
        {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

        <FormField label="タイムゾーンオフセット" htmlFor="monthly-attendance-offset" required>
          <Input
            id="monthly-attendance-offset"
            value={offset}
            placeholder="+09:00"
            pattern="^[+-]\d{2}:\d{2}$"
            onChange={(e) => setOffset(e.target.value)}
          />
        </FormField>

        <p className="mb-2 text-sm font-semibold text-foreground">曜日ごとの既定の出退勤・休憩時刻</p>
        <WeekdayScheduleFields state={weeklyPatternState} onChange={handleWeekdayChange} />

        <div className="border-t border-border pt-4">
          <h3 className="mb-2 text-sm font-semibold text-foreground">日単位の個別設定</h3>
          <ul className="divide-y divide-border">
            {dates.map((date) => {
              const row = dayOverrideState[date]
              const weekdayLabel = WEEKDAYS[(new Date(`${date}T00:00:00`).getDay() + 6) % 7].label
              return (
                <li key={date} className="flex flex-wrap items-center gap-3 py-2 text-sm">
                  <label className="flex w-32 items-center gap-2 font-medium text-foreground">
                    <Checkbox
                      checked={row?.enabled ?? false}
                      onCheckedChange={(checked) => handleDayOverrideChange(date, { enabled: checked === true })}
                    />
                    {date}({weekdayLabel})
                  </label>
                  {row?.enabled && (
                    <>
                      <Input
                        type="time"
                        aria-label={`${date}の出勤時刻`}
                        className="w-28"
                        value={row.startTime}
                        onChange={(e) => handleDayOverrideChange(date, { startTime: e.target.value })}
                      />
                      <span className="text-muted-foreground">〜</span>
                      <Input
                        type="time"
                        aria-label={`${date}の退勤時刻`}
                        className="w-28"
                        value={row.endTime}
                        onChange={(e) => handleDayOverrideChange(date, { endTime: e.target.value })}
                      />
                      <label className="flex items-center gap-2 text-foreground">
                        <Checkbox
                          checked={row.breakEnabled}
                          aria-label={`${date}の休憩`}
                          onCheckedChange={(checked) => handleDayOverrideChange(date, { breakEnabled: checked === true })}
                        />
                        休憩
                      </label>
                      {row.breakEnabled && (
                        <>
                          <Input
                            type="time"
                            aria-label={`${date}の休憩開始時刻`}
                            className="w-28"
                            value={row.breakStartTime}
                            onChange={(e) => handleDayOverrideChange(date, { breakStartTime: e.target.value })}
                          />
                          <span className="text-muted-foreground">〜</span>
                          <Input
                            type="time"
                            aria-label={`${date}の休憩終了時刻`}
                            className="w-28"
                            value={row.breakEndTime}
                            onChange={(e) => handleDayOverrideChange(date, { breakEndTime: e.target.value })}
                          />
                        </>
                      )}
                    </>
                  )}
                </li>
              )
            })}
          </ul>
        </div>

        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" isLoading={previewPattern.isPending} disabled={!from || !to} onClick={handlePreview}>
            プレビューする
          </Button>
        </div>

        {previewPattern.data && (
          <ul className="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
            {previewPattern.data.days.map((day) => (
              <li key={day.date} className="text-foreground">
                {day.date}: {day.start_time}〜{day.end_time}
                {day.has_existing_day && <span className="ml-1 text-xs text-muted-foreground">(既存実績あり)</span>}
                {day.is_locked && <span className="ml-1 text-xs text-destructive">(締め済み)</span>}
              </li>
            ))}
          </ul>
        )}

        <FormField label="確定理由" htmlFor="monthly-attendance-reason" required>
          <Input id="monthly-attendance-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>

        <FormField label="既存の実績がある日の扱い" htmlFor="monthly-attendance-overwrite-mode">
          <NativeSelect
            id="monthly-attendance-overwrite-mode"
            value={overwriteMode}
            onChange={(e) => setOverwriteMode(e.target.value as 'skip_existing' | 'overwrite_existing')}
          >
            <option value="skip_existing">既存の実績がある日はスキップする(安全)</option>
            <option value="overwrite_existing">既存の実績がある日も上書きする</option>
          </NativeSelect>
          <p className="mt-1 text-xs text-muted-foreground">締め済み・承認済みの月に属する日はどちらを選んでも変更されない。</p>
        </FormField>

        <Button
          isLoading={generatePattern.isPending}
          disabled={!user || !from || !to || !reason}
          onClick={handleGenerate}
        >
          確定する
        </Button>

        {generatePattern.data && (
          <p className="text-sm text-foreground">
            {generatePattern.data.created_count}件作成・{generatePattern.data.updated_count}件更新しました。
            {generatePattern.data.skipped_count > 0 && `既存実績のため${generatePattern.data.skipped_count}件をスキップしました。`}
            {generatePattern.data.rejected_count > 0 && `締め済み等のため${generatePattern.data.rejected_count}件は反映できませんでした。`}
          </p>
        )}
      </DialogContent>
    </Dialog>
  )
}
