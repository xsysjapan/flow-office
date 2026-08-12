import { useState } from 'react'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { LoadingState } from '../LoadingState/LoadingState'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../ui/dialog'
import { Input } from '../ui/input'
import {
  useCreateHolidayCalendarSource,
  useDisableHolidayCalendarSource,
  useHolidayCalendarSources,
  useRevertLastHolidayCalendarSync,
  useSyncHolidayCalendarSource,
} from '../../hooks/useHolidayCalendarSources'
import { useUpdateWorkCalendar } from '../../hooks/useWorkCalendars'
import type { WorkCalendar } from '../../api/types'

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

interface HolidayCalendarSourceModalProps {
  companyCalendar: WorkCalendar
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * UC-C012: 祝日iCalendarソースの登録・手動同期・無効化・直前同期の取消、および
 * このカレンダー本体への割当を、会社カレンダー一覧のサブ画面(モーダル)から行う。
 */
export function HolidayCalendarSourceModal({ companyCalendar, open, onOpenChange }: HolidayCalendarSourceModalProps) {
  const { data, isLoading, error } = useHolidayCalendarSources()
  const createSource = useCreateHolidayCalendarSource()
  const syncSource = useSyncHolidayCalendarSource()
  const disableSource = useDisableHolidayCalendarSource()
  const revertLastSync = useRevertLastHolidayCalendarSync()
  const updateCalendar = useUpdateWorkCalendar()

  const [name, setName] = useState('')
  const [icsUrl, setIcsUrl] = useState('')

  const sources = data ?? []
  const actionError =
    createSource.error ?? syncSource.error ?? disableSource.error ?? revertLastSync.error ?? updateCalendar.error

  const handleCreate = () => {
    createSource.mutate(
      { name, ics_url: icsUrl },
      {
        onSuccess: () => {
          setName('')
          setIcsUrl('')
        },
      },
    )
  }

  const handleAssign = (sourceId: string | null) => {
    updateCalendar.mutate({
      id: companyCalendar.id,
      input: {
        name: companyCalendar.name,
        week_starts_on: companyCalendar.week_starts_on,
        fiscal_year_start_month: companyCalendar.fiscal_year_start_month,
        fiscal_year_start_day: companyCalendar.fiscal_year_start_day,
        holiday_calendar_source_id: sourceId,
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{companyCalendar.name} の祝日iCalendar同期</DialogTitle>
        </DialogHeader>

        {actionError && <ErrorMessage error={actionError} />}

        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="祝日iCalendarソース一覧の取得に失敗しました。" />
        ) : (
          <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-4">
              <h3 className="text-sm font-medium text-foreground">新しいソースを登録</h3>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="名称" htmlFor="holiday-source-name" required>
                  <Input id="holiday-source-name" value={name} onChange={(e) => setName(e.target.value)} />
                </FormField>

                <FormField label="iCalendar URL" htmlFor="holiday-source-ics-url" required>
                  <Input
                    id="holiday-source-ics-url"
                    type="url"
                    value={icsUrl}
                    onChange={(e) => setIcsUrl(e.target.value)}
                  />
                </FormField>
              </div>

              <Button isLoading={createSource.isPending} disabled={!name || !icsUrl} onClick={handleCreate}>
                登録する
              </Button>
            </div>

            <div className="flex flex-col gap-2">
              <h3 className="text-sm font-medium text-foreground">ソース一覧</h3>
              {sources.length === 0 ? (
                <p className="text-sm text-muted-foreground">まだ登録されていません。</p>
              ) : (
                <ul className="divide-y divide-border">
                  {sources.map((source) => {
                    const isAssigned = companyCalendar.holiday_calendar_source_id === source.id

                    return (
                      <li key={source.id} className="flex flex-wrap items-center gap-3 py-3">
                        <div className="flex flex-1 flex-col">
                          <span className="text-sm font-medium text-foreground">{source.name}</span>
                          <span className="text-sm text-muted-foreground">{source.ics_url}</span>
                          <span className="text-xs text-muted-foreground">
                            最終同期: {source.last_synced_at ?? '未同期'}
                          </span>
                          {source.last_error && (
                            <span className="text-xs text-destructive">エラー: {source.last_error}</span>
                          )}
                        </div>

                        {isAssigned && <Badge tone="success">このカレンダーに設定中</Badge>}
                        <Badge tone={source.disabled_at ? 'danger' : SYNC_STATUS_TONE[source.sync_status] ?? 'neutral'}>
                          {source.disabled_at ? '無効化済み' : SYNC_STATUS_LABEL[source.sync_status] ?? source.sync_status}
                        </Badge>

                        <div className="flex flex-wrap gap-2">
                          {!isAssigned && !source.disabled_at && (
                            <Button
                              variant="secondary"
                              isLoading={updateCalendar.isPending}
                              onClick={() => handleAssign(source.id)}
                            >
                              このカレンダーに設定する
                            </Button>
                          )}
                          {isAssigned && (
                            <Button
                              variant="secondary"
                              isLoading={updateCalendar.isPending}
                              onClick={() => handleAssign(null)}
                            >
                              設定を解除する
                            </Button>
                          )}
                          {!source.disabled_at && (
                            <>
                              <Button
                                variant="secondary"
                                isLoading={syncSource.isPending}
                                onClick={() => syncSource.mutate(source.id)}
                              >
                                今すぐ同期
                              </Button>
                              {source.last_synced_at && (
                                <Button
                                  variant="secondary"
                                  isLoading={revertLastSync.isPending}
                                  onClick={() => revertLastSync.mutate(source.id)}
                                >
                                  直前の同期を取消す
                                </Button>
                              )}
                              <Button
                                variant="danger"
                                isLoading={disableSource.isPending}
                                onClick={() => disableSource.mutate(source.id)}
                              >
                                無効化する
                              </Button>
                            </>
                          )}
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
