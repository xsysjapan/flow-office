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
import { useShiftAssignments, useGenerateShiftAssignments } from '../hooks/useEmployeeShiftAssignments'
import { useWorkCalendars } from '../hooks/useWorkCalendars'
import { useCreateWorkStyle, useWorkStyles } from '../hooks/useWorkStyles'

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
              <span className="text-muted-foreground">{style.work_time_system}</span>
              <span className="text-muted-foreground">{style.prescribed_daily_minutes}分/日</span>
              <span className="text-muted-foreground">{style.is_shift_based ? 'シフト制' : '固定制'}</span>
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
          <Input id="work-style-time-system" value={workTimeSystem} onChange={(e) => setWorkTimeSystem(e.target.value)} />
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

      <Button
        isLoading={createWorkStyle.isPending}
        disabled={
          !code || !name || !workTimeSystem || !prescribedDailyMinutes || !prescribedWeeklyMinutes || !calendarId
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
 * UC-C002: 勤務形態の作成・一覧。UC-C003: 個別シフトの生成・確認。
 */
export function WorkStylesAndShiftsPage() {
  return (
    <div className="flex flex-col gap-6">
      <WorkStyleFormCard />
      <ShiftGenerationCard />
    </div>
  )
}
