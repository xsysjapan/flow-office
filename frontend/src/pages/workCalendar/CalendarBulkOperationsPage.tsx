import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Textarea } from '../../components/ui/textarea'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useEditableRows } from '../../hooks/useEditableRows'
import { useWorkStyles } from '../../hooks/useWorkStyles'
import type {
  CalendarBulkOperationConflictPolicy,
  CalendarBulkOperationType,
} from '../../api/types'
import {
  useCalendarBulkOperations,
  useCreateCalendarBulkOperation,
  usePreviewCalendarBulkOperation,
  useRevertCalendarBulkOperation,
} from '../../hooks/useCalendarBulkOperations'

const OPERATION_TYPE_OPTIONS: { value: CalendarBulkOperationType; label: string }[] = [
  { value: 'calendar_apply', label: '会社カレンダー適用' },
  { value: 'rotation_generate', label: 'ローテーション生成' },
  { value: 'bulk_edit', label: '個別指定編集' },
]

const CONFLICT_POLICY_OPTIONS: { value: CalendarBulkOperationConflictPolicy; label: string }[] = [
  { value: 'skip_existing', label: '既存はスキップする' },
  { value: 'overwrite', label: '既存を上書きする' },
  { value: 'fail_on_conflict', label: '競合があれば全体を失敗させる' },
]

const STATUS_LABEL: Record<string, string> = {
  applied: '適用済み',
  reverted: '取消済み',
  failed: '失敗',
}

const STATUS_TONE: Record<string, 'success' | 'neutral' | 'danger'> = {
  applied: 'success',
  reverted: 'neutral',
  failed: 'danger',
}

interface UserRow {
  userId: string
}

interface BulkEditEntryRow {
  userId: string
  workDate: string
  scheduleState: 'WORK' | 'OFF' | 'LEAVE'
}

/**
 * UC-C013/UC-C041/UC-C042: 複数従業員予定の一括操作をプレビュー→確定適用→取消の
 * ウィザード形式で行う。プレビュー内容(対象件数・競合件数・実行可否)と競合ポリシーを
 * そのまま表示する簡潔なUIに留める。
 */
export function CalendarBulkOperationsPage() {
  const { data: workStyles } = useWorkStyles()
  const { data: history, isLoading: isLoadingHistory, error: historyError } = useCalendarBulkOperations()
  const preview = usePreviewCalendarBulkOperation()
  const apply = useCreateCalendarBulkOperation()
  const revert = useRevertCalendarBulkOperation()

  const [operationType, setOperationType] = useState<CalendarBulkOperationType>('calendar_apply')
  const [conflictPolicy, setConflictPolicy] = useState<CalendarBulkOperationConflictPolicy>('skip_existing')
  const [reason, setReason] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [workStyleId, setWorkStyleId] = useState('')

  const { rows: userRows, addRow: addUserRow, updateRow: updateUserRow, removeRow: removeUserRow } =
    useEditableRows<UserRow>([{ userId: '' }])
  const {
    rows: entryRows,
    addRow: addEntryRow,
    updateRow: updateEntryRow,
    removeRow: removeEntryRow,
  } = useEditableRows<BulkEditEntryRow>([{ userId: '', workDate: '', scheduleState: 'WORK' }])

  const isEntryBased = operationType === 'bulk_edit'

  const buildTargetScope = (): Record<string, unknown> => {
    if (isEntryBased) {
      return {
        entries: entryRows
          .filter((row) => row.userId && row.workDate)
          .map((row) => ({ user_id: row.userId, work_date: row.workDate, schedule_state: row.scheduleState })),
      }
    }

    const scope: Record<string, unknown> = {
      user_ids: userRows.map((row) => row.userId).filter(Boolean),
      from,
      to,
    }
    if (operationType === 'calendar_apply') scope.work_style_id = workStyleId
    return scope
  }

  const canPreview = isEntryBased
    ? entryRows.some((row) => row.userId && row.workDate)
    : userRows.some((row) => row.userId) && Boolean(from) && Boolean(to) && (operationType !== 'calendar_apply' || Boolean(workStyleId))

  const handlePreview = () => {
    preview.mutate({
      operation_type: operationType,
      target_scope: buildTargetScope(),
      conflict_policy: conflictPolicy,
      reason: reason || '(未入力)',
    })
  }

  const handleApply = () => {
    apply.mutate(
      {
        operation_type: operationType,
        target_scope: buildTargetScope(),
        conflict_policy: conflictPolicy,
        reason,
      },
      { onSuccess: () => preview.reset() },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <Card title="一括操作を作成">
        {(preview.error || apply.error) && <ErrorMessage error={preview.error ?? apply.error} />}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="操作種別" htmlFor="bulk-op-type" required>
            <NativeSelect
              id="bulk-op-type"
              value={operationType}
              onChange={(e) => {
                setOperationType(e.target.value as CalendarBulkOperationType)
                preview.reset()
              }}
            >
              {OPERATION_TYPE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>

          <FormField label="競合時の方針" htmlFor="bulk-op-conflict-policy" required>
            <NativeSelect
              id="bulk-op-conflict-policy"
              value={conflictPolicy}
              onChange={(e) => setConflictPolicy(e.target.value as CalendarBulkOperationConflictPolicy)}
            >
              {CONFLICT_POLICY_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </div>

        <FormField label="理由" htmlFor="bulk-op-reason" required>
          <Textarea id="bulk-op-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>

        {!isEntryBased && (
          <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField label="対象期間(開始)" htmlFor="bulk-op-from" required>
                <DatePicker id="bulk-op-from" value={from || undefined} onChange={(date) => setFrom(date ?? '')} />
              </FormField>
              <FormField label="対象期間(終了)" htmlFor="bulk-op-to" required>
                <DatePicker id="bulk-op-to" value={to || undefined} onChange={(date) => setTo(date ?? '')} />
              </FormField>
            </div>

            {operationType === 'calendar_apply' && (
              <FormField label="勤務形態" htmlFor="bulk-op-work-style" required>
                <NativeSelect id="bulk-op-work-style" value={workStyleId} onChange={(e) => setWorkStyleId(e.target.value)}>
                  <option value="">選択してください</option>
                  {workStyles?.map((workStyle) => (
                    <option key={workStyle.id} value={workStyle.id}>
                      {workStyle.name}
                    </option>
                  ))}
                </NativeSelect>
              </FormField>
            )}

            <p className="mb-2 text-sm font-medium text-foreground">対象社員</p>
            <div className="mb-4 flex flex-col gap-2">
              {userRows.map((row) => (
                <div key={row.rowId} className="flex items-center gap-2">
                  <div className="flex-1">
                    <UserPicker
                      id={`bulk-op-user-${row.rowId}`}
                      value={row.userId || undefined}
                      onChange={(userId) => updateUserRow(row.rowId, { userId: userId ?? '' })}
                    />
                  </div>
                  <Button variant="secondary" onClick={() => removeUserRow(row.rowId)}>
                    削除
                  </Button>
                </div>
              ))}
              <Button variant="secondary" onClick={() => addUserRow({ userId: '' })}>
                社員を追加
              </Button>
            </div>
          </>
        )}

        {isEntryBased && (
          <div className="mb-4">
            <p className="mb-2 text-sm font-medium text-foreground">対象社員・日付・勤務区分</p>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>社員</TableHead>
                  <TableHead>対象日</TableHead>
                  <TableHead>勤務区分</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {entryRows.map((row) => (
                  <TableRow key={row.rowId}>
                    <TableCell>
                      <UserPicker
                        id={`bulk-op-entry-user-${row.rowId}`}
                        value={row.userId || undefined}
                        onChange={(userId) => updateEntryRow(row.rowId, { userId: userId ?? '' })}
                      />
                    </TableCell>
                    <TableCell>
                      <DatePicker
                        aria-label="対象日"
                        value={row.workDate || undefined}
                        onChange={(date) => updateEntryRow(row.rowId, { workDate: date ?? '' })}
                      />
                    </TableCell>
                    <TableCell>
                      <NativeSelect
                        aria-label="勤務区分"
                        value={row.scheduleState}
                        onChange={(e) =>
                          updateEntryRow(row.rowId, { scheduleState: e.target.value as BulkEditEntryRow['scheduleState'] })
                        }
                      >
                        <option value="WORK">WORK</option>
                        <option value="OFF">OFF</option>
                        <option value="LEAVE">LEAVE</option>
                      </NativeSelect>
                    </TableCell>
                    <TableCell>
                      <Button variant="secondary" onClick={() => removeEntryRow(row.rowId)}>
                        削除
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Button
              variant="secondary"
              className="mt-2"
              onClick={() => addEntryRow({ userId: '', workDate: '', scheduleState: 'WORK' })}
            >
              行を追加
            </Button>
          </div>
        )}

        <Button isLoading={preview.isPending} disabled={!canPreview} onClick={handlePreview}>
          プレビューする
        </Button>
      </Card>

      {preview.data && (
        <Card title="プレビュー結果">
          <dl className="mb-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="font-medium text-muted-foreground">対象件数</dt>
            <dd className="text-foreground">{preview.data.targets.length}件</dd>
            <dt className="font-medium text-muted-foreground">競合件数</dt>
            <dd className="text-foreground">{preview.data.conflict_count}件</dd>
            <dt className="font-medium text-muted-foreground">実行可否</dt>
            <dd>
              <Badge tone={preview.data.executable ? 'success' : 'danger'}>
                {preview.data.executable ? '実行可能' : '実行不可(競合あり)'}
              </Badge>
            </dd>
          </dl>

          {preview.data.targets.length > 0 && (
            <div className="mb-4 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>社員ID</TableHead>
                    <TableHead>対象日</TableHead>
                    <TableHead>競合</TableHead>
                    <TableHead>結果</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {preview.data.targets.map((target) => (
                    <TableRow key={`${target.user_id}-${target.work_date}`}>
                      <TableCell>{target.user_id}</TableCell>
                      <TableCell>{target.work_date}</TableCell>
                      <TableCell>{target.conflict ? 'あり' : 'なし'}</TableCell>
                      <TableCell>
                        <Badge tone={target.result === 'applied' ? 'success' : target.result === 'failed' ? 'danger' : 'neutral'}>
                          {target.result === 'applied' ? '適用' : target.result === 'failed' ? '失敗' : 'スキップ'}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          <Button
            isLoading={apply.isPending}
            disabled={!preview.data.executable || !reason}
            onClick={handleApply}
          >
            この内容で確定適用する
          </Button>
        </Card>
      )}

      <Card title="一括操作の履歴">
        {isLoadingHistory && <LoadingState />}
        {historyError && <ErrorMessage error={historyError} fallback="履歴の取得に失敗しました。" />}
        {!isLoadingHistory && !historyError && (history ?? []).length === 0 && (
          <EmptyState title="一括操作の履歴はまだありません。" />
        )}
        {!isLoadingHistory && !historyError && (history ?? []).length > 0 && (
          <ul className="divide-y divide-border">
            {(history ?? []).map((operation) => (
              <li key={operation.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="flex flex-1 flex-col">
                  <span className="text-sm font-medium text-foreground">
                    {OPERATION_TYPE_OPTIONS.find((o) => o.value === operation.operation_type)?.label ??
                      operation.operation_type}
                  </span>
                  <span className="text-sm text-muted-foreground">{operation.reason}</span>
                </div>
                <Badge tone={STATUS_TONE[operation.status] ?? 'neutral'}>
                  {STATUS_LABEL[operation.status] ?? operation.status}
                </Badge>
                {operation.status === 'applied' && (
                  <ConfirmActionDialog
                    triggerLabel="取消す"
                    title="この一括操作を取り消しますか?"
                    description="この操作で適用した予定・カレンダー設定がすべて元に戻ります。取消は元に戻せません。"
                    confirmLabel="取り消す"
                    isPending={revert.isPending && revert.variables === operation.id}
                    error={revert.variables === operation.id ? revert.error : undefined}
                    onConfirm={() => revert.mutateAsync(operation.id)}
                  />
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
