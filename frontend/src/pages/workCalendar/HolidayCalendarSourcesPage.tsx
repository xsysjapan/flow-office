import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Input } from '../../components/ui/input'
import {
  useCreateHolidayCalendarSource,
  useDisableHolidayCalendarSource,
  useHolidayCalendarSourcesList,
  useSyncHolidayCalendarSource,
} from '../../hooks/useHolidayCalendarSources'

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

/**
 * UC-C012: 祝日iCalendarソースの登録・手動同期・無効化。backendに一覧取得APIが無いため、
 * このページを開いている間に登録・同期・無効化した結果を一覧として表示する
 * (`useHolidayCalendarSourcesList`参照)。会社カレンダー本体への割り当ては
 * `WorkCalendarListPage`側で行う。
 */
export function HolidayCalendarSourcesPage() {
  const { data } = useHolidayCalendarSourcesList()
  const createSource = useCreateHolidayCalendarSource()
  const syncSource = useSyncHolidayCalendarSource()
  const disableSource = useDisableHolidayCalendarSource()

  const [name, setName] = useState('')
  const [icsUrl, setIcsUrl] = useState('')

  const sources = data ?? []
  const actionError = createSource.error ?? syncSource.error ?? disableSource.error

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

  return (
    <div className="flex flex-col gap-6">
      <Card title="祝日iCalendarソースを登録">
        {actionError && <ErrorMessage error={actionError} />}

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
      </Card>

      <Card title="祝日iCalendarソース一覧">
        {sources.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            まだ登録されていません。このページで登録したソースがここに表示されます。
          </p>
        ) : (
          <ul className="divide-y divide-border">
            {sources.map((source) => (
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

                <Badge tone={source.disabled_at ? 'danger' : SYNC_STATUS_TONE[source.sync_status] ?? 'neutral'}>
                  {source.disabled_at ? '無効化済み' : SYNC_STATUS_LABEL[source.sync_status] ?? source.sync_status}
                </Badge>

                {!source.disabled_at && (
                  <div className="flex gap-2">
                    <Button
                      variant="secondary"
                      isLoading={syncSource.isPending}
                      onClick={() => syncSource.mutate(source.id)}
                    >
                      今すぐ同期
                    </Button>
                    <Button
                      variant="danger"
                      isLoading={disableSource.isPending}
                      onClick={() => disableSource.mutate(source.id)}
                    >
                      無効化する
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
