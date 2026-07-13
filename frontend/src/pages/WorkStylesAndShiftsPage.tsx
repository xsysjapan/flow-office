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
  useAssignShiftPatternDay,
  useGenerateShiftAssignments,
  usePublishShiftSchedule,
  useShiftAssignments,
  useShiftScheduleReview,
} from '../hooks/useEmployeeShiftAssignments'
import { useCreateShiftPattern, useShiftPatterns } from '../hooks/useShiftPatterns'
import { useWorkCalendars } from '../hooks/useWorkCalendars'
import { useCreateWorkStyle, useWorkStyles } from '../hooks/useWorkStyles'
import type { LegalHolidayRule, WorkStyle } from '../api/types'

const WORK_TIME_SYSTEM_OPTIONS = [
  { value: 'fixed', label: '通常勤務' },
  { value: 'monthly_variable', label: '1か月単位変形労働時間制' },
  { value: 'discretionary', label: '裁量労働制' },
  { value: 'manager_supervisor', label: '管理監督者' },
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
        },
      },
    )
  }

  return (
    <Card title="勤務形態">
      {error && <ErrorMessage error={error} fallback="勤務形態の取得に失敗しました。" />}
      {createWorkStyle.error && <ErrorMessage error={createWorkStyle.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (workStyles ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">勤務形態はまだありません。</p>
      ) : (
        <ul className="mb-4 divide-y divide-border">
          {(workStyles ?? []).map((style) => (
            <li key={style.id} className="flex flex-wrap gap-3 py-2 text-sm">
              <strong className="font-semibold text-foreground">{style.name}</strong>
              <span className="text-muted-foreground">{style.code}</span>
              <span className="text-muted-foreground">{workTimeSystemLabel(style.work_time_system)}</span>
              <span className="text-muted-foreground">{style.prescribed_daily_minutes}分/日</span>
              <span className="text-muted-foreground">{style.is_shift_based ? 'シフト制' : '固定制'}</span>
              {style.is_shift_based && (
                <span className="text-muted-foreground">{legalHolidayRuleDescription(style)}</span>
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

      <Button
        isLoading={createWorkStyle.isPending}
        disabled={
          !code ||
          !name ||
          !workTimeSystem ||
          !prescribedDailyMinutes ||
          !prescribedWeeklyMinutes ||
          !calendarId ||
          (isShiftBased && legalHolidayRule === 'four_weeks_four_days' && !fourWeekPeriodStartDate)
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
 */
export function WorkStylesAndShiftsPage() {
  return (
    <div className="flex flex-col gap-6">
      <WorkStyleFormCard />
      <ShiftGenerationCard />
      <ShiftPatternFormCard />
      <ShiftScheduleBoardCard />
    </div>
  )
}
