import { useState } from 'react'
import { TriangleAlert } from 'lucide-react'
import type { ReadModelRebuildRun, ReadModelRebuildStatus } from '../../api/readModelRebuilds'
import { Badge, type BadgeTone } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import {
  isActiveReadModelRebuild,
  useReadModelRebuild,
  useReadModelRebuilds,
  useRequestReadModelRebuild,
} from '../../hooks/useReadModelRebuilds'

/** 変更があれば警告するテーブル(利用者・権限に関わるもの)。 */
const ACCOUNT_TABLES = ['users', 'memberships', 'groups', 'roles', 'role_assignments', 'permission_role', 'role_features']

const STATUS_LABEL: Record<ReadModelRebuildStatus, string> = {
  queued: '待機中',
  running: '実行中',
  succeeded: '成功',
  failed: '失敗',
}
const STATUS_TONE: Record<ReadModelRebuildStatus, BadgeTone> = {
  queued: 'neutral',
  running: 'info',
  succeeded: 'success',
  failed: 'danger',
}

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('ja-JP') : '—'
}

function RunDetail({ id }: { id: string }) {
  const detail = useReadModelRebuild(id)

  if (detail.isLoading) return <LoadingState />
  if (detail.error) return <ErrorMessage error={detail.error} fallback="結果を取得できませんでした。" />
  const summary = detail.data?.data.summary
  if (!summary) return <p className="text-sm text-muted-foreground">結果はまだありません。</p>

  const tables = [...summary.tables].sort((a, b) => Number(b.changed) - Number(a.changed))
  const accountChanged = summary.tables.some((table) => table.changed && ACCOUNT_TABLES.includes(table.table))

  return (
    <div className="space-y-3">
      {accountChanged && (
        <Alert className="border-warning/30 bg-warning/10 text-warning [&>svg]:text-warning">
          <TriangleAlert />
          <AlertDescription>
            <p>利用者・権限のデータが変わります。本実行の前に内容を確認してください。</p>
          </AlertDescription>
        </Alert>
      )}
      <p className="text-sm text-muted-foreground">変更のあるテーブル: {summary.changed_tables}件 / 全{summary.tables.length}件</p>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>テーブル</TableHead>
            <TableHead>作り直し前の件数</TableHead>
            <TableHead>作り直し後の件数</TableHead>
            <TableHead>変更</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {tables.map((table) => (
            <TableRow key={table.table}>
              <TableCell>{table.table}</TableCell>
              <TableCell>{table.before}</TableCell>
              <TableCell>{table.after}</TableCell>
              <TableCell>{table.changed ? '変更あり' : '—'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function RunRow({ run, isOpen, onToggle }: { run: ReadModelRebuildRun; isOpen: boolean; onToggle: () => void }) {
  return (
    <>
      <TableRow>
        <TableCell>{run.dry_run ? '試し実行' : '本実行'}</TableCell>
        <TableCell><Badge tone={STATUS_TONE[run.status]}>{STATUS_LABEL[run.status]}</Badge></TableCell>
        <TableCell>{run.requested_by?.name ?? '—'}</TableCell>
        <TableCell>{formatDateTime(run.requested_at)}</TableCell>
        <TableCell>{formatDateTime(run.started_at)}</TableCell>
        <TableCell>{formatDateTime(run.finished_at)}</TableCell>
        <TableCell className="max-w-xs whitespace-normal break-words">{run.error_message ?? '—'}</TableCell>
        <TableCell>
          <Button size="sm" variant="secondary" aria-expanded={isOpen} onClick={onToggle}>
            {isOpen ? '閉じる' : '結果を見る'}
          </Button>
        </TableCell>
      </TableRow>
      {isOpen && (
        <TableRow>
          <TableCell colSpan={8}><RunDetail id={run.id} /></TableCell>
        </TableRow>
      )}
    </>
  )
}

export function ReadModelRebuildPage() {
  const runs = useReadModelRebuilds()
  const request = useRequestReadModelRebuild()
  const [openId, setOpenId] = useState<string | null>(null)

  const hasActive = runs.data?.data.some(isActiveReadModelRebuild) ?? false

  return (
    <div className="space-y-6">
      <Card title="データの作り直し">
        <div className="space-y-4">
          <div className="space-y-2 text-sm text-foreground">
            <p>すべてのReadModel(画面表示用のデータ)を、イベント(stored_events)から作り直します。</p>
            <ul className="list-disc space-y-1 pl-5">
              <li>実行中は、すべての利用者がメンテナンス中になり、操作できなくなります。</li>
              <li>所要時間はデータ量によります。</li>
              <li>日次バッチの時刻(1:00 users:sync-ms365、2:00〜2:20 有給・特別休暇の日次処理)を避けて実行してください。実行中に時刻が来たバッチはその日は実行されません。</li>
              <li>作り直しは現在のシステム設定で計算されるため、設定を変えた後は過去の値が変わることがあります。</li>
              <li>実行の開始は、依頼から約1分後です。</li>
              <li>「試し実行」は同じ処理を行い、最後に元へ戻します。件数と変更の有無を確認できます。</li>
            </ul>
          </div>
          {hasActive && (
            <p className="text-sm text-muted-foreground">待機中または実行中の作り直しがあるため、新しく依頼できません。</p>
          )}
          <div className="flex flex-wrap gap-2">
            {hasActive ? (
              <>
                <Button size="sm" variant="secondary" disabled>試し実行</Button>
                <Button size="sm" variant="danger" disabled>作り直す</Button>
              </>
            ) : (
              <>
                <ConfirmActionDialog
                  triggerLabel="試し実行"
                  triggerVariant="secondary"
                  title="試し実行しますか"
                  description="本実行と同じ処理を行い、最後に元へ戻します。実行中はすべての利用者がメンテナンス中になります。"
                  confirmLabel="試し実行する"
                  isPending={request.isPending}
                  error={request.error}
                  onOpenChange={(open) => { if (open) request.reset() }}
                  onConfirm={() => request.mutateAsync(true)}
                />
                <ConfirmActionDialog
                  triggerLabel="作り直す"
                  title="データを作り直しますか"
                  description="すべてのReadModelを空にして、イベントから作り直します。実行中はすべての利用者がメンテナンス中になり、この操作は元に戻せません。"
                  confirmLabel="作り直す"
                  isPending={request.isPending}
                  error={request.error}
                  onOpenChange={(open) => { if (open) request.reset() }}
                  onConfirm={() => request.mutateAsync(false)}
                />
              </>
            )}
          </div>
        </div>
      </Card>
      <Card title="実行履歴">
        {runs.isLoading ? (
          <LoadingState />
        ) : runs.error ? (
          <ErrorMessage error={runs.error} fallback="実行履歴を取得できませんでした。" />
        ) : (runs.data?.data.length ?? 0) === 0 ? (
          <EmptyState title="実行履歴はまだありません。" description="試し実行または作り直しを依頼すると、ここに結果が表示されます。" />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>種別</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>依頼者</TableHead>
                <TableHead>依頼日時</TableHead>
                <TableHead>開始</TableHead>
                <TableHead>終了</TableHead>
                <TableHead>エラー</TableHead>
                <TableHead><span className="sr-only">操作</span></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {runs.data?.data.map((run) => (
                <RunRow key={run.id} run={run} isOpen={openId === run.id} onToggle={() => setOpenId(openId === run.id ? null : run.id)} />
              ))}
            </TableBody>
          </Table>
        )}
      </Card>
    </div>
  )
}
