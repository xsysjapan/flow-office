import { useEffect, useState } from 'react'
import { Button } from '../Button/Button'
import { DatePicker } from '../DatePicker/DatePicker'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { TimePicker } from '../TimePicker/TimePicker'
import { Checkbox } from '../ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../ui/dialog'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { useCreateWorkStyle, useUpdateWorkStyle } from '../../hooks/useWorkStyles'
import { useWorkCalendars } from '../../hooks/useWorkCalendars'
import type { LegalHolidayRule, RoundingMode, WorkStyle } from '../../api/types'

export const WORK_TIME_SYSTEM_OPTIONS = [
  { value: 'fixed', label: '通常勤務' },
  { value: 'monthly_variable', label: '1か月単位変形労働時間制' },
  { value: 'discretionary', label: '裁量労働制' },
  { value: 'manager_supervisor', label: '管理監督者' },
  { value: 'flex', label: 'フレックスタイム制' },
]

const ROUNDING_UNIT_OPTIONS = [5, 10, 15, 30]

const ROUNDING_MODE_LABELS: Record<RoundingMode, string> = {
  nearest: '四捨五入',
  shorten: '切り捨て(勤務時間が短くなる方向)',
  lengthen: '切り上げ(勤務時間が長くなる方向)',
}

const ROUNDING_MODE_ORDER: RoundingMode[] = ['nearest', 'shorten', 'lengthen']

/** 丸め単位・丸め方向は「5分(四捨五入)」のように1つの選択項目にまとめる。 */
export function roundingSelectValue(unitMinutes: string, mode: RoundingMode): string {
  return unitMinutes ? `${unitMinutes}:${mode}` : ''
}

export function parseRoundingSelectValue(value: string): { unitMinutes: string; mode: RoundingMode } {
  if (!value) return { unitMinutes: '', mode: 'nearest' }
  const [unitMinutes, mode] = value.split(':')
  return { unitMinutes, mode: mode as RoundingMode }
}

function emptyFormState(workStyle: WorkStyle | undefined) {
  return {
    code: workStyle?.code ?? '',
    name: workStyle?.name ?? '',
    workTimeSystem: workStyle?.work_time_system ?? '',
    prescribedDailyMinutes: workStyle ? String(workStyle.prescribed_daily_minutes) : '',
    prescribedWeeklyMinutes: workStyle ? String(workStyle.prescribed_weekly_minutes) : '',
    deemedDailyMinutes: workStyle?.deemed_daily_minutes != null ? String(workStyle.deemed_daily_minutes) : '',
    defaultStartTime: workStyle?.default_start_time ?? '',
    defaultEndTime: workStyle?.default_end_time ?? '',
    defaultBreakMinutes: workStyle?.default_break_minutes != null ? String(workStyle.default_break_minutes) : '',
    roundingUnitMinutes: workStyle?.rounding_unit_minutes != null ? String(workStyle.rounding_unit_minutes) : '',
    roundingMode: (workStyle?.rounding_mode ?? 'nearest') as RoundingMode,
    defaultBreakStartTime: workStyle?.default_break_start_time ?? '',
    defaultBreakEndTime: workStyle?.default_break_end_time ?? '',
    autoBreakEnabled: workStyle?.auto_break_enabled ?? false,
    calendarId: workStyle?.company_calendar_id ?? '',
    isShiftBased: workStyle?.is_shift_based ?? false,
    legalHolidayRule: (workStyle?.legal_holiday_rule ?? 'weekly') as LegalHolidayRule,
    fourWeekPeriodStartDate: workStyle?.four_week_period_start_date ?? '',
    maxConsecutiveWorkDays: workStyle?.max_consecutive_work_days != null ? String(workStyle.max_consecutive_work_days) : '',
    settlementStartDay: workStyle?.settlement_start_day != null ? String(workStyle.settlement_start_day) : '',
    coreTimeEnabled: workStyle?.core_time_enabled ?? false,
    coreTimeStart: workStyle?.core_time_start ?? '',
    coreTimeEnd: workStyle?.core_time_end ?? '',
    flexibleTimeStart: workStyle?.flexible_time_start ?? '',
    flexibleTimeEnd: workStyle?.flexible_time_end ?? '',
  }
}

export interface WorkStyleFormModalProps {
  /** 'create'は新規登録モーダル、'edit'は既存のworkStyleを渡して編集する。 */
  mode: 'create' | 'edit'
  workStyle?: WorkStyle
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * 勤務形態の新規登録・編集モーダル(UC-C002)。作成・編集で同じ入力項目を使うため
 * フォーム状態をこのコンポーネントに集約する。編集時も初回オンボーディングで作成された
 * 標準の勤務形態(system_generated=true)も含めて編集できる(code・is_default・
 * system_generatedはUpdateWorkStyleHandlerが変更しない)。
 */
export function WorkStyleFormModal({ mode, workStyle, open, onOpenChange }: WorkStyleFormModalProps) {
  const { data: workCalendars } = useWorkCalendars()
  const createWorkStyle = useCreateWorkStyle()
  const updateWorkStyle = useUpdateWorkStyle()
  const isEditing = mode === 'edit'
  const mutation = isEditing ? updateWorkStyle : createWorkStyle

  const [form, setForm] = useState(() => emptyFormState(workStyle))

  useEffect(() => {
    if (!open) return
    setForm(emptyFormState(workStyle))
    createWorkStyle.reset()
    updateWorkStyle.reset()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, workStyle?.id])

  const isFlex = form.workTimeSystem === 'flex'
  const isDiscretionary = form.workTimeSystem === 'discretionary'

  const handleSubmit = () => {
    const input = {
      code: form.code,
      name: form.name,
      work_time_system: form.workTimeSystem,
      prescribed_daily_minutes: Number(form.prescribedDailyMinutes),
      prescribed_weekly_minutes: Number(form.prescribedWeeklyMinutes),
      deemed_daily_minutes: isDiscretionary && form.deemedDailyMinutes ? Number(form.deemedDailyMinutes) : undefined,
      default_start_time: form.defaultStartTime || undefined,
      default_end_time: form.defaultEndTime || undefined,
      default_break_minutes: form.defaultBreakMinutes ? Number(form.defaultBreakMinutes) : undefined,
      rounding_unit_minutes: form.roundingUnitMinutes ? Number(form.roundingUnitMinutes) : undefined,
      rounding_mode: form.roundingUnitMinutes ? form.roundingMode : undefined,
      default_break_start_time: form.defaultBreakStartTime || undefined,
      default_break_end_time: form.defaultBreakEndTime || undefined,
      auto_break_enabled: form.autoBreakEnabled,
      company_calendar_id: form.calendarId,
      is_shift_based: form.isShiftBased,
      legal_holiday_rule: form.isShiftBased ? form.legalHolidayRule : undefined,
      four_week_period_start_date:
        form.isShiftBased && form.legalHolidayRule === 'four_weeks_four_days' ? form.fourWeekPeriodStartDate : undefined,
      max_consecutive_work_days:
        form.isShiftBased && form.maxConsecutiveWorkDays ? Number(form.maxConsecutiveWorkDays) : undefined,
      settlement_start_day: isFlex && form.settlementStartDay ? Number(form.settlementStartDay) : undefined,
      core_time_enabled: isFlex ? form.coreTimeEnabled : undefined,
      core_time_start: isFlex && form.coreTimeEnabled ? form.coreTimeStart : undefined,
      core_time_end: isFlex && form.coreTimeEnabled ? form.coreTimeEnd : undefined,
      flexible_time_start: isFlex ? form.flexibleTimeStart || undefined : undefined,
      flexible_time_end: isFlex ? form.flexibleTimeEnd || undefined : undefined,
    }

    if (isEditing && workStyle) {
      updateWorkStyle.mutate({ id: workStyle.id, input }, { onSuccess: () => onOpenChange(false) })
    } else {
      createWorkStyle.mutate(input, { onSuccess: () => onOpenChange(false) })
    }
  }

  const patch = (next: Partial<typeof form>) => setForm((prev) => ({ ...prev, ...next }))

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEditing ? `勤務形態を編集(${workStyle?.name})` : '勤務形態を登録'}</DialogTitle>
        </DialogHeader>

        {mutation.error && <ErrorMessage error={mutation.error} />}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="コード" htmlFor="work-style-code" required>
            <Input id="work-style-code" value={form.code} onChange={(e) => patch({ code: e.target.value })} />
          </FormField>

          <FormField label="名称" htmlFor="work-style-name" required>
            <Input id="work-style-name" value={form.name} onChange={(e) => patch({ name: e.target.value })} />
          </FormField>

          <FormField label="労働時間制" htmlFor="work-style-time-system" required>
            <NativeSelect
              id="work-style-time-system"
              value={form.workTimeSystem}
              onChange={(e) => patch({ workTimeSystem: e.target.value })}
            >
              <option value="">選択してください</option>
              {WORK_TIME_SYSTEM_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>

          <FormField label="所定労働時間(分/日)" htmlFor="work-style-daily-minutes" required>
            <Input
              id="work-style-daily-minutes"
              type="number"
              value={form.prescribedDailyMinutes}
              onChange={(e) => patch({ prescribedDailyMinutes: e.target.value })}
            />
          </FormField>

          <FormField label="所定労働時間(分/週)" htmlFor="work-style-weekly-minutes" required>
            <Input
              id="work-style-weekly-minutes"
              type="number"
              value={form.prescribedWeeklyMinutes}
              onChange={(e) => patch({ prescribedWeeklyMinutes: e.target.value })}
            />
          </FormField>

          <FormField label="標準開始時刻" htmlFor="work-style-start-time">
            <TimePicker
              id="work-style-start-time"
              value={form.defaultStartTime}
              onChange={(time) => patch({ defaultStartTime: time ?? '' })}
            />
          </FormField>

          <FormField label="標準終了時刻" htmlFor="work-style-end-time">
            <TimePicker
              id="work-style-end-time"
              value={form.defaultEndTime}
              onChange={(time) => patch({ defaultEndTime: time ?? '' })}
            />
          </FormField>

          <FormField label="標準休憩(分)" htmlFor="work-style-break-minutes">
            <Input
              id="work-style-break-minutes"
              type="number"
              value={form.defaultBreakMinutes}
              onChange={(e) => patch({ defaultBreakMinutes: e.target.value })}
            />
          </FormField>

          <FormField label="標準休憩開始時刻" htmlFor="work-style-break-start-time">
            <TimePicker
              id="work-style-break-start-time"
              value={form.defaultBreakStartTime}
              onChange={(time) => patch({ defaultBreakStartTime: time ?? '' })}
            />
            <p className="mt-1 text-xs text-muted-foreground">
              日次勤怠の入力画面で、勤務予定・打刻のいずれも無い日の初期値(システムの初期設定)に使う。
            </p>
          </FormField>

          <FormField label="標準休憩終了時刻" htmlFor="work-style-break-end-time">
            <TimePicker
              id="work-style-break-end-time"
              value={form.defaultBreakEndTime}
              onChange={(time) => patch({ defaultBreakEndTime: time ?? '' })}
            />
          </FormField>

          <FormField label="打刻の丸め単位" htmlFor="work-style-rounding-unit-minutes">
            <NativeSelect
              id="work-style-rounding-unit-minutes"
              value={roundingSelectValue(form.roundingUnitMinutes, form.roundingMode)}
              onChange={(e) => {
                const { unitMinutes, mode } = parseRoundingSelectValue(e.target.value)
                patch({ roundingUnitMinutes: unitMinutes, roundingMode: mode })
              }}
            >
              <option value="">丸めない</option>
              {ROUNDING_UNIT_OPTIONS.map((unit) =>
                ROUNDING_MODE_ORDER.map((mode) => (
                  <option key={`${unit}:${mode}`} value={roundingSelectValue(String(unit), mode)}>
                    {unit}分({ROUNDING_MODE_LABELS[mode]})
                  </option>
                )),
              )}
            </NativeSelect>
            <p className="mt-1 text-xs text-muted-foreground">
              日次勤怠の入力画面で打刻内容を初期値として反映する際、この単位・方向に丸める。
            </p>
          </FormField>

          <FormField label="カレンダー" htmlFor="work-style-calendar" required={!isEditing}>
            <NativeSelect
              id="work-style-calendar"
              value={form.calendarId}
              onChange={(e) => patch({ calendarId: e.target.value })}
            >
              <option value="">選択してください</option>
              {workCalendars?.map((calendar) => (
                <option key={calendar.id} value={calendar.id}>
                  {calendar.name}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </div>

        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
          <Checkbox
            checked={form.autoBreakEnabled}
            onCheckedChange={(checked) => patch({ autoBreakEnabled: checked === true })}
          />
          退勤時に標準休憩を自動で記録する
        </label>
        <p className="-mt-2 text-xs text-muted-foreground">
          その日に休憩が1件も打刻・記録されておらず、実働時間が6時間以上、かつ標準休憩の時間帯が実働時間内に
          収まる場合に限り、標準休憩開始・終了時刻を自動でその日の休憩として記録する。実際に打刻・編集された
          休憩がある日には影響しない。
        </p>

        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
          <Checkbox checked={form.isShiftBased} onCheckedChange={(checked) => patch({ isShiftBased: checked === true })} />
          シフト制
        </label>

        {form.isShiftBased && (
          <div className="grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
            <FormField label="法定休日の与え方" htmlFor="work-style-legal-holiday-rule">
              <NativeSelect
                id="work-style-legal-holiday-rule"
                value={form.legalHolidayRule}
                onChange={(e) => patch({ legalHolidayRule: e.target.value as LegalHolidayRule })}
              >
                <option value="weekly">毎週1日</option>
                <option value="four_weeks_four_days">4週4日以上(変形休日制)</option>
              </NativeSelect>
              <p className="mt-1 text-xs text-muted-foreground">
                月次まとめ承認時に、この要件を満たしているか警告表示される(UC-C005)。
              </p>
            </FormField>

            {form.legalHolidayRule === 'four_weeks_four_days' && (
              <FormField label="4週間の起算日" htmlFor="work-style-four-week-start" required>
                <DatePicker
                  id="work-style-four-week-start"
                  value={form.fourWeekPeriodStartDate || undefined}
                  onChange={(date) => patch({ fourWeekPeriodStartDate: date ?? '' })}
                />
                <p className="mt-1 text-xs text-muted-foreground">就業規則で定めた4週間の起算日。</p>
              </FormField>
            )}

            <FormField label="連続勤務日数の上限(任意)" htmlFor="work-style-max-consecutive-work-days">
              <Input
                id="work-style-max-consecutive-work-days"
                type="number"
                min={1}
                value={form.maxConsecutiveWorkDays}
                onChange={(e) => patch({ maxConsecutiveWorkDays: e.target.value })}
              />
              <p className="mt-1 text-xs text-muted-foreground">
                未設定ならチェックしない。3交代制シフト表の公開前確認(UC-C004)で警告に使う。
              </p>
            </FormField>
          </div>
        )}

        {isDiscretionary && (
          <div className="grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
            <FormField label="みなし労働時間(分/日)" htmlFor="work-style-deemed-daily-minutes" required>
              <Input
                id="work-style-deemed-daily-minutes"
                type="number"
                min={1}
                value={form.deemedDailyMinutes}
                onChange={(e) => patch({ deemedDailyMinutes: e.target.value })}
              />
              <p className="mt-1 text-xs text-muted-foreground">
                裁量労働制の対象日は、実労働時間にかかわらずこの時間を給与計算上の労働時間として採用する。
              </p>
            </FormField>
          </div>
        )}

        {isFlex && (
          <div className="grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
            <FormField label="清算期間の起算日(任意)" htmlFor="work-style-settlement-start-day">
              <Input
                id="work-style-settlement-start-day"
                type="number"
                min={1}
                max={31}
                value={form.settlementStartDay}
                onChange={(e) => patch({ settlementStartDay: e.target.value })}
              />
              <p className="mt-1 text-xs text-muted-foreground">未設定なら毎月1日を起算日とする。</p>
            </FormField>

            <FormField label="勤務可能開始時刻" htmlFor="work-style-flexible-start">
              <TimePicker
                id="work-style-flexible-start"
                value={form.flexibleTimeStart}
                onChange={(time) => patch({ flexibleTimeStart: time ?? '' })}
              />
            </FormField>

            <FormField label="勤務可能終了時刻" htmlFor="work-style-flexible-end">
              <TimePicker
                id="work-style-flexible-end"
                value={form.flexibleTimeEnd}
                onChange={(time) => patch({ flexibleTimeEnd: time ?? '' })}
              />
            </FormField>

            <label className="flex items-center gap-2 text-sm font-medium text-foreground sm:col-span-2">
              <Checkbox
                checked={form.coreTimeEnabled}
                onCheckedChange={(checked) => patch({ coreTimeEnabled: checked === true })}
              />
              コアタイムあり
            </label>

            {form.coreTimeEnabled && (
              <>
                <FormField label="コアタイム開始時刻" htmlFor="work-style-core-time-start" required>
                  <TimePicker
                    id="work-style-core-time-start"
                    value={form.coreTimeStart}
                    onChange={(time) => patch({ coreTimeStart: time ?? '' })}
                  />
                </FormField>

                <FormField label="コアタイム終了時刻" htmlFor="work-style-core-time-end" required>
                  <TimePicker
                    id="work-style-core-time-end"
                    value={form.coreTimeEnd}
                    onChange={(time) => patch({ coreTimeEnd: time ?? '' })}
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    労働時間は足りていてもコアタイム中に不在の場合は別枠の警告になる(指示書7.4節)。
                  </p>
                </FormField>
              </>
            )}
          </div>
        )}

        <div className="flex flex-wrap gap-3">
          <Button
            isLoading={mutation.isPending}
            disabled={
              !form.code ||
              !form.name ||
              !form.workTimeSystem ||
              !form.prescribedDailyMinutes ||
              !form.prescribedWeeklyMinutes ||
              (!isEditing && !form.calendarId) ||
              (isDiscretionary && !form.deemedDailyMinutes) ||
              (form.isShiftBased && form.legalHolidayRule === 'four_weeks_four_days' && !form.fourWeekPeriodStartDate) ||
              (isFlex && form.coreTimeEnabled && (!form.coreTimeStart || !form.coreTimeEnd))
            }
            onClick={handleSubmit}
          >
            {isEditing ? '更新する' : '登録する'}
          </Button>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            キャンセル
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
