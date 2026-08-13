import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Popover, PopoverContent, PopoverTrigger } from '../../components/ui/popover'
import type { HolidayCalendarSyncSummary, WorkCalendarDay, WorkCalendarYearStatus } from '../../api/types'
import { cn } from '../../lib/utils'
import { addDays, addMonths, datesInMonth } from '../../utils/weekDates'
import {
  useArchiveWorkCalendarYear,
  useCompanyCalendarYearById,
  useCompanyCalendarYearDays,
  useDuplicateWorkCalendarYear,
  usePublishWorkCalendarYear,
  usePutWorkCalendarDays,
  useSyncCompanyCalendarYearHolidayCalendar,
  useUnpublishWorkCalendarYear,
} from '../../hooks/useWorkCalendars'

type ScheduleState = 'WORK' | 'OFF'

interface DayState {
  schedule_state: ScheduleState
  is_public_holiday: boolean
  public_holiday_name: string
  note: string
}

const DEFAULT_DAY_STATE: DayState = {
  schedule_state: 'WORK',
  is_public_holiday: false,
  public_holiday_name: '',
  note: '',
}

const WEEKDAY_LABELS_JA = ['日', '月', '火', '水', '木', '金', '土']

const YEAR_STATUS_LABEL: Record<WorkCalendarYearStatus, string> = {
  draft: '未公開',
  published: '公開済み',
  archived: '廃止',
}

const YEAR_STATUS_TONE: Record<WorkCalendarYearStatus, 'neutral' | 'success' | 'danger'> = {
  draft: 'neutral',
  published: 'success',
  archived: 'danger',
}

const HOLIDAY_SOURCE_MANAGEMENT_ANCHOR = 'holiday-source-management'

function formatSyncSummary(summary: HolidayCalendarSyncSummary): string {
  return `追加 ${summary.added}件・更新 ${summary.updated}件・削除 ${summary.removed}件・カレンダーに反映 ${summary.applied}件(手動変更保護のためスキップ ${summary.protected_conflicts}件)`
}

/** UC-C010: 祝日属性(祝日か否か)と勤務区分(WORK/OFF)を別の入力として扱う。 */
function deriveDayType(state: DayState): string {
  if (state.is_public_holiday) return 'public_holiday'
  return state.schedule_state === 'WORK' ? 'weekday' : 'holiday'
}

function toDayState(day: WorkCalendarDay): DayState {
  return {
    schedule_state: day.schedule_state,
    is_public_holiday: day.is_public_holiday,
    public_holiday_name: day.public_holiday_name ?? '',
    note: day.note ?? '',
  }
}

/** `startsOn`〜`endsOn`(両端含む)の全日付("YYYY-MM-DD")を並べて返す。 */
export function allDatesInRange(startsOn: string, endsOn: string): string[] {
  const dates: string[] = []
  let cursor = startsOn
  while (cursor <= endsOn) {
    dates.push(cursor)
    cursor = addDays(cursor, 1)
  }
  return dates
}

/** `startsOn`〜`endsOn`が属する暦月("YYYY-MM")を開始から終了まで順に並べて返す。 */
export function monthsInRange(startsOn: string, endsOn: string): string[] {
  const months: string[] = []
  let cursor = startsOn.slice(0, 7)
  const endMonth = endsOn.slice(0, 7)
  // eslint-disable-next-line no-constant-condition
  while (true) {
    months.push(cursor)
    if (cursor === endMonth) break
    cursor = addMonths(cursor, 1)
  }
  return months
}

/**
 * 1ヶ月分のカレンダーグリッドを週単位の行に組む(週の起算曜日に応じて先頭・末尾に
 * 空セル(null)を補う)。既存の`DatePicker`(react-day-picker)は単一日付選択の
 * ポップオーバー用途に特化しており、年度全体を常時表示する本画面の12ヶ月分グリッド+
 * セルごとのポップオーバー編集には向かないため、`datesInMonth`等の既存日付ユーティリティ
 * (`utils/weekDates.ts`)を組み合わせて専用に組む。
 */
export function buildMonthWeeks(yearMonth: string, weekStartsOn: number): (string | null)[][] {
  const dates = datesInMonth(yearMonth)
  const first = new Date(`${dates[0]}T00:00:00`)
  const leading = (first.getDay() - weekStartsOn + 7) % 7

  const cells: (string | null)[] = [...Array<null>(leading).fill(null), ...dates]
  while (cells.length % 7 !== 0) cells.push(null)

  const weeks: (string | null)[][] = []
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7))
  return weeks
}

function orderedWeekdayLabels(weekStartsOn: number): string[] {
  return Array.from({ length: 7 }, (_, i) => WEEKDAY_LABELS_JA[(weekStartsOn + i) % 7])
}

interface DayCellProps {
  date: string
  state: DayState
  inRange: boolean
  onChange: (next: DayState) => void
}

function DayCell({ date, state, inRange, onChange }: DayCellProps) {
  const dayOfMonth = Number(date.slice(8, 10))

  if (!inRange) {
    return (
      <div className="flex aspect-square min-h-9 items-center justify-center rounded-md text-sm text-muted-foreground opacity-40">
        {dayOfMonth}
      </div>
    )
  }

  const statusLabel = state.is_public_holiday
    ? `祝日${state.public_holiday_name ? `(${state.public_holiday_name})` : ''}`
    : state.schedule_state === 'WORK'
      ? '勤務日'
      : '休日'

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={`${date} ${statusLabel}`}
          className={cn(
            'flex aspect-square min-h-9 flex-col items-center justify-center gap-0.5 rounded-md border text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
            state.is_public_holiday
              ? 'border-warning/40 bg-warning/15 text-warning hover:bg-warning/25'
              : state.schedule_state === 'OFF'
                ? 'border-border bg-muted text-muted-foreground hover:bg-accent'
                : 'border-border bg-card text-foreground hover:bg-accent',
          )}
        >
          <span>{dayOfMonth}</span>
          {state.is_public_holiday && <span className="text-[10px] leading-none">祝</span>}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-64 p-4">
        <div className="flex flex-col gap-3">
          <p className="text-sm font-medium text-foreground">{date}</p>

          <div className="flex flex-col gap-1.5">
            <span className="text-sm text-foreground">勤務区分</span>
            <NativeSelect
              aria-label={`${date}の勤務区分`}
              value={state.schedule_state}
              onChange={(e) => onChange({ ...state, schedule_state: e.target.value as ScheduleState })}
            >
              <option value="WORK">WORK(勤務日)</option>
              <option value="OFF">OFF(休日)</option>
            </NativeSelect>
          </div>

          <label className="flex items-center gap-2 text-sm text-foreground">
            <Checkbox
              aria-label={`${date}の祝日`}
              checked={state.is_public_holiday}
              onCheckedChange={(checked) => onChange({ ...state, is_public_holiday: checked === true })}
            />
            祝日
          </label>

          {state.is_public_holiday && (
            <Input
              aria-label={`${date}の祝日名`}
              placeholder="祝日名"
              value={state.public_holiday_name}
              onChange={(e) => onChange({ ...state, public_holiday_name: e.target.value })}
            />
          )}

          <Input
            aria-label={`${date}のメモ`}
            placeholder="メモ"
            value={state.note}
            onChange={(e) => onChange({ ...state, note: e.target.value })}
          />
        </div>
      </PopoverContent>
    </Popover>
  )
}

/**
 * UC-C010: カレンダー年度の日別属性(勤務区分・祝日)を、月カレンダーのグリッドUIで
 * 一覧・編集する。新規作成された年度は週次パターンからの初期値で既にほぼ全日埋まっている
 * 前提(`GET .../days`)で、テーブルの行を1件ずつ追加する旧UI(365行を手で追加する必要が
 * あった)を廃止し、実データを読み込んだ上でカレンダー上のセルをクリックして編集する形に
 * 刷新する。保存は編集内容をまとめて`PUT .../days`に送る点は変わらない。
 */
export function WorkCalendarDaysPage() {
  const { yearId } = useParams<{ yearId: string }>()
  const putDays = usePutWorkCalendarDays()

  const { year, calendar, isLoading: isLoadingYear, error: yearError } = useCompanyCalendarYearById(yearId ?? '')
  const daysQuery = useCompanyCalendarYearDays(yearId ?? '')

  const publishYear = usePublishWorkCalendarYear(calendar?.id ?? '')
  const unpublishYear = useUnpublishWorkCalendarYear(calendar?.id ?? '')
  const archiveYear = useArchiveWorkCalendarYear(calendar?.id ?? '')
  const duplicateYear = useDuplicateWorkCalendarYear(calendar?.id ?? '')
  const syncYearHolidayCalendar = useSyncCompanyCalendarYearHolidayCalendar()

  const [syncSummary, setSyncSummary] = useState<HolidayCalendarSyncSummary | null>(null)

  const [daysMap, setDaysMap] = useState<Map<string, DayState>>(new Map())
  const [loadedForYearId, setLoadedForYearId] = useState<string | null>(null)
  const [hoursPerDay, setHoursPerDay] = useState(8)

  useEffect(() => {
    if (!daysQuery.data || loadedForYearId === yearId) return

    const map = new Map<string, DayState>()
    for (const day of daysQuery.data) {
      map.set(day.date, toDayState(day))
    }
    setDaysMap(map)
    setLoadedForYearId(yearId ?? null)
  }, [daysQuery.data, yearId, loadedForYearId])

  const dates = useMemo(() => (year ? allDatesInRange(year.starts_on, year.ends_on) : []), [year])
  const months = useMemo(() => (year ? monthsInRange(year.starts_on, year.ends_on) : []), [year])
  const weekStartsOn = calendar?.week_starts_on ?? 0

  const getDay = (date: string): DayState => daysMap.get(date) ?? DEFAULT_DAY_STATE

  const updateDay = (date: string, next: DayState) => {
    setDaysMap((prev) => {
      const map = new Map(prev)
      map.set(date, next)
      return map
    })
  }

  const stats = useMemo(() => {
    let workCount = 0
    let offCount = 0
    let publicHolidayCount = 0

    for (const date of dates) {
      const state = getDay(date)
      if (state.schedule_state === 'WORK') workCount += 1
      else offCount += 1
      if (state.is_public_holiday) publicHolidayCount += 1
    }

    return { workCount, offCount, publicHolidayCount, estimatedHours: workCount * hoursPerDay }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dates, daysMap, hoursPerDay])

  if (!yearId) return <p className="text-sm text-muted-foreground">カレンダー年度が見つかりません。</p>

  const handleSyncYear = () => {
    syncYearHolidayCalendar.mutate(yearId, {
      onSuccess: (updatedSource) => {
        if (updatedSource.last_sync_summary) {
          setSyncSummary(updatedSource.last_sync_summary)
        }
        // 同期でサーバ側の日別データ(祝日フラグ・祝日名)が変わるため、編集中のローカル状態を
        // 破棄して再読み込みさせる(そうしないとグリッドが同期前の内容のままになり、保存時に
        // 同期結果を古い内容で上書きしてしまう)。
        setLoadedForYearId(null)
      },
    })
  }

  const hasHolidaySource = Boolean(calendar?.holiday_calendar_source_id)
  const yearActionError =
    publishYear.error ?? unpublishYear.error ?? archiveYear.error ?? duplicateYear.error ?? syncYearHolidayCalendar.error

  const handleSave = () => {
    if (!yearId) return

    putDays.mutate({
      id: yearId,
      days: dates.map((date) => {
        const state = getDay(date)
        return {
          date,
          day_type: deriveDayType(state),
          schedule_state: state.schedule_state,
          is_public_holiday: state.is_public_holiday,
          public_holiday_name: state.is_public_holiday && state.public_holiday_name ? state.public_holiday_name : undefined,
          note: state.note || undefined,
        }
      }),
    })
  }

  return (
    <div className="flex flex-col gap-6">
      {calendar && (
        <div>
          <Link
            to={`/admin/work-calendars/${calendar.id}`}
            className="text-sm text-muted-foreground hover:text-foreground hover:underline"
          >
            ← {calendar.name}に戻る
          </Link>
        </div>
      )}

      {isLoadingYear ? (
        <LoadingState />
      ) : yearError ? (
        <ErrorMessage error={yearError} fallback="カレンダー年度の取得に失敗しました。" />
      ) : !year ? (
        <p className="text-sm text-muted-foreground">カレンダー年度が見つかりません。</p>
      ) : (
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-col gap-1">
              <h1 className="text-lg font-semibold text-foreground">{year.fiscal_year}年度</h1>
              <span className="text-sm text-muted-foreground">
                {year.starts_on}〜{year.ends_on}
              </span>
            </div>
            <Badge tone={YEAR_STATUS_TONE[year.status]}>{YEAR_STATUS_LABEL[year.status]}</Badge>
          </div>

          {yearActionError && <ErrorMessage error={yearActionError} />}

          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              isLoading={syncYearHolidayCalendar.isPending}
              disabled={!hasHolidaySource || syncYearHolidayCalendar.isPending}
              onClick={handleSyncYear}
            >
              この年度を祝日と同期する
            </Button>
            {year.status === 'draft' && (
              <Button variant="secondary" isLoading={publishYear.isPending} onClick={() => publishYear.mutate(year.id)}>
                公開する
              </Button>
            )}
            {year.status === 'published' && (
              <Button
                variant="secondary"
                isLoading={unpublishYear.isPending}
                onClick={() => unpublishYear.mutate(year.id)}
              >
                公開を取消す
              </Button>
            )}
            {year.status !== 'archived' && (
              <Button variant="danger" isLoading={archiveYear.isPending} onClick={() => archiveYear.mutate(year.id)}>
                廃止する
              </Button>
            )}
            <Button variant="secondary" isLoading={duplicateYear.isPending} onClick={() => duplicateYear.mutate(year.id)}>
              複製して翌年度を作成
            </Button>
          </div>

          {!hasHolidaySource && calendar && (
            <p className="text-xs text-muted-foreground">
              祝日iCalendarソースが未設定のため、同期は行えません。
              <Link
                to={`/admin/work-calendars/${calendar.id}#${HOLIDAY_SOURCE_MANAGEMENT_ANCHOR}`}
                className="ml-1 underline hover:text-foreground"
              >
                祝日iCalendarソース管理
              </Link>
              から設定してください。
            </p>
          )}

          {syncSummary && <p className="text-sm text-foreground">{formatSyncSummary(syncSummary)}</p>}
        </div>
      )}

      <Card title="出勤日数・時間(概算)">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">出勤日数</span>
            <span className="text-lg font-semibold text-foreground">{stats.workCount}日</span>
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">休日日数(うち祝日)</span>
            <span className="text-lg font-semibold text-foreground">
              {stats.offCount}日({stats.publicHolidayCount}日)
            </span>
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="hours-per-day" className="text-xs text-muted-foreground">
              1日の所定労働時間(時間)
            </label>
            <Input
              id="hours-per-day"
              type="number"
              min={0}
              step={0.25}
              value={hoursPerDay}
              onChange={(e) => setHoursPerDay(Number(e.target.value) || 0)}
            />
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">想定総労働時間(概算)</span>
            <span className="text-lg font-semibold text-foreground">{stats.estimatedHours}時間</span>
          </div>
        </div>
        <p className="mt-3 text-xs text-muted-foreground">
          この数値はこの画面だけで計算する概算値であり、保存されません。実際の労働時間・残業等の計算は勤怠管理APIが行います。
        </p>
      </Card>

      <Card title="カレンダー年度の日別編集">
        {(daysQuery.error || putDays.error) && <ErrorMessage error={daysQuery.error ?? putDays.error} />}

        {daysQuery.isLoading ? (
          <LoadingState />
        ) : (
          <div className="flex flex-col gap-6">
            {months.map((yearMonth) => {
              const weeks = buildMonthWeeks(yearMonth, weekStartsOn)
              const [y, m] = yearMonth.split('-').map(Number)

              return (
                <div key={yearMonth} className="flex flex-col gap-2">
                  <h3 className="text-sm font-medium text-foreground">
                    {y}年{m}月
                  </h3>
                  <div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">
                    {orderedWeekdayLabels(weekStartsOn).map((label) => (
                      <div key={label} className="py-1">
                        {label}
                      </div>
                    ))}
                  </div>
                  <div className="flex flex-col gap-1">
                    {weeks.map((week, weekIndex) => (
                      // eslint-disable-next-line react/no-array-index-key
                      <div key={weekIndex} className="grid grid-cols-7 gap-1">
                        {week.map((date, dayIndex) =>
                          date === null ? (
                            // eslint-disable-next-line react/no-array-index-key
                            <div key={dayIndex} />
                          ) : (
                            <DayCell
                              key={date}
                              date={date}
                              state={getDay(date)}
                              inRange={year ? date >= year.starts_on && date <= year.ends_on : true}
                              onChange={(next) => updateDay(date, next)}
                            />
                          ),
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )
            })}

            {months.length === 0 && (
              <p className="text-sm text-muted-foreground">カレンダー年度の期間が確認できません。</p>
            )}

            <div className="flex justify-end">
              <Button isLoading={putDays.isPending} disabled={dates.length === 0} onClick={handleSave}>
                保存する
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
