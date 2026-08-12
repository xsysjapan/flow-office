import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import {
  useCreateHolidayCalendarSource,
  useDisableHolidayCalendarSource,
  useHolidayCalendarSources,
  useRevertLastHolidayCalendarSync,
  useSyncHolidayCalendarSource,
} from '../../hooks/useHolidayCalendarSources'
import { useSetDefaultWorkCalendar, useUpdateWorkCalendar, useWorkCalendars } from '../../hooks/useWorkCalendars'

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

/** ドロップダウンの「登録しない」選択肢の値。空文字はNativeSelectのvalueとして扱いやすいので採用する。 */
const NONE_OPTION_VALUE = ''

/**
 * UC-C009/UC-C012: 会社カレンダー本体1件分の基本設定(名称・週起算曜日・年度開始月日・
 * デフォルト切替)と祝日iCalendar同期をまとめて行う詳細画面。祝日iCalendar同期は
 * 「ソースを選ぶ→選択して同期する」の1ボタンに単純化している。旧HolidayCalendarSourceModalは
 * 「このカレンダーに設定する」ボタンと「今すぐ同期」ボタンが別々で、同期結果がこのカレンダーに
 * どう反映されたか(反映件数・保護によるスキップ件数)が見えなかったため、その課題に対応する。
 */
export function WorkCalendarDetailPage() {
  const { id: companyCalendarId } = useParams<{ id: string }>()
  const { data: calendars, isLoading, error } = useWorkCalendars()
  const updateCalendar = useUpdateWorkCalendar()
  const setDefaultCalendar = useSetDefaultWorkCalendar()

  const { data: sourcesData, isLoading: isLoadingSources, error: sourcesError } = useHolidayCalendarSources()
  const createSource = useCreateHolidayCalendarSource()
  const syncSource = useSyncHolidayCalendarSource()
  const disableSource = useDisableHolidayCalendarSource()
  const revertLastSync = useRevertLastHolidayCalendarSync()

  const calendar = calendars?.find((c) => c.id === companyCalendarId)
  const sources = sourcesData ?? []

  const [name, setName] = useState('')
  const [weekStartsOn, setWeekStartsOn] = useState('')
  const [fiscalYearStartMonth, setFiscalYearStartMonth] = useState('')
  const [fiscalYearStartDay, setFiscalYearStartDay] = useState('')

  useEffect(() => {
    if (!calendar) return
    setName(calendar.name)
    setWeekStartsOn(String(calendar.week_starts_on))
    setFiscalYearStartMonth(String(calendar.fiscal_year_start_month))
    setFiscalYearStartDay(String(calendar.fiscal_year_start_day))
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
  const [newSourceIcsUrl, setNewSourceIcsUrl] = useState('')

  if (!companyCalendarId) return <p className="text-sm text-muted-foreground">カレンダーが見つかりません。</p>
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="カレンダー一覧の取得に失敗しました。" />
  if (!calendar) return <p className="text-sm text-muted-foreground">カレンダーが見つかりません。</p>

  const handleSaveSettings = () => {
    updateCalendar.mutate({
      id: calendar.id,
      input: {
        name,
        week_starts_on: Number(weekStartsOn),
        fiscal_year_start_month: Number(fiscalYearStartMonth),
        fiscal_year_start_day: Number(fiscalYearStartDay),
        holiday_calendar_source_id: calendar.holiday_calendar_source_id,
      },
    })
  }

  const handleCreateSource = () => {
    createSource.mutate(
      { name: newSourceName, ics_url: newSourceIcsUrl },
      {
        onSuccess: (created) => {
          setNewSourceName('')
          setNewSourceIcsUrl('')
          setIsRegisteringSource(false)
          setSelectedSourceId(created.id)
        },
      },
    )
  }

  const handleSyncClick = () => {
    if (!selectedSourceId) return

    if (selectedSourceId !== calendar.holiday_calendar_source_id) {
      updateCalendar.mutate(
        {
          id: calendar.id,
          input: {
            name: calendar.name,
            week_starts_on: calendar.week_starts_on,
            fiscal_year_start_month: calendar.fiscal_year_start_month,
            fiscal_year_start_day: calendar.fiscal_year_start_day,
            holiday_calendar_source_id: selectedSourceId,
          },
        },
        { onSuccess: () => syncSource.mutate(selectedSourceId) },
      )
    } else {
      syncSource.mutate(selectedSourceId)
    }
  }

  const selectedSource = sources.find((s) => s.id === selectedSourceId) ?? null
  const summary = selectedSource?.last_sync_summary ?? null
  const isSyncBusy = updateCalendar.isPending || syncSource.isPending
  const sourceActionError = createSource.error ?? syncSource.error ?? disableSource.error ?? revertLastSync.error

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link to="/admin/work-calendars" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
          ← 会社カレンダー一覧に戻る
        </Link>
      </div>

      <Card title="基本設定">
        {updateCalendar.error && <ErrorMessage error={updateCalendar.error} />}

        <div className="mb-4 flex items-center gap-3">
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

        <div className="mt-4 flex justify-end">
          <Button isLoading={updateCalendar.isPending} disabled={!name} onClick={handleSaveSettings}>
            保存する
          </Button>
        </div>
      </Card>

      <Card
        title="祝日iCalendar同期"
        navigation={
          <Button variant="secondary" asChild>
            <Link to={`/admin/work-calendars/${calendar.id}/years`}>年度一覧を見る</Link>
          </Button>
        }
      >
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

                  <FormField label="iCalendar URL" htmlFor="holiday-source-ics-url" required>
                    <Input
                      id="holiday-source-ics-url"
                      type="url"
                      value={newSourceIcsUrl}
                      onChange={(e) => setNewSourceIcsUrl(e.target.value)}
                    />
                  </FormField>
                </div>

                <div className="flex justify-end gap-2">
                  <Button variant="secondary" onClick={() => setIsRegisteringSource(false)}>
                    キャンセル
                  </Button>
                  <Button
                    isLoading={createSource.isPending}
                    disabled={!newSourceName || !newSourceIcsUrl}
                    onClick={handleCreateSource}
                  >
                    登録する
                  </Button>
                </div>
              </div>
            )}

            <div className="flex justify-end">
              <Button isLoading={isSyncBusy} disabled={!selectedSourceId || isSyncBusy} onClick={handleSyncClick}>
                選択して同期する
              </Button>
            </div>

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
                  最終同期: {selectedSource.last_synced_at ?? '未同期'}
                </span>
                {selectedSource.last_error && (
                  <span className="text-xs text-destructive">エラー: {selectedSource.last_error}</span>
                )}

                {summary && (
                  <p className="text-sm text-foreground">
                    追加 {summary.added}件・更新 {summary.updated}件・削除 {summary.removed}件・カレンダーに反映{' '}
                    {summary.applied}件(手動変更保護のためスキップ {summary.protected_conflicts}件)
                  </p>
                )}

                {!selectedSource.disabled_at && (
                  <div className="flex flex-wrap gap-2">
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
