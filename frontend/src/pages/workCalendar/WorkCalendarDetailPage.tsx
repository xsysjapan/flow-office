import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import type {
  HolidayCalendarSyncSummary,
  WeekdayHolidayPattern,
  WeekdayHolidayPatternDayType,
  WorkCalendarYearStatus,
} from '../../api/types'
import {
  useCreateHolidayCalendarSource,
  useDeleteHolidayCalendarSource,
  useDisableHolidayCalendarSource,
  useHolidayCalendarSources,
  useRevertLastHolidayCalendarSync,
  useSyncHolidayCalendarSource,
  useUpdateHolidayCalendarSource,
} from '../../hooks/useHolidayCalendarSources'
import {
  useCreateWorkCalendarYear,
  useSetDefaultWorkCalendar,
  useUpdateWorkCalendar,
  useWorkCalendarYears,
  useWorkCalendars,
} from '../../hooks/useWorkCalendars'

const SYNC_STATUS_LABEL: Record<string, string> = {
  pending: '未同期',
  synced: '同期済み',
  failed: '同期失敗',
}

const SYNC_STATUS_TONE: Record<string, 'neutral' | 'success' | 'danger'> = {
  pending: 'neutral',
  synced: 'success',
  failed: 'danger',
}

const SOURCE_KIND_LABEL: Record<string, string> = {
  url: 'URL',
  upload: 'アップロード',
}

const ICS_FILE_ACCEPT = '.ics,.ical,.ifb'

/** ドロップダウンの「登録しない」選択肢の値。空文字はNativeSelectのvalueとして扱いやすいので採用する。 */
const NONE_OPTION_VALUE = ''

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

const WEEKDAY_KEYS: (keyof WeekdayHolidayPattern)[] = ['1', '2', '3', '4', '5', '6', '7']

const WEEKDAY_LABELS: Record<keyof WeekdayHolidayPattern, string> = {
  '1': '月曜日',
  '2': '火曜日',
  '3': '水曜日',
  '4': '木曜日',
  '5': '金曜日',
  '6': '土曜日',
  '7': '日曜日',
}

const DAY_TYPE_OPTIONS: { value: WeekdayHolidayPatternDayType; label: string }[] = [
  { value: 'working', label: '勤務日' },
  { value: 'company_holiday', label: '所定休日' },
  { value: 'legal_holiday', label: '法定休日' },
]

function formatDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

/**
 * カレンダー本体の年度開始月日(システム設定)から、入力した年度番号(例: 2026)に対応する
 * 開始日・終了日を計算する(例: 開始月日が4/1なら2026-04-01〜2027-03-31)。
 */
export function calculateFiscalYearRange(
  fiscalYear: number,
  fiscalYearStartMonth: number,
  fiscalYearStartDay: number,
): { startsOn: string; endsOn: string } {
  const startsOn = new Date(fiscalYear, fiscalYearStartMonth - 1, fiscalYearStartDay)
  const endsOn = new Date(fiscalYear + 1, fiscalYearStartMonth - 1, fiscalYearStartDay)
  endsOn.setDate(endsOn.getDate() - 1)

  return { startsOn: formatDate(startsOn), endsOn: formatDate(endsOn) }
}

function formatSyncSummary(summary: HolidayCalendarSyncSummary): string {
  return `追加 ${summary.added}件・更新 ${summary.updated}件・削除 ${summary.removed}件・カレンダーに反映 ${summary.applied}件(手動変更保護のためスキップ ${summary.protected_conflicts}件)`
}

/**
 * UC-C009/UC-C012: 会社カレンダー本体1件分の基本設定(名称・週起算曜日・年度開始月日)、
 * カレンダー年度の作成・公開・複製(旧WorkCalendarYearsPageを統合)、祝日iCalendar同期を
 * まとめて行う詳細画面。デフォルト切替はページ見出し右上に配置し、祝日iCalendar同期は
 * 「ソースの登録・割当」(このカードで行う)と「年度ごとの同期実行」(年度一覧の各行で行う)に
 * 役割を分離している。年度ごとの同期はその年度の期間だけを対象にするため、カレンダー全体を
 * 一括で同期していた旧仕様(意図しない年度まで同期されてしまう)の課題に対応する。
 */
export function WorkCalendarDetailPage() {
  const { id: companyCalendarId } = useParams<{ id: string }>()
  const { data: calendars, isLoading, error } = useWorkCalendars()
  const updateCalendar = useUpdateWorkCalendar()
  const setDefaultCalendar = useSetDefaultWorkCalendar()

  const { data: sourcesData, isLoading: isLoadingSources, error: sourcesError } = useHolidayCalendarSources()
  const createSource = useCreateHolidayCalendarSource()
  const updateSource = useUpdateHolidayCalendarSource()
  const syncSource = useSyncHolidayCalendarSource()
  const disableSource = useDisableHolidayCalendarSource()
  const revertLastSync = useRevertLastHolidayCalendarSync()
  const deleteSource = useDeleteHolidayCalendarSource()

  const {
    data: years,
    isLoading: isLoadingYears,
    error: yearsError,
  } = useWorkCalendarYears(companyCalendarId ?? '')
  const createYear = useCreateWorkCalendarYear(companyCalendarId ?? '')

  const calendar = calendars?.find((c) => c.id === companyCalendarId)
  const sources = sourcesData ?? []
  const yearList = years ?? []

  const [name, setName] = useState('')
  const [weekStartsOn, setWeekStartsOn] = useState('')
  const [fiscalYearStartMonth, setFiscalYearStartMonth] = useState('')
  const [fiscalYearStartDay, setFiscalYearStartDay] = useState('')
  const [allowDailyHolidayOverride, setAllowDailyHolidayOverride] = useState(true)
  const [isWeekdayPatternOpen, setIsWeekdayPatternOpen] = useState(false)
  const [weekdayPattern, setWeekdayPattern] = useState<WeekdayHolidayPattern | null>(null)

  useEffect(() => {
    if (!calendar) return
    setName(calendar.name)
    setWeekStartsOn(String(calendar.week_starts_on))
    setFiscalYearStartMonth(String(calendar.fiscal_year_start_month))
    setFiscalYearStartDay(String(calendar.fiscal_year_start_day))
    setAllowDailyHolidayOverride(calendar.allow_daily_holiday_override)
    setIsWeekdayPatternOpen(false)
    setWeekdayPattern(calendar.weekday_holiday_pattern)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [calendar?.id])

  const [selectedSourceId, setSelectedSourceId] = useState<string>(NONE_OPTION_VALUE)

  useEffect(() => {
    if (!calendar) return
    setSelectedSourceId(calendar.holiday_calendar_source_id ?? NONE_OPTION_VALUE)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [calendar?.id])

  const [isRegisteringSource, setIsRegisteringSource] = useState(false)
  const [newSourceName, setNewSourceName] = useState('')
  const [newSourceMode, setNewSourceMode] = useState<'url' | 'upload'>('url')
  const [newSourceIcsUrl, setNewSourceIcsUrl] = useState('')
  const [newSourceIcsFile, setNewSourceIcsFile] = useState<File | undefined>(undefined)

  const [isEditingSource, setIsEditingSource] = useState(false)
  const [editSourceName, setEditSourceName] = useState('')
  const [editSourceMode, setEditSourceMode] = useState<'url' | 'upload'>('url')
  const [editSourceIcsUrl, setEditSourceIcsUrl] = useState('')
  const [editSourceIcsFile, setEditSourceIcsFile] = useState<File | undefined>(undefined)

  const [isYearModalOpen, setIsYearModalOpen] = useState(false)
  const [fiscalYear, setFiscalYear] = useState('')
  const [yearStartsOn, setYearStartsOn] = useState('')
  const [yearEndsOn, setYearEndsOn] = useState('')

  if (!companyCalendarId) return <p className="text-sm text-muted-foreground">カレンダーが見つかりません。</p>
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />
  if (!calendar) return <p className="text-sm text-muted-foreground">カレンダーが見つかりません。</p>

  const handleOpenWeekdayPattern = () => {
    setIsWeekdayPatternOpen(true)
  }

  const handleSaveSettings = () => {
    updateCalendar.mutate({
      id: calendar.id,
      input: {
        name,
        week_starts_on: Number(weekStartsOn),
        fiscal_year_start_month: Number(fiscalYearStartMonth),
        fiscal_year_start_day: Number(fiscalYearStartDay),
        holiday_calendar_source_id: calendar.holiday_calendar_source_id,
        weekday_holiday_pattern: isWeekdayPatternOpen && weekdayPattern ? weekdayPattern : undefined,
        allow_daily_holiday_override: allowDailyHolidayOverride,
      },
    })
  }

  const handleCreateSource = () => {
    createSource.mutate(
      {
        name: newSourceName,
        ics_url: newSourceMode === 'url' ? newSourceIcsUrl : undefined,
        ics_file: newSourceMode === 'upload' ? newSourceIcsFile : undefined,
      },
      {
        onSuccess: (created) => {
          setNewSourceName('')
          setNewSourceMode('url')
          setNewSourceIcsUrl('')
          setNewSourceIcsFile(undefined)
          setIsRegisteringSource(false)
          setSelectedSourceId(created.id)
        },
      },
    )
  }

  const handleStartEditSource = () => {
    if (!selectedSource) return
    setEditSourceName(selectedSource.name)
    setEditSourceMode(selectedSource.source_kind)
    setEditSourceIcsUrl(selectedSource.ics_url ?? '')
    setEditSourceIcsFile(undefined)
    setIsEditingSource(true)
  }

  const handleUpdateSource = () => {
    if (!selectedSource) return
    updateSource.mutate(
      {
        id: selectedSource.id,
        input: {
          name: editSourceName,
          ics_url: editSourceMode === 'url' ? editSourceIcsUrl : undefined,
          ics_file: editSourceMode === 'upload' ? editSourceIcsFile : undefined,
        },
      },
      {
        onSuccess: () => {
          setIsEditingSource(false)
        },
      },
    )
  }

  const handleAssignSource = () => {
    if (selectedSourceId === (calendar.holiday_calendar_source_id ?? NONE_OPTION_VALUE)) return

    updateCalendar.mutate({
      id: calendar.id,
      input: {
        name: calendar.name,
        week_starts_on: calendar.week_starts_on,
        fiscal_year_start_month: calendar.fiscal_year_start_month,
        fiscal_year_start_day: calendar.fiscal_year_start_day,
        holiday_calendar_source_id: selectedSourceId || null,
      },
    })
  }

  const handleFiscalYearChange = (value: string) => {
    setFiscalYear(value)

    const parsed = Number(value)
    if (!value || !Number.isInteger(parsed)) return

    const range = calculateFiscalYearRange(parsed, calendar.fiscal_year_start_month, calendar.fiscal_year_start_day)
    setYearStartsOn(range.startsOn)
    setYearEndsOn(range.endsOn)
  }

  const handleCreateYear = () => {
    createYear.mutate(
      { fiscal_year: Number(fiscalYear), starts_on: yearStartsOn, ends_on: yearEndsOn },
      {
        onSuccess: () => {
          setFiscalYear('')
          setYearStartsOn('')
          setYearEndsOn('')
          setIsYearModalOpen(false)
        },
      },
    )
  }

  const handleYearModalOpenChange = (next: boolean) => {
    setIsYearModalOpen(next)
    if (next) {
      setFiscalYear('')
      setYearStartsOn('')
      setYearEndsOn('')
      createYear.reset()
    }
  }

  const selectedSource = sources.find((s) => s.id === selectedSourceId) ?? null
  const summary = selectedSource?.last_sync_summary ?? null
  const sourceActionError =
    createSource.error ??
    updateSource.error ??
    syncSource.error ??
    disableSource.error ??
    revertLastSync.error ??
    deleteSource.error ??
    updateCalendar.error

  const handleDeleteSource = (id: string) => {
    if (!window.confirm('この祝日iCalendarソースを削除します。よろしいですか?')) return
    deleteSource.mutate(id, {
      onSuccess: () => {
        setSelectedSourceId(NONE_OPTION_VALUE)
      },
    })
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link to="/admin/work-calendars" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
          ← 会社カレンダー一覧に戻る
        </Link>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold text-foreground">{calendar.name}</h1>
        <div className="flex items-center gap-3">
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
        </div>
      </div>

      <Card title="基本設定">
        {updateCalendar.error && !sourceActionError && <ErrorMessage error={updateCalendar.error} />}

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

          {!isWeekdayPatternOpen ? (
            <Button variant="secondary" onClick={handleOpenWeekdayPattern}>
              曜日ごとの休日設定を変更する
            </Button>
          ) : (
            <div className="flex flex-col gap-3 rounded-md border border-border p-4">
              <p className="text-xs text-muted-foreground">
                未変更の曜日は既定値のままです。ここで設定した内容がこのカレンダーの曜日ごとの休日区分になります。
              </p>
              <label className="flex items-center gap-2 text-sm text-foreground">
                <Checkbox
                  aria-label="曜日ごとの休日設定を日ごとに個別変更できるようにする"
                  checked={allowDailyHolidayOverride}
                  onCheckedChange={(checked) => setAllowDailyHolidayOverride(checked === true)}
                />
                曜日ごとの休日設定を日ごとに個別変更できるようにする
              </label>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {WEEKDAY_KEYS.map((weekdayKey) => (
                  <FormField
                    key={weekdayKey}
                    label={WEEKDAY_LABELS[weekdayKey]}
                    htmlFor={`company-calendar-weekday-pattern-${weekdayKey}`}
                  >
                    <NativeSelect
                      id={`company-calendar-weekday-pattern-${weekdayKey}`}
                      value={weekdayPattern?.[weekdayKey] ?? 'working'}
                      onChange={(e) =>
                        setWeekdayPattern((prev) => ({
                          ...(prev ?? calendar.weekday_holiday_pattern),
                          [weekdayKey]: e.target.value as WeekdayHolidayPatternDayType,
                        }))
                      }
                    >
                      {DAY_TYPE_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </NativeSelect>
                  </FormField>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="mt-4 flex justify-end">
          <Button isLoading={updateCalendar.isPending} disabled={!name} onClick={handleSaveSettings}>
            保存する
          </Button>
        </div>
      </Card>

      <Card
        title="カレンダー年度"
        actions={<Button onClick={() => handleYearModalOpenChange(true)}>新規作成</Button>}
      >
        <Dialog open={isYearModalOpen} onOpenChange={handleYearModalOpenChange}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>カレンダー年度を作成</DialogTitle>
            </DialogHeader>

            {createYear.error && <ErrorMessage error={createYear.error} />}

            <div className="flex flex-col gap-4">
              <FormField label="年度" htmlFor="year-fiscal-year" required>
                <Input
                  id="year-fiscal-year"
                  type="number"
                  value={fiscalYear}
                  onChange={(e) => handleFiscalYearChange(e.target.value)}
                />
              </FormField>

              <FormField label="開始日" htmlFor="year-starts-on" required>
                <DatePicker
                  id="year-starts-on"
                  value={yearStartsOn || undefined}
                  onChange={(date) => setYearStartsOn(date ?? '')}
                />
              </FormField>

              <FormField label="終了日" htmlFor="year-ends-on" required>
                <DatePicker
                  id="year-ends-on"
                  value={yearEndsOn || undefined}
                  onChange={(date) => setYearEndsOn(date ?? '')}
                />
              </FormField>
            </div>

            <p className="text-xs text-muted-foreground">
              年度を入力すると、このカレンダーの年度開始月日(設定は「基本設定」から変更できます)から開始日・終了日を自動計算します。開始日・終了日は個別に変更できます。
            </p>

            <div className="flex flex-wrap gap-3">
              <Button
                isLoading={createYear.isPending}
                disabled={!fiscalYear || !yearStartsOn || !yearEndsOn}
                onClick={handleCreateYear}
              >
                年度を作成する
              </Button>
              <Button variant="secondary" onClick={() => handleYearModalOpenChange(false)}>
                キャンセル
              </Button>
            </div>
          </DialogContent>
        </Dialog>

        {isLoadingYears ? (
          <LoadingState />
        ) : yearsError ? (
          <ErrorMessage error={yearsError} fallback="カレンダー年度一覧の取得に失敗しました。" />
        ) : (
          <div className="flex flex-col gap-6">
            {yearList.length === 0 ? (
              <p className="text-sm text-muted-foreground">年度はまだありません。</p>
            ) : (
              <ul className="divide-y divide-border">
                {yearList.map((year) => (
                  <li key={year.id} className="flex flex-wrap items-center gap-3 py-3">
                    <div className="flex flex-1 flex-col gap-1">
                      <Link
                        to={`/admin/work-calendars/${calendar.id}/years/${year.id}/days`}
                        className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                      >
                        {year.fiscal_year}年度
                      </Link>
                      <span className="text-sm text-muted-foreground">
                        {year.starts_on}〜{year.ends_on}
                      </span>
                    </div>
                    <Badge tone={YEAR_STATUS_TONE[year.status]}>{YEAR_STATUS_LABEL[year.status]}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </Card>

      <Card id={HOLIDAY_SOURCE_MANAGEMENT_ANCHOR} title="祝日iCalendarソース管理">
        {sourceActionError && <ErrorMessage error={sourceActionError} />}

        {isLoadingSources ? (
          <LoadingState />
        ) : sourcesError ? (
          <ErrorMessage error={sourcesError} fallback="祝日iCalendarソース一覧の取得に失敗しました。" />
        ) : (
          <div className="flex flex-col gap-4">
            <FormField label="使用する祝日iCalendarソース" htmlFor="holiday-source-select">
              <NativeSelect
                id="holiday-source-select"
                value={selectedSourceId}
                onChange={(e) => setSelectedSourceId(e.target.value)}
              >
                <option value={NONE_OPTION_VALUE}>登録しない</option>
                {sources.map((source) => (
                  <option key={source.id} value={source.id}>
                    {source.name}
                  </option>
                ))}
              </NativeSelect>
            </FormField>

            {!isRegisteringSource ? (
              <Button variant="secondary" onClick={() => setIsRegisteringSource(true)}>
                新しいiCalendarを登録する
              </Button>
            ) : (
              <div className="flex flex-col gap-4 rounded-md border border-border p-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <FormField label="名称" htmlFor="holiday-source-name" required>
                    <Input
                      id="holiday-source-name"
                      value={newSourceName}
                      onChange={(e) => setNewSourceName(e.target.value)}
                    />
                  </FormField>

                  <div className="flex flex-col gap-2">
                    <span className="text-sm font-medium text-foreground">登録方法</span>
                    <div className="flex gap-4">
                      <label className="flex items-center gap-2 text-sm text-foreground">
                        <input
                          type="radio"
                          name="holiday-source-mode"
                          checked={newSourceMode === 'url'}
                          onChange={() => setNewSourceMode('url')}
                        />
                        URLで登録
                      </label>
                      <label className="flex items-center gap-2 text-sm text-foreground">
                        <input
                          type="radio"
                          name="holiday-source-mode"
                          checked={newSourceMode === 'upload'}
                          onChange={() => setNewSourceMode('upload')}
                        />
                        ファイルをアップロード
                      </label>
                    </div>
                  </div>
                </div>

                {newSourceMode === 'url' ? (
                  <FormField label="iCalendar URL" htmlFor="holiday-source-ics-url" required>
                    <Input
                      id="holiday-source-ics-url"
                      type="url"
                      value={newSourceIcsUrl}
                      onChange={(e) => setNewSourceIcsUrl(e.target.value)}
                    />
                  </FormField>
                ) : (
                  <FormField label="iCalendarファイル" htmlFor="holiday-source-ics-file" required>
                    <input
                      id="holiday-source-ics-file"
                      type="file"
                      accept={ICS_FILE_ACCEPT}
                      onChange={(e) => setNewSourceIcsFile(e.target.files?.[0])}
                    />
                  </FormField>
                )}

                <div className="flex justify-end gap-2">
                  <Button variant="secondary" onClick={() => setIsRegisteringSource(false)}>
                    キャンセル
                  </Button>
                  <Button
                    isLoading={createSource.isPending}
                    disabled={!newSourceName || (newSourceMode === 'url' ? !newSourceIcsUrl : !newSourceIcsFile)}
                    onClick={handleCreateSource}
                  >
                    登録する
                  </Button>
                </div>
              </div>
            )}

            {selectedSourceId !== (calendar.holiday_calendar_source_id ?? NONE_OPTION_VALUE) && (
              <div className="flex justify-end">
                <Button isLoading={updateCalendar.isPending} onClick={handleAssignSource}>
                  このカレンダーに設定する
                </Button>
              </div>
            )}

            {selectedSource && (
              <div className="flex flex-col gap-3 rounded-md border border-border p-4">
                <div className="flex flex-wrap items-center gap-3">
                  <span className="text-sm font-medium text-foreground">{selectedSource.name}</span>
                  <Badge
                    tone={
                      selectedSource.disabled_at
                        ? 'danger'
                        : SYNC_STATUS_TONE[selectedSource.sync_status] ?? 'neutral'
                    }
                  >
                    {selectedSource.disabled_at
                      ? '無効化済み'
                      : SYNC_STATUS_LABEL[selectedSource.sync_status] ?? selectedSource.sync_status}
                  </Badge>
                </div>
                <span className="text-xs text-muted-foreground">
                  種別: {SOURCE_KIND_LABEL[selectedSource.source_kind] ?? selectedSource.source_kind}
                  {selectedSource.source_kind === 'upload'
                    ? `(${selectedSource.uploaded_ics_filename ?? '未アップロード'})`
                    : `(${selectedSource.ics_url ?? ''})`}
                </span>
                <span className="text-xs text-muted-foreground">
                  最終同期: {selectedSource.last_synced_at ?? '未同期'}
                </span>
                {selectedSource.last_error && (
                  <span className="text-xs text-destructive">エラー: {selectedSource.last_error}</span>
                )}

                {summary && (
                  <p className="text-sm text-foreground">{formatSyncSummary(summary)}</p>
                )}
                {updateSource.isSuccess && (
                  <span className="text-xs text-muted-foreground">変更を反映するには同期してください。</span>
                )}

                {!isEditingSource ? (
                  <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={handleStartEditSource}>
                      編集
                    </Button>
                    {!selectedSource.disabled_at && (
                      <>
                        {selectedSource.last_synced_at && (
                          <Button
                            variant="secondary"
                            isLoading={revertLastSync.isPending}
                            onClick={() => revertLastSync.mutate(selectedSource.id)}
                          >
                            直前の同期を取消す
                          </Button>
                        )}
                        <Button
                          variant="danger"
                          isLoading={disableSource.isPending}
                          onClick={() => disableSource.mutate(selectedSource.id)}
                        >
                          無効化する
                        </Button>
                      </>
                    )}
                    <Button
                      variant="danger"
                      isLoading={deleteSource.isPending}
                      onClick={() => handleDeleteSource(selectedSource.id)}
                    >
                      削除する
                    </Button>
                  </div>
                ) : (
                  <div className="flex flex-col gap-4 rounded-md border border-border p-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                      <FormField label="名称" htmlFor="holiday-source-edit-name" required>
                        <Input
                          id="holiday-source-edit-name"
                          value={editSourceName}
                          onChange={(e) => setEditSourceName(e.target.value)}
                        />
                      </FormField>

                      <div className="flex flex-col gap-2">
                        <span className="text-sm font-medium text-foreground">登録方法</span>
                        <div className="flex gap-4">
                          <label className="flex items-center gap-2 text-sm text-foreground">
                            <input
                              type="radio"
                              name="holiday-source-edit-mode"
                              checked={editSourceMode === 'url'}
                              onChange={() => setEditSourceMode('url')}
                            />
                            URLで登録
                          </label>
                          <label className="flex items-center gap-2 text-sm text-foreground">
                            <input
                              type="radio"
                              name="holiday-source-edit-mode"
                              checked={editSourceMode === 'upload'}
                              onChange={() => setEditSourceMode('upload')}
                            />
                            ファイルをアップロード
                          </label>
                        </div>
                      </div>
                    </div>

                    {editSourceMode === 'url' ? (
                      <FormField label="iCalendar URL" htmlFor="holiday-source-edit-ics-url" required>
                        <Input
                          id="holiday-source-edit-ics-url"
                          type="url"
                          value={editSourceIcsUrl}
                          onChange={(e) => setEditSourceIcsUrl(e.target.value)}
                        />
                      </FormField>
                    ) : (
                      <div className="flex flex-col gap-2">
                        <span className="text-xs text-muted-foreground">
                          現在のファイル: {selectedSource.uploaded_ics_filename ?? '未アップロード'}
                        </span>
                        <FormField label="iCalendarファイル(置き換え)" htmlFor="holiday-source-edit-ics-file">
                          <input
                            id="holiday-source-edit-ics-file"
                            type="file"
                            accept={ICS_FILE_ACCEPT}
                            onChange={(e) => setEditSourceIcsFile(e.target.files?.[0])}
                          />
                        </FormField>
                      </div>
                    )}

                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" onClick={() => setIsEditingSource(false)}>
                        キャンセル
                      </Button>
                      <Button
                        isLoading={updateSource.isPending}
                        disabled={
                          !editSourceName || (editSourceMode === 'url' ? !editSourceIcsUrl : !editSourceIcsFile)
                        }
                        onClick={handleUpdateSource}
                      >
                        更新する
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </Card>
    </div>
  )
}
