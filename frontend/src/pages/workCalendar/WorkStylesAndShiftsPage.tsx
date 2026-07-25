import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { TimePicker } from '../../components/TimePicker/TimePicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import {
  useAssignEmployeeRotation,
  useEmployeeRotationAssignment,
  useGenerateRotationShiftAssignments,
} from '../../hooks/useEmployeeRotationAssignments'
import {
  useAssignShiftPatternDay,
  useGeneratePatternShiftAssignments,
  useGenerateShiftAssignments,
  usePreviewPatternShiftAssignments,
  usePublishShiftSchedule,
  useShiftAssignments,
  useShiftScheduleReview,
} from '../../hooks/useEmployeeShiftAssignments'
import { useCreateRotationPattern, usePreviewRotationPattern, useRotationPatterns } from '../../hooks/useRotationPatterns'
import { useCreateShiftPattern, useShiftPatterns } from '../../hooks/useShiftPatterns'
import {
  useAssignUserWorkStyleForMonth,
  useUserWorkStyleMonthlyAssignments,
} from '../../hooks/useUserWorkStyleMonthlyAssignments'
import { useUser } from '../../hooks/useUsers'
import { useWorkCalendars } from '../../hooks/useWorkCalendars'
import {
  useCreateDefaultWorkStyle,
  useCreateWorkStyle,
  useSetDefaultWorkStyle,
  useUpdateWorkStyle,
  useWorkStyles,
} from '../../hooks/useWorkStyles'
import type { LegalHolidayRule, RotationPreviewDay, WorkStyle } from '../../api/types'
import type { DayShiftOverrides, WeeklyShiftPattern } from '../../api/employeeShiftAssignments'

/** "YYYY-MM" から、その月の1日と末日(YYYY-MM-DD)を返す。 */
function monthBoundaries(yearMonth: string): { from: string; to: string } {
  const [year, month] = yearMonth.split('-').map(Number)
  const lastDay = new Date(year, month, 0).getDate()
  return { from: `${yearMonth}-01`, to: `${yearMonth}-${String(lastDay).padStart(2, '0')}` }
}

const STANDARD_WORK_STYLE_DEFAULTS = {
  name: '通常勤務',
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
}

/**
 * 指示書 12.1節: 初回導入時、会社のデフォルト働き方が未設定の間だけ表示するオンボーディング。
 * 「未設定」と「デフォルト適用」を混同しないため、is_defaultの働き方が1件も無いことを
 * 表示条件とする(指示書 2.2節)。
 */
function WorkStyleOnboardingCard() {
  const { data: workStyles } = useWorkStyles()
  const createDefault = useCreateDefaultWorkStyle()
  const [isEditing, setIsEditing] = useState(false)
  const [name, setName] = useState(STANDARD_WORK_STYLE_DEFAULTS.name)
  const [startTime, setStartTime] = useState(STANDARD_WORK_STYLE_DEFAULTS.default_start_time)
  const [endTime, setEndTime] = useState(STANDARD_WORK_STYLE_DEFAULTS.default_end_time)
  const [breakMinutes, setBreakMinutes] = useState(String(STANDARD_WORK_STYLE_DEFAULTS.default_break_minutes))

  const hasDefault = (workStyles ?? []).some((style) => style.is_default)
  if (hasDefault) return null

  const handleStart = () => {
    createDefault.mutate({})
  }

  const handleSaveEdited = () => {
    createDefault.mutate({
      name,
      default_start_time: startTime,
      default_end_time: endTime,
      default_break_minutes: Number(breakMinutes),
    })
  }

  const handleAddAnother = () => {
    createDefault.mutate({})
    document.getElementById('work-style-create-form')?.scrollIntoView({ behavior: 'smooth' })
  }

  return (
    <Card title="一般的な勤務設定を用意しました">
      {createDefault.error && <ErrorMessage error={createDefault.error} />}

      {!isEditing ? (
        <>
          <ul className="mb-4 text-sm text-foreground">
            <li>{STANDARD_WORK_STYLE_DEFAULTS.name}</li>
            <li>月曜日〜金曜日</li>
            <li>
              {STANDARD_WORK_STYLE_DEFAULTS.default_start_time}〜{STANDARD_WORK_STYLE_DEFAULTS.default_end_time}
            </li>
            <li>休憩12:00〜13:00</li>
            <li>土日祝休み</li>
          </ul>

          <div className="flex flex-wrap gap-3">
            <Button isLoading={createDefault.isPending} onClick={handleStart}>
              この設定で始める
            </Button>
            <Button variant="secondary" onClick={() => setIsEditing(true)}>
              内容を変更する
            </Button>
            <Button variant="secondary" isLoading={createDefault.isPending} onClick={handleAddAnother}>
              別の働き方を追加する
            </Button>
          </div>
        </>
      ) : (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="名称" htmlFor="onboarding-work-style-name" required>
              <Input id="onboarding-work-style-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>

            <FormField label="休憩(分)" htmlFor="onboarding-work-style-break-minutes" required>
              <Input
                id="onboarding-work-style-break-minutes"
                type="number"
                min={0}
                value={breakMinutes}
                onChange={(e) => setBreakMinutes(e.target.value)}
              />
            </FormField>

            <FormField label="始業時刻" htmlFor="onboarding-work-style-start-time" required>
              <TimePicker id="onboarding-work-style-start-time" value={startTime} onChange={(time) => setStartTime(time ?? '')} />
            </FormField>

            <FormField label="終業時刻" htmlFor="onboarding-work-style-end-time" required>
              <TimePicker id="onboarding-work-style-end-time" value={endTime} onChange={(time) => setEndTime(time ?? '')} />
            </FormField>
          </div>

          <div className="flex flex-wrap gap-3">
            <Button isLoading={createDefault.isPending} onClick={handleSaveEdited}>
              保存して開始する
            </Button>
            <Button variant="secondary" onClick={() => setIsEditing(false)}>
              キャンセル
            </Button>
          </div>
        </>
      )}
    </Card>
  )
}

const WORK_TIME_SYSTEM_OPTIONS = [
  { value: 'fixed', label: '通常勤務' },
  { value: 'monthly_variable', label: '1か月単位変形労働時間制' },
  { value: 'discretionary', label: '裁量労働制' },
  { value: 'manager_supervisor', label: '管理監督者' },
  { value: 'flex', label: 'フレックスタイム制' },
]

function workTimeSystemLabel(value: string): string {
  return WORK_TIME_SYSTEM_OPTIONS.find((option) => option.value === value)?.label ?? value
}

/** UC-C005: シフト制の勤務形態にのみ適用される法定休日要件の説明。 */
function legalHolidayRuleDescription(style: Pick<WorkStyle, 'legal_holiday_rule' | 'four_week_period_start_date'>): string {
  if (style.legal_holiday_rule === 'four_weeks_four_days') {
    return `法定休日: 4週4日以上(変形休日制、起算日 ${style.four_week_period_start_date ?? '未設定'})`
  }
  return '法定休日: 毎週1日'
}

function WorkStyleFormCard() {
  const { data: workStyles, isLoading, error } = useWorkStyles()
  const { data: workCalendars } = useWorkCalendars()
  const createWorkStyle = useCreateWorkStyle()
  const updateWorkStyle = useUpdateWorkStyle()
  const setDefaultWorkStyle = useSetDefaultWorkStyle()

  const [editingId, setEditingId] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [workTimeSystem, setWorkTimeSystem] = useState('')
  const [prescribedDailyMinutes, setPrescribedDailyMinutes] = useState('')
  const [prescribedWeeklyMinutes, setPrescribedWeeklyMinutes] = useState('')
  const [defaultStartTime, setDefaultStartTime] = useState('')
  const [defaultEndTime, setDefaultEndTime] = useState('')
  const [defaultBreakMinutes, setDefaultBreakMinutes] = useState('')
  const [roundingUnitMinutes, setRoundingUnitMinutes] = useState('')
  const [defaultBreakStartTime, setDefaultBreakStartTime] = useState('')
  const [defaultBreakEndTime, setDefaultBreakEndTime] = useState('')
  const [autoBreakEnabled, setAutoBreakEnabled] = useState(false)
  const [calendarId, setCalendarId] = useState('')
  const [isShiftBased, setIsShiftBased] = useState(false)
  const [legalHolidayRule, setLegalHolidayRule] = useState<LegalHolidayRule>('weekly')
  const [fourWeekPeriodStartDate, setFourWeekPeriodStartDate] = useState('')
  const [maxConsecutiveWorkDays, setMaxConsecutiveWorkDays] = useState('')
  const [settlementStartDay, setSettlementStartDay] = useState('')
  const [coreTimeEnabled, setCoreTimeEnabled] = useState(false)
  const [coreTimeStart, setCoreTimeStart] = useState('')
  const [coreTimeEnd, setCoreTimeEnd] = useState('')
  const [flexibleTimeStart, setFlexibleTimeStart] = useState('')
  const [flexibleTimeEnd, setFlexibleTimeEnd] = useState('')

  const isFlex = workTimeSystem === 'flex'
  const isEditing = editingId !== null

  const resetForm = () => {
    setEditingId(null)
    setCode('')
    setName('')
    setWorkTimeSystem('')
    setPrescribedDailyMinutes('')
    setPrescribedWeeklyMinutes('')
    setDefaultStartTime('')
    setDefaultEndTime('')
    setDefaultBreakMinutes('')
    setRoundingUnitMinutes('')
    setDefaultBreakStartTime('')
    setDefaultBreakEndTime('')
    setAutoBreakEnabled(false)
    setCalendarId('')
    setIsShiftBased(false)
    setLegalHolidayRule('weekly')
    setFourWeekPeriodStartDate('')
    setMaxConsecutiveWorkDays('')
    setSettlementStartDay('')
    setCoreTimeEnabled(false)
    setCoreTimeStart('')
    setCoreTimeEnd('')
    setFlexibleTimeStart('')
    setFlexibleTimeEnd('')
  }

  const startEditing = (style: WorkStyle) => {
    setEditingId(style.id)
    setCode(style.code)
    setName(style.name)
    setWorkTimeSystem(style.work_time_system)
    setPrescribedDailyMinutes(String(style.prescribed_daily_minutes))
    setPrescribedWeeklyMinutes(String(style.prescribed_weekly_minutes))
    setDefaultStartTime(style.default_start_time ?? '')
    setDefaultEndTime(style.default_end_time ?? '')
    setDefaultBreakMinutes(style.default_break_minutes != null ? String(style.default_break_minutes) : '')
    setRoundingUnitMinutes(style.rounding_unit_minutes != null ? String(style.rounding_unit_minutes) : '')
    setDefaultBreakStartTime(style.default_break_start_time ?? '')
    setDefaultBreakEndTime(style.default_break_end_time ?? '')
    setAutoBreakEnabled(style.auto_break_enabled)
    setCalendarId(style.calendar_id ?? '')
    setIsShiftBased(style.is_shift_based)
    setLegalHolidayRule(style.legal_holiday_rule ?? 'weekly')
    setFourWeekPeriodStartDate(style.four_week_period_start_date ?? '')
    setMaxConsecutiveWorkDays(style.max_consecutive_work_days != null ? String(style.max_consecutive_work_days) : '')
    setSettlementStartDay(style.settlement_start_day != null ? String(style.settlement_start_day) : '')
    setCoreTimeEnabled(style.core_time_enabled)
    setCoreTimeStart(style.core_time_start ?? '')
    setCoreTimeEnd(style.core_time_end ?? '')
    setFlexibleTimeStart(style.flexible_time_start ?? '')
    setFlexibleTimeEnd(style.flexible_time_end ?? '')
    document.getElementById('work-style-create-form')?.scrollIntoView({ behavior: 'smooth' })
  }

  const handleSubmitWorkStyle = () => {
    const input = {
      code,
      name,
      work_time_system: workTimeSystem,
      prescribed_daily_minutes: Number(prescribedDailyMinutes),
      prescribed_weekly_minutes: Number(prescribedWeeklyMinutes),
      default_start_time: defaultStartTime || undefined,
      default_end_time: defaultEndTime || undefined,
      default_break_minutes: defaultBreakMinutes ? Number(defaultBreakMinutes) : undefined,
      rounding_unit_minutes: roundingUnitMinutes ? Number(roundingUnitMinutes) : undefined,
      default_break_start_time: defaultBreakStartTime || undefined,
      default_break_end_time: defaultBreakEndTime || undefined,
      auto_break_enabled: autoBreakEnabled,
      calendar_id: calendarId,
      is_shift_based: isShiftBased,
      legal_holiday_rule: isShiftBased ? legalHolidayRule : undefined,
      four_week_period_start_date:
        isShiftBased && legalHolidayRule === 'four_weeks_four_days' ? fourWeekPeriodStartDate : undefined,
      max_consecutive_work_days:
        isShiftBased && maxConsecutiveWorkDays ? Number(maxConsecutiveWorkDays) : undefined,
      settlement_start_day: isFlex && settlementStartDay ? Number(settlementStartDay) : undefined,
      core_time_enabled: isFlex ? coreTimeEnabled : undefined,
      core_time_start: isFlex && coreTimeEnabled ? coreTimeStart : undefined,
      core_time_end: isFlex && coreTimeEnabled ? coreTimeEnd : undefined,
      flexible_time_start: isFlex ? flexibleTimeStart || undefined : undefined,
      flexible_time_end: isFlex ? flexibleTimeEnd || undefined : undefined,
    }

    if (editingId !== null) {
      updateWorkStyle.mutate({ id: editingId, input }, { onSuccess: resetForm })
    } else {
      createWorkStyle.mutate(input, { onSuccess: resetForm })
    }
  }

  return (
    <Card id="work-style-create-form" title="勤務形態">
      {error && <ErrorMessage error={error} fallback="勤務形態の取得に失敗しました。" />}
      {createWorkStyle.error && <ErrorMessage error={createWorkStyle.error} />}
      {updateWorkStyle.error && <ErrorMessage error={updateWorkStyle.error} />}
      {setDefaultWorkStyle.error && <ErrorMessage error={setDefaultWorkStyle.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (workStyles ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">勤務形態はまだありません。</p>
      ) : (
        <ul className="mb-4 divide-y divide-border">
          {(workStyles ?? []).map((style) => (
            <li key={style.id} className="py-2 text-sm">
              <div className="flex flex-wrap items-center gap-3">
                <strong className="font-semibold text-foreground">{style.name}</strong>
                <span className="text-muted-foreground">{style.code}</span>
                <span className="text-muted-foreground">{workTimeSystemLabel(style.work_time_system)}</span>
                <span className="text-muted-foreground">{style.prescribed_daily_minutes}分/日</span>
                <span className="text-muted-foreground">{style.is_shift_based ? 'シフト制' : '固定制'}</span>
                {style.auto_break_enabled && <Badge tone="info">休憩自動補完</Badge>}
                {style.is_shift_based && (
                  <span className="text-muted-foreground">{legalHolidayRuleDescription(style)}</span>
                )}
                {style.is_default && (
                  <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                    デフォルト
                  </span>
                )}
                {!style.is_default && (
                  <Button
                    variant="secondary"
                    isLoading={setDefaultWorkStyle.isPending}
                    onClick={() => setDefaultWorkStyle.mutate(style.id)}
                  >
                    デフォルトに設定
                  </Button>
                )}
                <Button variant="secondary" onClick={() => startEditing(style)}>
                  編集
                </Button>
              </div>

              <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <span>適用社員数 {style.applied_employee_count ?? '-'}名</span>
                {style.is_shift_based && <span>使用中の勤務シフト {style.active_shift_pattern_count ?? 0}件</span>}
                {style.updated_at && <span>最終更新 {style.updated_at.slice(0, 10)}</span>}
                {style.configuration_warnings.map((warning) => (
                  <Badge key={warning} tone="warning">
                    {warning}
                  </Badge>
                ))}
              </div>
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">
        {isEditing ? `勤務形態を編集(${name || code})` : '勤務形態を作成'}
      </h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="コード" htmlFor="work-style-code" required>
          <Input id="work-style-code" value={code} onChange={(e) => setCode(e.target.value)} />
        </FormField>

        <FormField label="名称" htmlFor="work-style-name" required>
          <Input id="work-style-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>

        <FormField label="労働時間制" htmlFor="work-style-time-system" required>
          <NativeSelect
            id="work-style-time-system"
            value={workTimeSystem}
            onChange={(e) => setWorkTimeSystem(e.target.value)}
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
            value={prescribedDailyMinutes}
            onChange={(e) => setPrescribedDailyMinutes(e.target.value)}
          />
        </FormField>

        <FormField label="所定労働時間(分/週)" htmlFor="work-style-weekly-minutes" required>
          <Input
            id="work-style-weekly-minutes"
            type="number"
            value={prescribedWeeklyMinutes}
            onChange={(e) => setPrescribedWeeklyMinutes(e.target.value)}
          />
        </FormField>

        <FormField label="標準開始時刻" htmlFor="work-style-start-time">
          <TimePicker id="work-style-start-time" value={defaultStartTime} onChange={(time) => setDefaultStartTime(time ?? '')} />
        </FormField>

        <FormField label="標準終了時刻" htmlFor="work-style-end-time">
          <TimePicker id="work-style-end-time" value={defaultEndTime} onChange={(time) => setDefaultEndTime(time ?? '')} />
        </FormField>

        <FormField label="標準休憩(分)" htmlFor="work-style-break-minutes">
          <Input
            id="work-style-break-minutes"
            type="number"
            value={defaultBreakMinutes}
            onChange={(e) => setDefaultBreakMinutes(e.target.value)}
          />
        </FormField>

        <FormField label="標準休憩開始時刻" htmlFor="work-style-break-start-time">
          <TimePicker
            id="work-style-break-start-time"
            value={defaultBreakStartTime}
            onChange={(time) => setDefaultBreakStartTime(time ?? '')}
          />
          <p className="mt-1 text-xs text-muted-foreground">
            日次勤怠の入力画面で、勤務予定・打刻のいずれも無い日の初期値(システムの初期設定)に使う。
          </p>
        </FormField>

        <FormField label="標準休憩終了時刻" htmlFor="work-style-break-end-time">
          <TimePicker
            id="work-style-break-end-time"
            value={defaultBreakEndTime}
            onChange={(time) => setDefaultBreakEndTime(time ?? '')}
          />
        </FormField>

        <FormField label="打刻の丸め単位" htmlFor="work-style-rounding-unit-minutes">
          <NativeSelect
            id="work-style-rounding-unit-minutes"
            value={roundingUnitMinutes}
            onChange={(e) => setRoundingUnitMinutes(e.target.value)}
          >
            <option value="">丸めない</option>
            <option value="5">5分</option>
            <option value="10">10分</option>
            <option value="15">15分</option>
            <option value="30">30分</option>
          </NativeSelect>
          <p className="mt-1 text-xs text-muted-foreground">
            日次勤怠の入力画面で打刻内容を初期値として反映する際、この単位に丸める(四捨五入)。
          </p>
        </FormField>

        <FormField label="カレンダー" htmlFor="work-style-calendar" required>
          <NativeSelect id="work-style-calendar" value={calendarId} onChange={(e) => setCalendarId(e.target.value)}>
            <option value="">選択してください</option>
            {workCalendars?.map((calendar) => (
              <option key={calendar.id} value={calendar.id}>
                {calendar.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      <label className="my-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={autoBreakEnabled} onCheckedChange={(checked) => setAutoBreakEnabled(checked === true)} />
        退勤時に標準休憩を自動で記録する
      </label>
      <p className="-mt-3 mb-4 text-xs text-muted-foreground">
        その日に休憩が1件も打刻・記録されておらず、実働時間が6時間以上、かつ標準休憩の時間帯が実働時間内に
        収まる場合に限り、標準休憩開始・終了時刻を自動でその日の休憩として記録する。実際に打刻・編集された
        休憩がある日には影響しない。
      </p>

      <label className="my-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isShiftBased} onCheckedChange={(checked) => setIsShiftBased(checked === true)} />
        シフト制
      </label>

      {isShiftBased && (
        <div className="mb-4 grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
          <FormField label="法定休日の与え方" htmlFor="work-style-legal-holiday-rule">
            <NativeSelect
              id="work-style-legal-holiday-rule"
              value={legalHolidayRule}
              onChange={(e) => setLegalHolidayRule(e.target.value as LegalHolidayRule)}
            >
              <option value="weekly">毎週1日</option>
              <option value="four_weeks_four_days">4週4日以上(変形休日制)</option>
            </NativeSelect>
            <p className="mt-1 text-xs text-muted-foreground">
              月次まとめ承認時に、この要件を満たしているか警告表示される(UC-C005)。
            </p>
          </FormField>

          {legalHolidayRule === 'four_weeks_four_days' && (
            <FormField label="4週間の起算日" htmlFor="work-style-four-week-start" required>
              <DatePicker
                id="work-style-four-week-start"
                value={fourWeekPeriodStartDate || undefined}
                onChange={(date) => setFourWeekPeriodStartDate(date ?? '')}
              />
              <p className="mt-1 text-xs text-muted-foreground">就業規則で定めた4週間の起算日。</p>
            </FormField>
          )}

          <FormField label="連続勤務日数の上限(任意)" htmlFor="work-style-max-consecutive-work-days">
            <Input
              id="work-style-max-consecutive-work-days"
              type="number"
              min={1}
              value={maxConsecutiveWorkDays}
              onChange={(e) => setMaxConsecutiveWorkDays(e.target.value)}
            />
            <p className="mt-1 text-xs text-muted-foreground">
              未設定ならチェックしない。3交代制シフト表の公開前確認(UC-C004)で警告に使う。
            </p>
          </FormField>
        </div>
      )}

      {isFlex && (
        <div className="mb-4 grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
          <FormField label="清算期間の起算日(任意)" htmlFor="work-style-settlement-start-day">
            <Input
              id="work-style-settlement-start-day"
              type="number"
              min={1}
              max={31}
              value={settlementStartDay}
              onChange={(e) => setSettlementStartDay(e.target.value)}
            />
            <p className="mt-1 text-xs text-muted-foreground">未設定なら毎月1日を起算日とする。</p>
          </FormField>

          <FormField label="勤務可能開始時刻" htmlFor="work-style-flexible-start">
            <TimePicker id="work-style-flexible-start" value={flexibleTimeStart} onChange={(time) => setFlexibleTimeStart(time ?? '')} />
          </FormField>

          <FormField label="勤務可能終了時刻" htmlFor="work-style-flexible-end">
            <TimePicker id="work-style-flexible-end" value={flexibleTimeEnd} onChange={(time) => setFlexibleTimeEnd(time ?? '')} />
          </FormField>

          <label className="flex items-center gap-2 text-sm font-medium text-foreground sm:col-span-2">
            <Checkbox checked={coreTimeEnabled} onCheckedChange={(checked) => setCoreTimeEnabled(checked === true)} />
            コアタイムあり
          </label>

          {coreTimeEnabled && (
            <>
              <FormField label="コアタイム開始時刻" htmlFor="work-style-core-time-start" required>
                <TimePicker id="work-style-core-time-start" value={coreTimeStart} onChange={(time) => setCoreTimeStart(time ?? '')} />
              </FormField>

              <FormField label="コアタイム終了時刻" htmlFor="work-style-core-time-end" required>
                <TimePicker id="work-style-core-time-end" value={coreTimeEnd} onChange={(time) => setCoreTimeEnd(time ?? '')} />
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
          isLoading={isEditing ? updateWorkStyle.isPending : createWorkStyle.isPending}
          disabled={
            !code ||
            !name ||
            !workTimeSystem ||
            !prescribedDailyMinutes ||
            !prescribedWeeklyMinutes ||
            (!isEditing && !calendarId) ||
            (isShiftBased && legalHolidayRule === 'four_weeks_four_days' && !fourWeekPeriodStartDate) ||
            (isFlex && coreTimeEnabled && (!coreTimeStart || !coreTimeEnd))
          }
          onClick={handleSubmitWorkStyle}
        >
          {isEditing ? '更新する' : '作成する'}
        </Button>
        {isEditing && (
          <Button variant="secondary" onClick={resetForm}>
            キャンセル
          </Button>
        )}
      </div>
    </Card>
  )
}

function ShiftGenerationCard() {
  const { data: workStyles } = useWorkStyles()
  const [shiftUserId, setShiftUserId] = useState<string | undefined>(undefined)
  const [shiftWorkStyleId, setShiftWorkStyleId] = useState('')
  const [shiftFrom, setShiftFrom] = useState('')
  const [shiftTo, setShiftTo] = useState('')

  const generateShifts = useGenerateShiftAssignments()
  const { data: shiftAssignments, isLoading: isLoadingShifts } = useShiftAssignments(
    shiftUserId ?? '',
    shiftFrom,
    shiftTo,
  )

  const handleGenerateShifts = () => {
    if (!shiftUserId || !shiftWorkStyleId) return
    generateShifts.mutate({
      user_id: shiftUserId,
      work_style_id: shiftWorkStyleId,
      from: shiftFrom,
      to: shiftTo,
    })
  }

  return (
    <Card title="シフト生成・確認">
      {generateShifts.error && <ErrorMessage error={generateShifts.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員" htmlFor="shift-target-user" required>
          <UserPicker id="shift-target-user" value={shiftUserId} onChange={setShiftUserId} />
        </FormField>

        <FormField label="勤務形態" htmlFor="shift-work-style" required>
          <NativeSelect id="shift-work-style" value={shiftWorkStyleId} onChange={(e) => setShiftWorkStyleId(e.target.value)}>
            <option value="">選択してください</option>
            {workStyles?.map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="開始日" htmlFor="shift-from" required>
          <DatePicker id="shift-from" value={shiftFrom || undefined} onChange={(date) => setShiftFrom(date ?? '')} />
        </FormField>

        <FormField label="終了日" htmlFor="shift-to" required>
          <DatePicker id="shift-to" value={shiftTo || undefined} onChange={(date) => setShiftTo(date ?? '')} />
        </FormField>
      </div>

      <Button
        isLoading={generateShifts.isPending}
        disabled={!shiftUserId || !shiftWorkStyleId || !shiftFrom || !shiftTo}
        onClick={handleGenerateShifts}
      >
        生成する
      </Button>

      {shiftUserId !== undefined && shiftFrom && shiftTo && (
        <div className="mt-5 border-t border-border pt-4">
          <h3 className="mb-2 text-sm font-semibold text-foreground">シフト一覧</h3>
          {isLoadingShifts ? (
            <LoadingState />
          ) : (shiftAssignments ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">シフトはまだありません。</p>
          ) : (
            <ul className="divide-y divide-border">
              {shiftAssignments?.map((assignment) => (
                <li key={assignment.id} className="py-2 text-sm text-foreground">
                  {assignment.work_date}({assignment.day_type}) {assignment.planned_start_at ?? '--:--'}〜
                  {assignment.planned_end_at ?? '--:--'}
                  {assignment.shift_pattern_id !== null && (
                    <span className="ml-2 text-xs text-muted-foreground">
                      {assignment.is_published ? '公開済み' : '下書き'}
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

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
  breakMinutes: string
}

function defaultWeeklyPatternState(): Record<number, WeekdayRowState> {
  const state: Record<number, WeekdayRowState> = {}
  for (const { iso } of WEEKDAYS) {
    state[iso] = { enabled: iso <= 5, startTime: '09:00', endTime: '18:00', breakMinutes: '60' }
  }
  return state
}

function buildWeeklyPattern(state: Record<number, WeekdayRowState>): WeeklyShiftPattern {
  const pattern: WeeklyShiftPattern = {}
  for (const { iso } of WEEKDAYS) {
    const row = state[iso]
    pattern[iso] = row.enabled
      ? { start_time: row.startTime, end_time: row.endTime, break_minutes: Number(row.breakMinutes) }
      : null
  }
  return pattern
}

/** 週次・月次一括入力で共通して使う「曜日ごとの勤務する/しない・時刻・休憩」の入力行。 */
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
            <div className="w-28">
              <TimePicker
                aria-label={`${label}曜日の開始時刻`}
                disabled={!row.enabled}
                value={row.startTime}
                onChange={(time) => onChange(iso, { startTime: time ?? '' })}
              />
            </div>
            <span className="text-sm text-muted-foreground">〜</span>
            <div className="w-28">
              <TimePicker
                aria-label={`${label}曜日の終了時刻`}
                disabled={!row.enabled}
                value={row.endTime}
                onChange={(time) => onChange(iso, { endTime: time ?? '' })}
              />
            </div>
            <Input
              type="number"
              min={0}
              aria-label={`${label}曜日の休憩分`}
              className="w-24"
              disabled={!row.enabled}
              value={row.breakMinutes}
              onChange={(e) => onChange(iso, { breakMinutes: e.target.value })}
            />
            <span className="text-sm text-muted-foreground">分休憩</span>
          </div>
        )
      })}
    </div>
  )
}

/**
 * 週次一括入力: 曜日ごとに開始/終了時刻・休憩を指定し、期間へ一括展開して確定する。
 */
function WeeklyPatternShiftAssignmentCard() {
  const { data: workStyles } = useWorkStyles()
  const [targetUserId, setTargetUserId] = useState<string | undefined>(undefined)
  const [workStyleId, setWorkStyleId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [weeklyPatternState, setWeeklyPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [overwriteMode, setOverwriteMode] = useState<'skip_edited' | 'overwrite_all'>('skip_edited')

  const previewPattern = usePreviewPatternShiftAssignments()
  const generatePattern = useGeneratePatternShiftAssignments()

  const handleWeekdayChange = (iso: number, patch: Partial<WeekdayRowState>) => {
    setWeeklyPatternState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))
  }

  const handlePreview = () => {
    if (!from || !to) return
    previewPattern.mutate({ from, to, weekly_pattern: buildWeeklyPattern(weeklyPatternState) })
  }

  const handleGenerate = () => {
    if (!targetUserId || !workStyleId || !from || !to) return
    generatePattern.mutate({
      user_id: targetUserId,
      work_style_id: workStyleId,
      from,
      to,
      weekly_pattern: buildWeeklyPattern(weeklyPatternState),
      overwrite_mode: overwriteMode,
    })
  }

  return (
    <Card title="週次の一括入力">
      {previewPattern.error && <ErrorMessage error={previewPattern.error} />}
      {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員(週次)" htmlFor="weekly-pattern-user" required>
          <UserPicker id="weekly-pattern-user" value={targetUserId} onChange={setTargetUserId} />
        </FormField>

        <FormField label="勤務形態(週次)" htmlFor="weekly-pattern-work-style" required>
          <NativeSelect id="weekly-pattern-work-style" value={workStyleId} onChange={(e) => setWorkStyleId(e.target.value)}>
            <option value="">選択してください</option>
            {workStyles?.map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="適用開始日" htmlFor="weekly-pattern-from" required>
          <DatePicker id="weekly-pattern-from" value={from || undefined} onChange={(date) => setFrom(date ?? '')} />
        </FormField>

        <FormField label="適用終了日" htmlFor="weekly-pattern-to" required>
          <DatePicker id="weekly-pattern-to" value={to || undefined} onChange={(date) => setTo(date ?? '')} />
        </FormField>
      </div>

      <p className="mb-2 text-sm font-semibold text-foreground">曜日ごとの勤務時間</p>
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
              {day.date}: {day.is_working_day ? `${day.start_time}〜${day.end_time}` : '休み'}
            </li>
          ))}
        </ul>
      )}

      <FormField label="再生成時の扱い(週次)" htmlFor="weekly-pattern-overwrite-mode">
        <NativeSelect
          id="weekly-pattern-overwrite-mode"
          value={overwriteMode}
          onChange={(e) => setOverwriteMode(e.target.value as 'skip_edited' | 'overwrite_all')}
        >
          <option value="skip_edited">未編集日のみ再生成する(安全)</option>
          <option value="overwrite_all">個別上書きも含めてすべて再生成する</option>
        </NativeSelect>
        <p className="mt-1 text-xs text-muted-foreground">実績のある日・締め済みの日はどちらを選んでも上書きされない。</p>
      </FormField>

      <Button
        isLoading={generatePattern.isPending}
        disabled={!targetUserId || !workStyleId || !from || !to}
        onClick={handleGenerate}
      >
        週次パターンで確定する
      </Button>

      {generatePattern.data && (
        <p className="mt-3 text-sm text-foreground">
          {generatePattern.data.generated_count}件生成しました。
          {generatePattern.data.skipped_dates.length > 0 &&
            `(実績・締め済み・個別上書きのため${generatePattern.data.skipped_dates.length}件をスキップしました)`}
        </p>
      )}
    </Card>
  )
}

interface DayOverrideRowState {
  enabled: boolean
  dayOff: boolean
  startTime: string
  endTime: string
  breakMinutes: string
}

/** "YYYY-MM"から、その月の全日付("YYYY-MM-DD")を返す。 */
function daysInMonth(yearMonth: string): string[] {
  if (!yearMonth) return []
  const { from, to } = monthBoundaries(yearMonth)
  const dates: string[] = []
  const cursor = new Date(`${from}T00:00:00`)
  const end = new Date(`${to}T00:00:00`)
  while (cursor <= end) {
    dates.push(cursor.toISOString().slice(0, 10))
    cursor.setDate(cursor.getDate() + 1)
  }
  return dates
}

/**
 * 月次一括入力: 曜日ごとの既定パターンに加えて、月内の特定日だけ個別に
 * 時刻を変更したり休みにしたりできる。
 */
function MonthlyPatternShiftAssignmentCard() {
  const { data: workStyles } = useWorkStyles()
  const [targetUserId, setTargetUserId] = useState<string | undefined>(undefined)
  const [workStyleId, setWorkStyleId] = useState('')
  const [yearMonth, setYearMonth] = useState('')
  const [weeklyPatternState, setWeeklyPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [dayOverrideState, setDayOverrideState] = useState<Record<string, DayOverrideRowState>>({})
  const [overwriteMode, setOverwriteMode] = useState<'skip_edited' | 'overwrite_all'>('skip_edited')

  const previewPattern = usePreviewPatternShiftAssignments()
  const generatePattern = useGeneratePatternShiftAssignments()

  const { from, to } = yearMonth ? monthBoundaries(yearMonth) : { from: '', to: '' }
  const dates = daysInMonth(yearMonth)

  const handleWeekdayChange = (iso: number, patch: Partial<WeekdayRowState>) => {
    setWeeklyPatternState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))
  }

  const handleDayOverrideChange = (date: string, patch: Partial<DayOverrideRowState>) => {
    setDayOverrideState((prev) => ({
      ...prev,
      [date]: {
        enabled: prev[date]?.enabled ?? false,
        dayOff: prev[date]?.dayOff ?? false,
        startTime: prev[date]?.startTime ?? '09:00',
        endTime: prev[date]?.endTime ?? '18:00',
        breakMinutes: prev[date]?.breakMinutes ?? '60',
        ...patch,
      },
    }))
  }

  const buildDayOverrides = (): DayShiftOverrides => {
    const overrides: DayShiftOverrides = {}
    for (const [date, row] of Object.entries(dayOverrideState)) {
      if (!row.enabled) continue
      overrides[date] = row.dayOff
        ? null
        : { start_time: row.startTime, end_time: row.endTime, break_minutes: Number(row.breakMinutes) }
    }
    return overrides
  }

  const handlePreview = () => {
    if (!from || !to) return
    previewPattern.mutate({
      from,
      to,
      weekly_pattern: buildWeeklyPattern(weeklyPatternState),
      day_overrides: buildDayOverrides(),
    })
  }

  const handleGenerate = () => {
    if (!targetUserId || !workStyleId || !from || !to) return
    generatePattern.mutate({
      user_id: targetUserId,
      work_style_id: workStyleId,
      from,
      to,
      weekly_pattern: buildWeeklyPattern(weeklyPatternState),
      day_overrides: buildDayOverrides(),
      overwrite_mode: overwriteMode,
    })
  }

  return (
    <Card title="月次の一括入力(日単位の個別設定つき)">
      {previewPattern.error && <ErrorMessage error={previewPattern.error} />}
      {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員(月次)" htmlFor="monthly-pattern-user" required>
          <UserPicker id="monthly-pattern-user" value={targetUserId} onChange={setTargetUserId} />
        </FormField>

        <FormField label="勤務形態(月次)" htmlFor="monthly-pattern-work-style" required>
          <NativeSelect
            id="monthly-pattern-work-style"
            value={workStyleId}
            onChange={(e) => setWorkStyleId(e.target.value)}
          >
            <option value="">選択してください</option>
            {workStyles?.map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="対象年月(月次パターン)" htmlFor="monthly-pattern-year-month" required>
          <Input
            id="monthly-pattern-year-month"
            type="month"
            value={yearMonth}
            onChange={(e) => setYearMonth(e.target.value)}
          />
        </FormField>
      </div>

      <p className="mb-2 text-sm font-semibold text-foreground">曜日ごとの既定の勤務時間</p>
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
                      <label className="flex items-center gap-2 text-foreground">
                        <Checkbox
                          checked={row.dayOff}
                          onCheckedChange={(checked) => handleDayOverrideChange(date, { dayOff: checked === true })}
                        />
                        休みにする
                      </label>
                      {!row.dayOff && (
                        <>
                          <div className="w-28">
                            <TimePicker
                              aria-label={`${date}の開始時刻`}
                              value={row.startTime}
                              onChange={(time) => handleDayOverrideChange(date, { startTime: time ?? '' })}
                            />
                          </div>
                          <span className="text-muted-foreground">〜</span>
                          <div className="w-28">
                            <TimePicker
                              aria-label={`${date}の終了時刻`}
                              value={row.endTime}
                              onChange={(time) => handleDayOverrideChange(date, { endTime: time ?? '' })}
                            />
                          </div>
                          <Input
                            type="number"
                            min={0}
                            aria-label={`${date}の休憩分`}
                            className="w-24"
                            value={row.breakMinutes}
                            onChange={(e) => handleDayOverrideChange(date, { breakMinutes: e.target.value })}
                          />
                          <span className="text-muted-foreground">分休憩</span>
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
              {day.date}: {day.is_working_day ? `${day.start_time}〜${day.end_time}` : '休み'}
              {day.source === 'day_override' && <span className="ml-1 text-xs text-muted-foreground">(個別設定)</span>}
            </li>
          ))}
        </ul>
      )}

      <FormField label="再生成時の扱い(月次)" htmlFor="monthly-pattern-overwrite-mode">
        <NativeSelect
          id="monthly-pattern-overwrite-mode"
          value={overwriteMode}
          onChange={(e) => setOverwriteMode(e.target.value as 'skip_edited' | 'overwrite_all')}
        >
          <option value="skip_edited">未編集日のみ再生成する(安全)</option>
          <option value="overwrite_all">個別上書きも含めてすべて再生成する</option>
        </NativeSelect>
        <p className="mt-1 text-xs text-muted-foreground">実績のある日・締め済みの日はどちらを選んでも上書きされない。</p>
      </FormField>

      <Button
        isLoading={generatePattern.isPending}
        disabled={!targetUserId || !workStyleId || !from || !to}
        onClick={handleGenerate}
      >
        月次パターンで確定する
      </Button>

      {generatePattern.data && (
        <p className="mt-3 text-sm text-foreground">
          {generatePattern.data.generated_count}件生成しました。
          {generatePattern.data.skipped_dates.length > 0 &&
            `(実績・締め済み・個別上書きのため${generatePattern.data.skipped_dates.length}件をスキップしました)`}
        </p>
      )}
    </Card>
  )
}

/**
 * 10月までは通常勤務、11月からシフト勤務のように、ユーザーの月次働き方を切り替える。
 * 過去月の割当は変更されず履歴として残る(docs/16-database-schema.md
 * user_work_style_monthly_assignments)。
 */
function MonthlyWorkStyleAssignmentCard() {
  const { data: workStyles } = useWorkStyles()
  const [targetUserId, setTargetUserId] = useState<string | undefined>(undefined)
  const [yearMonth, setYearMonth] = useState('')
  const [workStyleId, setWorkStyleId] = useState('')
  const [isConfirming, setIsConfirming] = useState(false)
  const [autoGenerateShifts, setAutoGenerateShifts] = useState(false)

  const { data: targetUser } = useUser(targetUserId ?? '')
  const { data: history, isLoading: isLoadingHistory } = useUserWorkStyleMonthlyAssignments(targetUserId)
  const assignForMonth = useAssignUserWorkStyleForMonth()
  const generateShifts = useGenerateShiftAssignments()

  const currentAssignment = history?.find((assignment) => assignment.year_month === yearMonth)
  const selectedWorkStyle = workStyles?.find((style) => style.id === workStyleId)

  const handleUserChange = (userId: string | undefined) => {
    setTargetUserId(userId)
    setIsConfirming(false)
  }

  const handleYearMonthChange = (value: string) => {
    setYearMonth(value)
    setIsConfirming(false)
  }

  const handleWorkStyleChange = (value: string) => {
    setWorkStyleId(value)
    setIsConfirming(false)
  }

  const handleSave = () => {
    if (!targetUserId || !yearMonth || !workStyleId) return
    assignForMonth.mutate(
      { user_id: targetUserId, year_month: yearMonth, work_style_id: workStyleId },
      {
        onSuccess: () => {
          if (autoGenerateShifts) {
            const { from, to } = monthBoundaries(yearMonth)
            generateShifts.mutate({ user_id: targetUserId, work_style_id: workStyleId, from, to })
          }
          setYearMonth('')
          setIsConfirming(false)
          setAutoGenerateShifts(false)
        },
      },
    )
  }

  return (
    <Card title="ユーザーの月次働き方">
      <p className="mb-4 text-sm text-muted-foreground">
        働き方が設定されていない月は、システムのデフォルト働き方にフォールバックする。
      </p>
      {assignForMonth.error && <ErrorMessage error={assignForMonth.error} />}
      {generateShifts.error && <ErrorMessage error={generateShifts.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <FormField label="働き方の対象社員" htmlFor="monthly-work-style-user" required>
          <UserPicker id="monthly-work-style-user" value={targetUserId} onChange={handleUserChange} />
        </FormField>

        <FormField label="対象年月" htmlFor="monthly-work-style-year-month" required>
          <Input
            id="monthly-work-style-year-month"
            type="month"
            value={yearMonth}
            onChange={(e) => handleYearMonthChange(e.target.value)}
          />
        </FormField>

        <FormField label="働き方" htmlFor="monthly-work-style-select" required>
          <NativeSelect
            id="monthly-work-style-select"
            value={workStyleId}
            onChange={(e) => handleWorkStyleChange(e.target.value)}
          >
            <option value="">選択してください</option>
            {workStyles?.map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      {!isConfirming ? (
        <Button disabled={!targetUserId || !yearMonth || !workStyleId} onClick={() => setIsConfirming(true)}>
          変更内容を確認する
        </Button>
      ) : (
        <div className="mb-4 rounded-md border border-border p-4 text-sm">
          <p className="mb-3 font-semibold text-foreground">変更内容の確認</p>

          <dl className="mb-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
            <dt className="text-muted-foreground">対象社員</dt>
            <dd className="text-foreground">{targetUser?.name ?? `社員ID ${targetUserId}`}</dd>
            <dt className="text-muted-foreground">対象年月</dt>
            <dd className="text-foreground">{yearMonth}</dd>
            <dt className="text-muted-foreground">現在の働き方</dt>
            <dd className="text-foreground">
              {currentAssignment?.work_style?.name ?? '未設定(会社のデフォルトにフォールバック)'}
            </dd>
            <dt className="text-muted-foreground">変更後の働き方</dt>
            <dd className="text-foreground">{selectedWorkStyle?.name ?? '-'}</dd>
          </dl>

          <ul className="mb-3 list-disc pl-5 text-xs text-muted-foreground">
            <li>{yearMonth}より前の月の割当・勤怠には影響しません。</li>
            <li>
              {yearMonth}より後の月は自動的には引き継がれません。別途その月の働き方を割り当てる必要があります。
            </li>
            <li>
              {yearMonth}内で既に打刻・日次編集済みの日の集計(残業・深夜等)は自動的には再計算されません。
              反映するには対象日を日次編集から保存し直してください。
            </li>
          </ul>

          <label className="mb-3 flex items-center gap-2 text-foreground">
            <Checkbox
              checked={autoGenerateShifts}
              onCheckedChange={(checked) => setAutoGenerateShifts(checked === true)}
            />
            この働き方をもとに{yearMonth}の勤務予定を自動生成する(既存の勤務予定は上書きされます)
          </label>

          <div className="flex flex-wrap gap-3">
            <Button isLoading={assignForMonth.isPending || generateShifts.isPending} onClick={handleSave}>
              この内容で保存する
            </Button>
            <Button variant="secondary" onClick={() => setIsConfirming(false)}>
              キャンセル
            </Button>
          </div>
        </div>
      )}

      {targetUserId !== undefined && (
        <div className="mt-5 border-t border-border pt-4">
          <h3 className="mb-2 text-sm font-semibold text-foreground">割当履歴</h3>
          {isLoadingHistory ? (
            <LoadingState />
          ) : (history ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">まだ割り当てられていません。</p>
          ) : (
            <ul className="divide-y divide-border">
              {history?.map((assignment) => (
                <li key={assignment.id} className="py-2 text-sm text-foreground">
                  {`${assignment.year_month}: ${assignment.work_style?.name ?? assignment.work_style_id}`}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

function ShiftPatternFormCard() {
  const { data: patterns, isLoading, error } = useShiftPatterns()
  const createShiftPattern = useCreateShiftPattern()

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [startTime, setStartTime] = useState('')
  const [endTime, setEndTime] = useState('')
  const [crossesMidnight, setCrossesMidnight] = useState(false)
  const [breakMinutes, setBreakMinutes] = useState('')
  const [breakStartTime, setBreakStartTime] = useState('')
  const [breakEndTime, setBreakEndTime] = useState('')
  const [prescribedWorkMinutes, setPrescribedWorkMinutes] = useState('')

  const handleCreate = () => {
    createShiftPattern.mutate(
      {
        code,
        name,
        start_time: startTime || undefined,
        end_time: endTime || undefined,
        crosses_midnight: crossesMidnight,
        break_minutes: breakMinutes ? Number(breakMinutes) : undefined,
        break_start_time: breakStartTime || undefined,
        break_end_time: breakEndTime || undefined,
        prescribed_work_minutes: prescribedWorkMinutes ? Number(prescribedWorkMinutes) : undefined,
      },
      {
        onSuccess: () => {
          setCode('')
          setName('')
          setStartTime('')
          setEndTime('')
          setCrossesMidnight(false)
          setBreakMinutes('')
          setBreakStartTime('')
          setBreakEndTime('')
          setPrescribedWorkMinutes('')
        },
      },
    )
  }

  return (
    <Card title="シフトパターン(UC-C004)">
      {error && <ErrorMessage error={error} fallback="シフトパターンの取得に失敗しました。" />}
      {createShiftPattern.error && <ErrorMessage error={createShiftPattern.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (patterns ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">シフトパターンはまだありません。</p>
      ) : (
        <ul className="mb-4 divide-y divide-border">
          {(patterns ?? []).map((pattern) => (
            <li key={pattern.id} className="flex flex-wrap gap-3 py-2 text-sm">
              <strong className="font-semibold text-foreground">{pattern.name}</strong>
              <span className="text-muted-foreground">{pattern.code}</span>
              <span className="text-muted-foreground">
                {pattern.start_time ?? '--:--'}〜{pattern.end_time ?? '--:--'}
                {pattern.crosses_midnight ? '(翌日)' : ''}
              </span>
              <span className="text-muted-foreground">所定{pattern.prescribed_work_minutes}分</span>
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">シフトパターンを作成</h3>
      <p className="mb-3 text-xs text-muted-foreground">
        日勤・準夜勤・深夜勤のような勤務パターンのほか、所定労働時間を0分にすると公休・明け休みのような
        非労働日のパターンとして扱える。
      </p>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="パターンコード" htmlFor="shift-pattern-code" required>
          <Input id="shift-pattern-code" value={code} onChange={(e) => setCode(e.target.value)} />
        </FormField>

        <FormField label="パターン名称" htmlFor="shift-pattern-name" required>
          <Input id="shift-pattern-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>

        <FormField label="開始時刻" htmlFor="shift-pattern-start-time">
          <TimePicker id="shift-pattern-start-time" value={startTime} onChange={(time) => setStartTime(time ?? '')} />
        </FormField>

        <FormField label="終了時刻" htmlFor="shift-pattern-end-time">
          <TimePicker id="shift-pattern-end-time" value={endTime} onChange={(time) => setEndTime(time ?? '')} />
        </FormField>

        <FormField label="休憩(分)" htmlFor="shift-pattern-break-minutes">
          <Input
            id="shift-pattern-break-minutes"
            type="number"
            min={0}
            value={breakMinutes}
            onChange={(e) => setBreakMinutes(e.target.value)}
          />
        </FormField>

        <FormField label="休憩開始時刻" htmlFor="shift-pattern-break-start-time">
          <TimePicker id="shift-pattern-break-start-time" value={breakStartTime} onChange={(time) => setBreakStartTime(time ?? '')} />
          <p className="mt-1 text-xs text-muted-foreground">
            日次勤怠の入力画面で、打刻が無く勤務予定がある日の休憩の初期値に使う。
          </p>
        </FormField>

        <FormField label="休憩終了時刻" htmlFor="shift-pattern-break-end-time">
          <TimePicker id="shift-pattern-break-end-time" value={breakEndTime} onChange={(time) => setBreakEndTime(time ?? '')} />
        </FormField>

        <FormField label="所定労働時間(分)" htmlFor="shift-pattern-prescribed-minutes" required>
          <Input
            id="shift-pattern-prescribed-minutes"
            type="number"
            min={0}
            value={prescribedWorkMinutes}
            onChange={(e) => setPrescribedWorkMinutes(e.target.value)}
          />
        </FormField>
      </div>

      <label className="my-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={crossesMidnight} onCheckedChange={(checked) => setCrossesMidnight(checked === true)} />
        日跨ぎ勤務(終了時刻は翌日)
      </label>

      <Button isLoading={createShiftPattern.isPending} disabled={!code || !name} onClick={handleCreate}>
        シフトパターンを作成する
      </Button>
    </Card>
  )
}

/**
 * 指示書 8.4節: 交代制勤務のローテーションパターンを登録する。
 * A勤・B勤・C勤・休を1つの働き方の中の繰り返し周期としてまとめる(別々の働き方にしない)。
 */
function RotationPatternFormCard() {
  const { data: workStyles } = useWorkStyles()
  const { data: patterns } = useShiftPatterns()
  const { data: rotationPatterns } = useRotationPatterns()
  const createRotationPattern = useCreateRotationPattern()

  const shiftBasedWorkStyles = (workStyles ?? []).filter((style) => style.is_shift_based)

  const [workStyleId, setWorkStyleId] = useState('')
  const [name, setName] = useState('')
  const [items, setItems] = useState<string[]>([''])

  const handleAddItem = () => setItems((prev) => [...prev, ''])
  const handleRemoveItem = (index: number) => setItems((prev) => prev.filter((_, i) => i !== index))
  const handleItemChange = (index: number, shiftPatternId: string) =>
    setItems((prev) => prev.map((item, i) => (i === index ? shiftPatternId : item)))

  const handleCreate = () => {
    createRotationPattern.mutate(
      {
        work_style_id: workStyleId,
        name,
        items: items.map((shiftPatternId, index) => ({ sequence: index, shift_pattern_id: shiftPatternId })),
      },
      {
        onSuccess: () => {
          setWorkStyleId('')
          setName('')
          setItems([''])
        },
      },
    )
  }

  const canCreate = workStyleId !== '' && name !== '' && items.length > 0 && items.every((item) => item !== '')

  return (
    <Card title="ローテーションパターン(指示書8.4節)">
      {createRotationPattern.error && <ErrorMessage error={createRotationPattern.error} />}

      {(rotationPatterns ?? []).length === 0 ? (
        <p className="mb-4 text-sm text-muted-foreground">ローテーションパターンはまだありません。</p>
      ) : (
        <ul className="mb-4 divide-y divide-border">
          {rotationPatterns?.map((pattern) => (
            <li key={pattern.id} className="py-2 text-sm">
              <div className="flex flex-wrap items-center gap-3">
                <strong className="font-semibold text-foreground">{pattern.name}</strong>
                <span className="text-muted-foreground">周期{pattern.cycle_length}日</span>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {pattern.items.map((item) => item.shift_pattern_name ?? item.shift_pattern_code).join(' → ')}
              </p>
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">ローテーションパターンを作成</h3>
      <p className="mb-3 text-xs text-muted-foreground">
        [A][A][休][B][B][休][C][C][休]のような繰り返し周期を、上から順番に登録する。
      </p>

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象の働き方(シフト制のみ)" htmlFor="rotation-pattern-work-style" required>
          <NativeSelect
            id="rotation-pattern-work-style"
            value={workStyleId}
            onChange={(e) => setWorkStyleId(e.target.value)}
          >
            <option value="">選択してください</option>
            {shiftBasedWorkStyles.map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="ローテーションパターン名称" htmlFor="rotation-pattern-name" required>
          <Input id="rotation-pattern-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
      </div>

      <div className="mb-4 flex flex-col gap-2">
        {items.map((item, index) => (
          <div key={index} className="flex items-center gap-2">
            <span className="w-6 text-xs text-muted-foreground">{index + 1}</span>
            <NativeSelect
              aria-label={`${index + 1}日目のシフトパターン`}
              value={item}
              onChange={(e) => handleItemChange(index, e.target.value)}
            >
              <option value="">選択してください</option>
              {patterns?.map((pattern) => (
                <option key={pattern.id} value={pattern.id}>
                  {pattern.name}
                </option>
              ))}
            </NativeSelect>
            {items.length > 1 && (
              <Button variant="secondary" onClick={() => handleRemoveItem(index)}>
                削除
              </Button>
            )}
          </div>
        ))}
        <Button variant="secondary" onClick={handleAddItem}>
          周期に追加する
        </Button>
      </div>

      <Button isLoading={createRotationPattern.isPending} disabled={!canCreate} onClick={handleCreate}>
        ローテーションパターンを作成する
      </Button>
    </Card>
  )
}

/**
 * 指示書 8.5節〜8.9節: 社員をローテーションパターンに割り当て、開始日・開始位置から
 * カレンダープレビューを確認したうえで、日別の勤務予定を一括生成する。
 */
function RotationAssignmentCard() {
  const { data: rotationPatterns } = useRotationPatterns()
  const [targetUserId, setTargetUserId] = useState<string | undefined>(undefined)
  const [rotationPatternId, setRotationPatternId] = useState('')
  const [rotationStartDate, setRotationStartDate] = useState('')
  const [rotationStartPosition, setRotationStartPosition] = useState('0')
  const [generateFrom, setGenerateFrom] = useState('')
  const [generateTo, setGenerateTo] = useState('')
  const [overwriteMode, setOverwriteMode] = useState<'skip_edited' | 'overwrite_all'>('skip_edited')

  const { data: currentAssignment } = useEmployeeRotationAssignment(targetUserId)
  const assignRotation = useAssignEmployeeRotation()
  const previewRotation = usePreviewRotationPattern()
  const generateShifts = useGenerateRotationShiftAssignments()

  const selectedPattern = rotationPatterns?.find((pattern) => pattern.id === rotationPatternId)

  const handleAssign = () => {
    if (!targetUserId || !rotationPatternId || !rotationStartDate) return
    assignRotation.mutate({
      user_id: targetUserId,
      rotation_pattern_id: rotationPatternId,
      rotation_start_date: rotationStartDate,
      rotation_start_position: Number(rotationStartPosition),
    })
  }

  const handlePreview = () => {
    if (!rotationPatternId || !rotationStartDate || !generateFrom || !generateTo) return
    previewRotation.mutate({
      rotationPatternId,
      input: {
        rotation_start_date: rotationStartDate,
        rotation_start_position: Number(rotationStartPosition),
        from: generateFrom,
        to: generateTo,
      },
    })
  }

  const handleGenerate = () => {
    if (!targetUserId || !generateFrom || !generateTo) return
    generateShifts.mutate({
      user_id: targetUserId,
      from: generateFrom,
      to: generateTo,
      overwrite_mode: overwriteMode,
    })
  }

  return (
    <Card title="ローテーションの割当・生成(指示書8.5節〜8.8節)">
      {assignRotation.error && <ErrorMessage error={assignRotation.error} />}
      {previewRotation.error && <ErrorMessage error={previewRotation.error} />}
      {generateShifts.error && <ErrorMessage error={generateShifts.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員(ローテーション)" htmlFor="rotation-assignment-user" required>
          <UserPicker id="rotation-assignment-user" value={targetUserId} onChange={setTargetUserId} />
        </FormField>

        <FormField label="ローテーションパターン" htmlFor="rotation-assignment-pattern" required>
          <NativeSelect
            id="rotation-assignment-pattern"
            value={rotationPatternId}
            onChange={(e) => setRotationPatternId(e.target.value)}
          >
            <option value="">選択してください</option>
            {rotationPatterns?.map((pattern) => (
              <option key={pattern.id} value={pattern.id}>
                {pattern.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="ローテーション開始日" htmlFor="rotation-assignment-start-date" required>
          <DatePicker
            id="rotation-assignment-start-date"
            value={rotationStartDate || undefined}
            onChange={(date) => setRotationStartDate(date ?? '')}
          />
        </FormField>

        <FormField label="開始位置(0始まり)" htmlFor="rotation-assignment-start-position" required>
          <Input
            id="rotation-assignment-start-position"
            type="number"
            min={0}
            max={selectedPattern ? selectedPattern.cycle_length - 1 : undefined}
            value={rotationStartPosition}
            onChange={(e) => setRotationStartPosition(e.target.value)}
          />
          <p className="mt-1 text-xs text-muted-foreground">開始日にローテーションの何番目(0始まり)が来るかを指定する。</p>
        </FormField>
      </div>

      {currentAssignment && (
        <p className="mb-4 text-xs text-muted-foreground">
          現在の割当: {currentAssignment.rotation_pattern_name}({currentAssignment.rotation_start_date}を基準)
        </p>
      )}

      <Button
        isLoading={assignRotation.isPending}
        disabled={!targetUserId || !rotationPatternId || !rotationStartDate}
        onClick={handleAssign}
      >
        ローテーションを割り当てる
      </Button>

      <div className="mt-5 border-t border-border pt-4">
        <h3 className="mb-2 text-sm font-semibold text-foreground">カレンダープレビュー・生成</h3>

        <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="生成開始日" htmlFor="rotation-generate-from" required>
            <DatePicker id="rotation-generate-from" value={generateFrom || undefined} onChange={(date) => setGenerateFrom(date ?? '')} />
          </FormField>

          <FormField label="生成終了日" htmlFor="rotation-generate-to" required>
            <DatePicker id="rotation-generate-to" value={generateTo || undefined} onChange={(date) => setGenerateTo(date ?? '')} />
          </FormField>
        </div>

        <div className="mb-4 flex flex-wrap gap-3">
          <Button
            variant="secondary"
            isLoading={previewRotation.isPending}
            disabled={!rotationPatternId || !rotationStartDate || !generateFrom || !generateTo}
            onClick={handlePreview}
          >
            プレビューする
          </Button>
        </div>

        {previewRotation.data && (
          <ul className="mb-4 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
            {previewRotation.data.days.map((day: RotationPreviewDay) => (
              <li key={day.date} className="text-foreground">
                {day.date}: {day.shift_pattern_name ?? day.shift_pattern_code ?? '-'}
              </li>
            ))}
          </ul>
        )}

        <FormField label="再生成時の扱い" htmlFor="rotation-overwrite-mode">
          <NativeSelect
            id="rotation-overwrite-mode"
            value={overwriteMode}
            onChange={(e) => setOverwriteMode(e.target.value as 'skip_edited' | 'overwrite_all')}
          >
            <option value="skip_edited">未編集日のみ再生成する(安全)</option>
            <option value="overwrite_all">個別上書きも含めてすべて再生成する</option>
          </NativeSelect>
          <p className="mt-1 text-xs text-muted-foreground">実績のある日・締め済みの日はどちらを選んでも上書きされない。</p>
        </FormField>

        <Button
          isLoading={generateShifts.isPending}
          disabled={!targetUserId || !generateFrom || !generateTo}
          onClick={handleGenerate}
        >
          勤務予定を生成する
        </Button>

        {generateShifts.data && (
          <p className="mt-3 text-sm text-foreground">
            {generateShifts.data.generated_count}件生成しました。
            {generateShifts.data.skipped_dates.length > 0 &&
              `(${generateShifts.data.skipped_dates.length}件は既に実績・個別編集があるためスキップしました)`}
          </p>
        )}
      </div>
    </Card>
  )
}

function ShiftScheduleBoardCard() {
  const { data: workStyles } = useWorkStyles()
  const { data: patterns } = useShiftPatterns()

  const [userId, setUserId] = useState<string | undefined>(undefined)
  const [workStyleId, setWorkStyleId] = useState('')
  const [workDate, setWorkDate] = useState('')
  const [shiftPatternId, setShiftPatternId] = useState('')
  const [isLegalHoliday, setIsLegalHoliday] = useState(false)

  const [department, setDepartment] = useState('')
  const [yearMonth, setYearMonth] = useState('')

  const assignPattern = useAssignShiftPatternDay()
  const publishSchedule = usePublishShiftSchedule()

  const reviewTarget = department && yearMonth ? { department, year_month: yearMonth } : undefined
  const { data: review, isLoading: isLoadingReview } = useShiftScheduleReview(reviewTarget)

  const handleAssign = () => {
    if (!userId || !workStyleId || !workDate || !shiftPatternId) return
    assignPattern.mutate({
      user_id: userId,
      work_style_id: workStyleId,
      work_date: workDate,
      shift_pattern_id: shiftPatternId,
      is_legal_holiday: isLegalHoliday,
    })
  }

  const handlePublish = () => {
    if (!department || !yearMonth) return
    publishSchedule.mutate({ department, year_month: yearMonth })
  }

  return (
    <Card title="3交代制シフト表(UC-C004)">
      {assignPattern.error && <ErrorMessage error={assignPattern.error} />}

      <h3 className="mb-3 text-sm font-semibold text-foreground">日別にシフトパターンを割り当てる</h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員(シフト表)" htmlFor="shift-board-user" required>
          <UserPicker id="shift-board-user" value={userId} onChange={setUserId} />
        </FormField>

        <FormField label="勤務形態(シフト表)" htmlFor="shift-board-work-style" required>
          <NativeSelect id="shift-board-work-style" value={workStyleId} onChange={(e) => setWorkStyleId(e.target.value)}>
            <option value="">選択してください</option>
            {workStyles?.filter((style) => style.is_shift_based).map((style) => (
              <option key={style.id} value={style.id}>
                {style.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="対象日" htmlFor="shift-board-date" required>
          <DatePicker id="shift-board-date" value={workDate || undefined} onChange={(date) => setWorkDate(date ?? '')} />
        </FormField>

        <FormField label="シフトパターン" htmlFor="shift-board-pattern" required>
          <NativeSelect id="shift-board-pattern" value={shiftPatternId} onChange={(e) => setShiftPatternId(e.target.value)}>
            <option value="">選択してください</option>
            {patterns?.map((pattern) => (
              <option key={pattern.id} value={pattern.id}>
                {pattern.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      <label className="my-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isLegalHoliday} onCheckedChange={(checked) => setIsLegalHoliday(checked === true)} />
        この日を法定休日にする
      </label>

      <Button
        isLoading={assignPattern.isPending}
        disabled={!userId || !workStyleId || !workDate || !shiftPatternId}
        onClick={handleAssign}
      >
        割り当てる(下書き)
      </Button>

      <div className="mt-6 border-t border-border pt-4">
        <h3 className="mb-3 text-sm font-semibold text-foreground">公開前確認・公開</h3>
        <p className="mb-3 text-xs text-muted-foreground">
          割り当てたシフトは下書きのままでは対象社員に見えない。部署・対象月を指定して、法定休日不足・連続勤務・
          月間予定時間の警告を確認してから公開する(警告があっても公開はブロックされない)。
        </p>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="対象部署" htmlFor="shift-board-department" required>
            <Input id="shift-board-department" value={department} onChange={(e) => setDepartment(e.target.value)} />
          </FormField>

          <FormField label="対象月" htmlFor="shift-board-year-month" required>
            <Input
              id="shift-board-year-month"
              type="month"
              value={yearMonth}
              onChange={(e) => setYearMonth(e.target.value)}
            />
          </FormField>
        </div>

        {isLoadingReview ? (
          <LoadingState />
        ) : review ? (
          <div className="mb-4 space-y-2 text-sm">
            {review.legal_holiday_shortages.length === 0 &&
            review.consecutive_work_violations.length === 0 &&
            review.monthly_hours_over_cap.length === 0 ? (
              <p className="text-muted-foreground">警告はありません。</p>
            ) : (
              <>
                {review.legal_holiday_shortages.map((warning, index) => (
                  <p key={`legal-${index}`} className="text-amber-600">
                    社員ID{warning.user_id}: {warning.period_start}〜{warning.period_end}
                    に法定休日が不足しています({warning.legal_holiday_count}/{warning.required_count})。
                  </p>
                ))}
                {review.consecutive_work_violations.map((warning, index) => (
                  <p key={`consecutive-${index}`} className="text-amber-600">
                    社員ID{warning.user_id}: {warning.period_start}〜{warning.period_end}
                    に{warning.consecutive_days}日連続勤務(上限{warning.max_allowed}日)。
                  </p>
                ))}
                {review.monthly_hours_over_cap.map((warning, index) => (
                  <p key={`monthly-${index}`} className="text-amber-600">
                    社員ID{warning.user_id}: {warning.year_month}
                    の所定労働時間合計が法定労働時間の総枠を超えています({warning.planned_minutes}分/
                    {warning.statutory_cap_minutes}分)。
                  </p>
                ))}
              </>
            )}
          </div>
        ) : null}

        {publishSchedule.error && <ErrorMessage error={publishSchedule.error} />}
        {publishSchedule.isSuccess && (
          <p className="mb-3 text-sm text-foreground">
            {publishSchedule.data.published_count}件のシフトを公開しました。
          </p>
        )}

        <Button isLoading={publishSchedule.isPending} disabled={!department || !yearMonth} onClick={handlePublish}>
          公開する
        </Button>
      </div>
    </Card>
  )
}

/**
 * UC-C002: 勤務形態の作成・一覧。UC-C003: 個別シフトの生成・確認。
 * UC-C004: 3交代制シフトパターンの作成・日別割当・公開前確認・公開。
 * デフォルト働き方が未設定の間は初回オンボーディングを表示する(指示書 12.1節)。
 */
export function WorkStylesAndShiftsPage() {
  return (
    <div className="flex flex-col gap-6">
      <WorkStyleOnboardingCard />
      <WorkStyleFormCard />
      <MonthlyWorkStyleAssignmentCard />
      <ShiftGenerationCard />
      <WeeklyPatternShiftAssignmentCard />
      <MonthlyPatternShiftAssignmentCard />
      <ShiftPatternFormCard />
      <RotationPatternFormCard />
      <RotationAssignmentCard />
      <ShiftScheduleBoardCard />
    </div>
  )
}
