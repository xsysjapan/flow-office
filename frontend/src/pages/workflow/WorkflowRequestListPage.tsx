import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { WorkflowRequestStatus } from '../../api/types'
import { useCancelWorkflowRequest, useMyWorkflowRequests } from '../../hooks/useWorkflowRequests'
import { workflowRequestStatusLabel } from '../../utils/statusLabels'

const CANCELLABLE_STATUSES: WorkflowRequestStatus[] = ['draft', 'submitted', 'returned']

/**
 * UC-W002手順6周辺: 自分の申請一覧。
 * 取消可能な申請(下書き/提出済み/差戻し)は複数選択し、共通の取消理由でまとめて
 * 取り消せる(オブジェクトを選択してから操作を適用するUI)。
 */
export function WorkflowRequestListPage() {
  const { data, isLoading, error } = useMyWorkflowRequests()
  const cancelRequest = useCancelWorkflowRequest()

  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const [bulkReason, setBulkReason] = useState('')
  const [isBulkCancelling, setIsBulkCancelling] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請一覧の取得に失敗しました。" />

  const requests = data?.data ?? []

  function toggleRow(id: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function handleBulkCancel() {
    if (!bulkReason || selectedIds.size === 0) return
    setBulkError(null)
    setIsBulkCancelling(true)
    try {
      await Promise.all(
        Array.from(selectedIds).map((id) => cancelRequest.mutateAsync({ id, reason: bulkReason })),
      )
      setSelectedIds(new Set())
      setBulkReason('')
    } catch (e) {
      setBulkError(e as Error)
    } finally {
      setIsBulkCancelling(false)
    }
  }

  return (
    <Card
      title="自分の申請"
      actions={
        selectedIds.size > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件を選択中</span>
            <div className="w-48">
              <Input placeholder="取消理由" value={bulkReason} onChange={(e) => setBulkReason(e.target.value)} />
            </div>
            <Button
              variant="danger"
              onClick={() => void handleBulkCancel()}
              isLoading={isBulkCancelling}
              disabled={!bulkReason}
            >
              まとめて取り消す
            </Button>
          </div>
        ) : undefined
      }
    >
      {bulkError && <ErrorMessage error={bulkError} />}
      {requests.length === 0 ? (
        <p className="text-sm text-muted-foreground">申請はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead aria-hidden="true" />
              <TableHead>タイトル</TableHead>
              <TableHead>種別</TableHead>
              <TableHead>ステータス</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {requests.map((request) => {
              const { label, tone } = workflowRequestStatusLabel(request.status)
              const cancellable = CANCELLABLE_STATUSES.includes(request.status)
              const selected = selectedIds.has(request.id)
              return (
                <TableRow key={request.id} data-state={selected ? 'selected' : undefined}>
                  <TableCell>
                    {cancellable && (
                      <Checkbox
                        checked={selected}
                        onCheckedChange={() => toggleRow(request.id)}
                        aria-label={`${request.title}を選択`}
                      />
                    )}
                  </TableCell>
                  <TableCell>
                    <Link
                      to={`/requests/${request.id}`}
                      className="font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {request.title}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{request.request_type?.name}</TableCell>
                  <TableCell>
                    <Badge tone={tone}>{label}</Badge>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}
