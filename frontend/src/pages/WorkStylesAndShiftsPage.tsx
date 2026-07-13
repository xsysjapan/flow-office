import { useState } from 'react'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Checkbox } from '../components/ui/checkbox'
import { Input } from '../components/ui/input'
import { NativeSelect } from '../components/ui/native-select'
import { UserPicker } from '../components/UserPicker/UserPicker'
import {
  useAssignEmployeeRotation,
  useEmployeeRotationAssignment,
  useGenerateRotationShiftAssignments,
} from '../hooks/useEmployeeRotationAssignments'
import {
  useAssignShiftPatternDay,
  useGenerateShiftAssignments,
  usePublishShiftSchedule,
  useShiftAssignments,
  useShiftScheduleReview,
} from '../hooks/useEmployeeShiftAssignments'
import { useCreateRotationPattern, usePreviewRotationPattern, useRotationPatterns } from '../hooks/useRotationPatterns'
import { useCreateShiftPattern, useShiftPatterns } from '../hooks/useShiftPatterns'
import {
  useAssignUserWorkStyleForMonth,
  useUserWorkStyleMonthlyAssignments,
} from '../hooks/useUserWorkStyleMonthlyAssignments'
import { useUser } from '../hooks/useUsers'
import { useWorkCalendars } from '../hooks/useWorkCalendars'
import {
  useCreateDefaultWorkStyle,
  useCreateWorkStyle,
  useSetDefaultWorkStyle,
  useWorkStyles,
} from '../hooks/useWorkStyles'
import type { LegalHolidayRule, RotationPreviewDay, WorkStyle } from '../api/types'

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
              <Input
                id="onboarding-work-style-start-time"
                type="time"
                value={startTime}
                onChange={(e) => setStartTime(e.target.value)}
              />
            </FormField>

            <FormField label="終業時刻" htmlFor="onboarding-work-style-end-time" required>
              <Input
                id="onboarding-work-style-end-time"
                type="time"
                value={endTime}
                onChange={(e) => setEndTime(e.target.value)}
              />
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
  const setDefaultWorkStyle = useSetDefaultWorkStyle()

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [workTimeSystem, setWorkTimeSystem] = useState('')
  const [prescribedDailyMinutes, setPrescribedDailyMinutes] = useState('')
  const [prescribedWeeklyMinutes, setPrescribedWeeklyMinutes] = useState('')
  const [defaultStartTime, setDefaultStartTime] = useState('')
  const [defaultEndTime, setDefaultEndTime] = useState('')
  const [defaultBreakMinutes, setDefaultBreakMinutes] = useState('')
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

  const handleCreateWorkStyle = () => {
    createWorkStyle.mutate(
      {
        code,
        name,
        work_time_system: workTimeSystem,
        prescribed_daily_minutes: Number(prescribedDailyMinutes),
        prescribed_weekly_minutes: Number(prescribedWeeklyMinutes),
        default_start_time: defaultStartTime || undefined,
        default_end_time: defaultEndTime || undefined,
        default_break_minutes: defaultBreakMinutes ? Number(defaultBreakMinutes) : undefined,
        calendar_id: Number(calendarId),
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
      },
      {
        onSuccess: () => {
          setCode('')
          setName('')
          setWorkTimeSystem('')
          setPrescribedDailyMinutes('')
          setPrescribedWeeklyMinutes('')
          setDefaultStartTime('')
          setDefaultEndTime('')
          setDefaultBreakMinutes('')
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
        },
      },
    )
  }

  return (
    <Card id="work-style-create-form" title="勤務形態">
      {error && <ErrorMessage error={error} fallback="勤務形態の取得に失敗しました。" />}
      {createWorkStyle.error && <ErrorMessage error={createWorkStyle.error} />}
      {setDefaultWorkStyle.error && <ErrorMessage error={setDefaultWorkStyle.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (workStyles ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">勤務形態はまだありません。</p>
      ) : (
        <ul className="mb-4 divide-y divide-border">
          {(workStyles ?? []).map((style) => (
            <li key={style.id} className="flex flex-wrap items-center gap-3 py-2 text-sm">
              <strong className="font-semibold text-foreground">{style.name}</strong>
              <span className="text-muted-foreground">{style.code}</span>
              <span className="text-muted-foreground">{workTimeSystemLabel(style.work_time_system)}</span>
              <span className="text-muted-foreground">{style.prescribed_daily_minutes}分/日</span>
              <span className="text-muted-foreground">{style.is_shift_based ? 'シフト制' : '固定制'}</span>
              {style.is_shift_based && (
                <span className="text-muted-foreground">{legalHolidayRuleDescription(style)}</span>
              )}
              {style.is_default ? (
                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                  デフォルト
                </span>
              ) : (
                <Button
                  variant="secondary"
                  isLoading={setDefaultWorkStyle.isPending}
                  onClick={() => setDefaultWorkStyle.mutate(style.id)}
                >
                  デフォルトに設定
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">勤務形態を作成</h3>

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
          <Input
            id="work-style-start-time"
            type="time"
            value={defaultStartTime}
            onChange={(e) => setDefaultStartTime(e.target.value)}
          />
        </FormField>

        <FormField label="標準終了時刻" htmlFor="work-style-end-time">
          <Input
            id="work-style-end-time"
            type="time"
            value={defaultEndTime}
            onChange={(e) => setDefaultEndTime(e.target.value)}
          />
        </FormField>

        <FormField label="標準休憩(分)" htmlFor="work-style-break-minutes">
          <Input
            id="work-style-break-minutes"
            type="number"
            value={defaultBreakMinutes}
            onChange={(e) => setDefaultBreakMinutes(e.target.value)}
          />
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
              <Input
                id="work-style-four-week-start"
                type="date"
                value={fourWeekPeriodStartDate}
                onChange={(e) => setFourWeekPeriodStartDate(e.target.value)}
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
            <Input
              id="work-style-flexible-start"
              type="time"
              value={flexibleTimeStart}
              onChange={(e) => setFlexibleTimeStart(e.target.value)}
            />
          </FormField>

          <FormField label="勤務可能終了時刻" htmlFor="work-style-flexible-end">
            <Input
              id="work-style-flexible-end"
              type="time"
              value={flexibleTimeEnd}
              onChange={(e) => setFlexibleTimeEnd(e.target.value)}
            />
          </FormField>

          <label className="flex items-center gap-2 text-sm font-medium text-foreground sm:col-span-2">
            <Checkbox checked={coreTimeEnabled} onCheckedChange={(checked) => setCoreTimeEnabled(checked === true)} />
            コアタイムあり
          </label>

          {coreTimeEnabled && (
            <>
              <FormField label="コアタイム開始時刻" htmlFor="work-style-core-time-start" required>
                <Input
                  id="work-style-core-time-start"
                  type="time"
                  value={coreTimeStart}
                  onChange={(e) => setCoreTimeStart(e.target.value)}
                />
              </FormField>

              <FormField label="コアタイム終了時刻" htmlFor="work-style-core-time-end" required>
                <Input
                  id="work-style-core-time-end"
                  type="time"
                  value={coreTimeEnd}
                  onChange={(e) => setCoreTimeEnd(e.target.value)}
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  労働時間は足りていてもコアタイム中に不在の場合は別枠の警告になる(指示書7.4節)。
                </p>
              </FormField>
            </>
          )}
        </div>
      )}

      <Button
        isLoading={createWorkStyle.isPending}
        disabled={
          !code ||
          !name ||
          !workTimeSystem ||
          !prescribedDailyMinutes ||
          !prescribedWeeklyMinutes ||
          !calendarId ||
          (isShiftBased && legalHolidayRule === 'four_weeks_four_days' && !fourWeekPeriodStartDate) ||
          (isFlex && coreTimeEnabled && (!coreTimeStart || !coreTimeEnd))
        }
        onClick={handleCreateWorkStyle}
      >
        作成する
      </Button>
    </Card>
  )
}

function ShiftGenerationCard() {
  const { data: workStyles } = useWorkStyles()
  const [shiftUserId, setShiftUserId] = useState<number | undefined>(undefined)
  const [shiftWorkStyleId, setShiftWorkStyleId] = useState('')
  const [shiftFrom, setShiftFrom] = useState('')
  const [shiftTo, setShiftTo] = useState('')

  const generateShifts = useGenerateShiftAssignments()
  const { data: shiftAssignments, isLoading: isLoadingShifts } = useShiftAssignments(
    shiftUserId ?? NaN,
    shiftFrom,
    shiftTo,
  )

  const handleGenerateShifts = () => {
    if (!shiftUserId || !shiftWorkStyleId) return
    generateShifts.mutate({
      user_id: shiftUserId,
      work_style_id: Number(shiftWorkStyleId),
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
          <Input id="shift-from" type="date" value={shiftFrom} onChange={(e) => setShiftFrom(e.target.value)} />
        </FormField>

        <FormField label="終了日" htmlFor="shift-to" required>
          <Input id="shift-to" type="date" value={shiftTo} onChange={(e) => setShiftTo(e.target.value)} />
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

/**
 * 10月までは通常勤務、11月からシフト勤務のように、ユーザーの月次働き方を切り替える。
 * 過去月の割当は変更されず履歴として残る(docs/16-database-schema.md
 * user_work_style_monthly_assignments)。
 */
function MonthlyWorkStyleAssignmentCard() {
  const { data: workStyles } = useWorkStyles()
  const [targetUserId, setTargetUserId] = useState<number | undefined>(undefined)
  const [yearMonth, setYearMonth] = useState('')
  const [workStyleId, setWorkStyleId] = useState('')
  const [isConfirming, setIsConfirming] = useState(false)
  const [autoGenerateShifts, setAutoGenerateShifts] = useState(false)

  const { data: targetUser } = useUser(targetUserId ?? NaN)
  const { data: history, isLoading: isLoadingHistory } = useUserWorkStyleMonthlyAssignments(targetUserId)
  const assignForMonth = useAssignUserWorkStyleForMonth()
  const generateShifts = useGenerateShiftAssignments()

  const currentAssignment = history?.find((assignment) => assignment.year_month === yearMonth)
  const selectedWorkStyle = workStyles?.find((style) => String(style.id) === workStyleId)

  const handleUserChange = (userId: number | undefined) => {
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
      { user_id: targetUserId, year_month: yearMonth, work_style_id: Number(workStyleId) },
      {
        onSuccess: () => {
          if (autoGenerateShifts) {
            const { from, to } = monthBoundaries(yearMonth)
            generateShifts.mutate({ user_id: targetUserId, work_style_id: Number(workStyleId), from, to })
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
          <Input id="shift-pattern-start-time" type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
        </FormField>

        <FormField label="終了時刻" htmlFor="shift-pattern-end-time">
          <Input id="shift-pattern-end-time" type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
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
        work_style_id: Number(workStyleId),
        name,
        items: items.map((shiftPatternId, index) => ({ sequence: index, shift_pattern_id: Number(shiftPatternId) })),
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
  const [targetUserId, setTargetUserId] = useState<number | undefined>(undefined)
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

  const selectedPattern = rotationPatterns?.find((pattern) => String(pattern.id) === rotationPatternId)

  const handleAssign = () => {
    if (!targetUserId || !rotationPatternId || !rotationStartDate) return
    assignRotation.mutate({
      user_id: targetUserId,
      rotation_pattern_id: Number(rotationPatternId),
      rotation_start_date: rotationStartDate,
      rotation_start_position: Number(rotationStartPosition),
    })
  }

  const handlePreview = () => {
    if (!rotationPatternId || !rotationStartDate || !generateFrom || !generateTo) return
    previewRotation.mutate({
      rotationPatternId: Number(rotationPatternId),
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
          <Input
            id="rotation-assignment-start-date"
            type="date"
            value={rotationStartDate}
            onChange={(e) => setRotationStartDate(e.target.value)}
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
            <Input
              id="rotation-generate-from"
              type="date"
              value={generateFrom}
              onChange={(e) => setGenerateFrom(e.target.value)}
            />
          </FormField>

          <FormField label="生成終了日" htmlFor="rotation-generate-to" required>
            <Input
              id="rotation-generate-to"
              type="date"
              value={generateTo}
              onChange={(e) => setGenerateTo(e.target.value)}
            />
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

  const [userId, setUserId] = useState<number | undefined>(undefined)
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
      work_style_id: Number(workStyleId),
      work_date: workDate,
      shift_pattern_id: Number(shiftPatternId),
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
          <Input id="shift-board-date" type="date" value={workDate} onChange={(e) => setWorkDate(e.target.value)} />
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
      <ShiftPatternFormCard />
      <RotationPatternFormCard />
      <RotationAssignmentCard />
      <ShiftScheduleBoardCard />
    </div>
  )
}
