import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import {
  useGenerateAttendancePattern,
  usePreviewAttendancePattern,
} from '../../hooks/useAttendance'
import { browserOffsetString } from '../../utils/offsetDateTime'
import { datesInMonth } from '../../utils/weekDates'
import type { AttendanceDayOverrides, WeeklyAttendancePattern } from '../../api/attendance'

const WEEKDAYS: { iso: number; label: string }[] = [
  { iso: 1, label: '月' },
  { iso: 2, label: '火' },
  { iso: 3, label: '水' },
  { iso: 4, label: '木' },
  { iso: 5, label: '金' },
  { iso: 6, label: '土' },
  { iso: 7, label: '日' },
]

interface WeekdayRowState {
  enabled: boolean
  startTime: string
  endTime: string
  breakEnabled: boolean
  breakStartTime: string
  breakEndTime: string
}

function defaultWeeklyPatternState(): Record<number, WeekdayRowState> {
  const state: Record<number, WeekdayRowState> = {}
  for (const { iso } of WEEKDAYS) {
    state[iso] = {
      enabled: iso <= 5,
      startTime: '09:00',
      endTime: '18:00',
      breakEnabled: iso <= 5,
      breakStartTime: '12:00',
      breakEndTime: '13:00',
    }
  }
  return state
}

function buildWeeklyPattern(state: Record<number, WeekdayRowState>): WeeklyAttendancePattern {
  const pattern: WeeklyAttendancePattern = {}
  for (const { iso } of WEEKDAYS) {
    const row = state[iso]
    pattern[iso] = row.enabled
      ? {
          start_time: row.startTime,
          end_time: row.endTime,
          ...(row.breakEnabled ? { break_start_time: row.breakStartTime, break_end_time: row.breakEndTime } : {}),
        }
      : null
  }
  return pattern
}

/** 週次・月次一括入力で共通して使う「曜日ごとに入力する/しない・出退勤時刻・休憩時刻」の入力行。 */
function WeekdayPatternGrid({
  state,
  onChange,
}: {
  state: Record<number, WeekdayRowState>
  onChange: (iso: number, patch: Partial<WeekdayRowState>) => void
}) {
  return (
    <div className="grid grid-cols-1 gap-2">
      {WEEKDAYS.map(({ iso, label }) => {
        const row = state[iso]
        return (
          <div key={iso} className="flex flex-wrap items-center gap-3 rounded-md border border-border p-2">
            <label className="flex w-16 items-center gap-2 text-sm font-medium text-foreground">
              <Checkbox
                checked={row.enabled}
                onCheckedChange={(checked) => onChange(iso, { enabled: checked === true })}
              />
              {label}曜日
            </label>
            <Input
              type="time"
              aria-label={`${label}曜日の出勤時刻`}
              className="w-28"
              disabled={!row.enabled}
              value={row.startTime}
              onChange={(e) => onChange(iso, { startTime: e.target.value })}
            />
            <span className="text-sm text-muted-foreground">〜</span>
            <Input
              type="time"
              aria-label={`${label}曜日の退勤時刻`}
              className="w-28"
              disabled={!row.enabled}
              value={row.endTime}
              onChange={(e) => onChange(iso, { endTime: e.target.value })}
            />
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox
                checked={row.breakEnabled}
                disabled={!row.enabled}
                onCheckedChange={(checked) => onChange(iso, { breakEnabled: checked === true })}
              />
              休憩
            </label>
            <Input
              type="time"
              aria-label={`${label}曜日の休憩開始時刻`}
              className="w-28"
              disabled={!row.enabled || !row.breakEnabled}
              value={row.breakStartTime}
              onChange={(e) => onChange(iso, { breakStartTime: e.target.value })}
            />
            <span className="text-sm text-muted-foreground">〜</span>
            <Input
              type="time"
              aria-label={`${label}曜日の休憩終了時刻`}
              className="w-28"
              disabled={!row.enabled || !row.breakEnabled}
              value={row.breakEndTime}
              onChange={(e) => onChange(iso, { breakEndTime: e.target.value })}
            />
          </div>
        )
      })}
    </div>
  )
}

/**
 * 週次一括入力: 曜日ごとに実際の出退勤・休憩時刻を指定し、期間へ一括展開して確定する。
 * 対象は常に本人(単日の実績編集・作成と同じ範囲)。
 */
function WeeklyAttendancePatternCard() {
  const { user } = useAuth()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const [weeklyPatternState, setWeeklyPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [overwriteMode, setOverwriteMode] = useState<'skip_existing' | 'overwrite_existing'>('skip_existing')

  const previewPattern = usePreviewAttendancePattern()
  const generatePattern = useGenerateAttendancePattern()

  const handleWeekdayChange = (iso: number, patch: Partial<WeekdayRowState>) => {
    setWeeklyPatternState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))
  }

  const handlePreview = () => {
    if (!from || !to) return
    previewPattern.mutate({ from, to, utc_offset: offset, weekly_pattern: buildWeeklyPattern(weeklyPatternState) })
  }

  const handleGenerate = () => {
    if (!user || !from || !to || !reason) return
    generatePattern.mutate({
      user_id: user.id,
      from,
      to,
      utc_offset: offset,
      weekly_pattern: buildWeeklyPattern(weeklyPatternState),
      overwrite_mode: overwriteMode,
      reason,
    })
  }

  return (
    <Card title="週次の一括入力">
      {previewPattern.error && <ErrorMessage error={previewPattern.error} />}
      {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="適用開始日" htmlFor="weekly-attendance-from" required>
          <Input id="weekly-attendance-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </FormField>

        <FormField label="適用終了日" htmlFor="weekly-attendance-to" required>
          <Input id="weekly-attendance-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </FormField>

        <FormField label="タイムゾーンオフセット(週次)" htmlFor="weekly-attendance-offset" required>
          <Input
            id="weekly-attendance-offset"
            value={offset}
            placeholder="+09:00"
            pattern="^[+-]\d{2}:\d{2}$"
            onChange={(e) => setOffset(e.target.value)}
          />
        </FormField>
      </div>

      <p className="mb-2 text-sm font-semibold text-foreground">曜日ごとの出退勤・休憩時刻</p>
      <WeekdayPatternGrid state={weeklyPatternState} onChange={handleWeekdayChange} />

      <div className="my-4 flex flex-wrap gap-3">
        <Button variant="secondary" isLoading={previewPattern.isPending} disabled={!from || !to} onClick={handlePreview}>
          週次パターンをプレビューする
        </Button>
      </div>

      {previewPattern.data && (
        <ul className="mb-4 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
          {previewPattern.data.days.map((day) => (
            <li key={day.date} className="text-foreground">
              {day.date}: {day.start_time}〜{day.end_time}
              {day.has_existing_day && <span className="ml-1 text-xs text-muted-foreground">(既存実績あり)</span>}
              {day.is_locked && <span className="ml-1 text-xs text-destructive">(締め済み)</span>}
            </li>
          ))}
        </ul>
      )}

      <FormField label="確定理由" htmlFor="weekly-attendance-reason" required>
        <Input id="weekly-attendance-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>

      <FormField label="既存の実績がある日の扱い(週次)" htmlFor="weekly-attendance-overwrite-mode">
        <NativeSelect
          id="weekly-attendance-overwrite-mode"
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
        週次パターンで確定する
      </Button>

      {generatePattern.data && (
        <p className="mt-3 text-sm text-foreground">
          {generatePattern.data.created_count}件作成・{generatePattern.data.updated_count}件更新しました。
          {generatePattern.data.skipped_count > 0 && `既存実績のため${generatePattern.data.skipped_count}件をスキップしました。`}
          {generatePattern.data.rejected_count > 0 && `締め済み等のため${generatePattern.data.rejected_count}件は反映できませんでした。`}
        </p>
      )}
    </Card>
  )
}

interface DayOverrideRowState {
  enabled: boolean
  startTime: string
  endTime: string
  breakEnabled: boolean
  breakStartTime: string
  breakEndTime: string
}

/**
 * 月次一括入力: 曜日ごとの既定パターンに加えて、月内の特定日だけ個別に
 * 出退勤・休憩時刻を変更できる。
 */
function MonthlyAttendancePatternCard() {
  const { user } = useAuth()
  const [yearMonth, setYearMonth] = useState('')
  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const [weeklyPatternState, setWeeklyPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [dayOverrideState, setDayOverrideState] = useState<Record<string, DayOverrideRowState>>({})
  const [overwriteMode, setOverwriteMode] = useState<'skip_existing' | 'overwrite_existing'>('skip_existing')

  const previewPattern = usePreviewAttendancePattern()
  const generatePattern = useGenerateAttendancePattern()

  const dates = yearMonth ? datesInMonth(yearMonth) : []
  const from = dates[0]
  const to = dates[dates.length - 1]

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
      overrides[date] = {
        start_time: row.startTime,
        end_time: row.endTime,
        ...(row.breakEnabled ? { break_start_time: row.breakStartTime, break_end_time: row.breakEndTime } : {}),
      }
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
    <Card title="月次の一括入力(日単位の個別設定つき)">
      {previewPattern.error && <ErrorMessage error={previewPattern.error} />}
      {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象年月(実績一括入力)" htmlFor="monthly-attendance-year-month" required>
          <Input
            id="monthly-attendance-year-month"
            type="month"
            value={yearMonth}
            onChange={(e) => setYearMonth(e.target.value)}
          />
        </FormField>

        <FormField label="タイムゾーンオフセット(月次)" htmlFor="monthly-attendance-offset" required>
          <Input
            id="monthly-attendance-offset"
            value={offset}
            placeholder="+09:00"
            pattern="^[+-]\d{2}:\d{2}$"
            onChange={(e) => setOffset(e.target.value)}
          />
        </FormField>
      </div>

      <p className="mb-2 text-sm font-semibold text-foreground">曜日ごとの既定の出退勤・休憩時刻</p>
      <WeekdayPatternGrid state={weeklyPatternState} onChange={handleWeekdayChange} />

      {dates.length > 0 && (
        <div className="mt-5 border-t border-border pt-4">
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
      )}

      <div className="my-4 flex flex-wrap gap-3">
        <Button variant="secondary" isLoading={previewPattern.isPending} disabled={!from || !to} onClick={handlePreview}>
          月次パターンをプレビューする
        </Button>
      </div>

      {previewPattern.data && (
        <ul className="mb-4 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
          {previewPattern.data.days.map((day) => (
            <li key={day.date} className="text-foreground">
              {day.date}: {day.start_time}〜{day.end_time}
              {day.has_existing_day && <span className="ml-1 text-xs text-muted-foreground">(既存実績あり)</span>}
              {day.is_locked && <span className="ml-1 text-xs text-destructive">(締め済み)</span>}
            </li>
          ))}
        </ul>
      )}

      <FormField label="確定理由(月次)" htmlFor="monthly-attendance-reason" required>
        <Input id="monthly-attendance-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>

      <FormField label="既存の実績がある日の扱い(月次)" htmlFor="monthly-attendance-overwrite-mode">
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
        月次パターンで確定する
      </Button>

      {generatePattern.data && (
        <p className="mt-3 text-sm text-foreground">
          {generatePattern.data.created_count}件作成・{generatePattern.data.updated_count}件更新しました。
          {generatePattern.data.skipped_count > 0 && `既存実績のため${generatePattern.data.skipped_count}件をスキップしました。`}
          {generatePattern.data.rejected_count > 0 && `締め済み等のため${generatePattern.data.rejected_count}件は反映できませんでした。`}
        </p>
      )}
    </Card>
  )
}

/**
 * 実績(attendance_days)の週次・月次一括入力。曜日ごとに開始/終了時刻・休憩時刻を
 * 入力して確定すると、指定期間に自動展開される(週次)。月次はさらに日単位でも
 * 個別に設定できる。対象は常に本人(単日の実績編集・作成と同じ範囲)。
 */
export function AttendanceBulkEntryPage() {
  return (
    <div className="flex flex-col gap-6">
      <WeeklyAttendancePatternCard />
      <MonthlyAttendancePatternCard />
    </div>
  )
}
