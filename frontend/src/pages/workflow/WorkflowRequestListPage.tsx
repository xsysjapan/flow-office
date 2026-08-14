import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useCancelWorkflowRequest, useMyWorkflowRequests } from '../../hooks/useWorkflowRequests'
import { isWorkflowRequestCancellable, workflowRequestStatusLabel } from '../../utils/statusLabels'

/**
 * UC-W002手順6周辺: 自分の申請一覧。
 * 取消可能な申請(下書き/提出済み/差戻し)は複数選択し、共通の取消理由でまとめて
 * 取り消せる(オブジェクトを選択してから操作を適用するUI)。
 */
export function WorkflowRequestListPage() {
  const navigate = useNavigate()
  const { data, isLoading, error } = useMyWorkflowRequests()
  const cancelRequest = useCancelWorkflowRequest()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkReason, setBulkReason] = useState('')
  const [isBulkCancelling, setIsBulkCancelling] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請一覧の取得に失敗しました。" />

  const requests = data?.data ?? []

  function toggleRow(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  /** 取消は元に戻せない操作(SKILL.md §2.12)のため、確認ダイアログ(ConfirmActionDialog)を
   *  経由させる。理由未入力の場合はダイアログを開いたまま留める(Promiseをthrowして
   *  ConfirmActionDialog側のcatchで「開いたまま」を維持する)。 */
  async function handleBulkCancel() {
    if (selectedIds.size === 0) return
    if (!bulkReason) {
      const emptyReasonError = new Error('取消理由を入力してください。')
      setBulkError(emptyReasonError)
      throw emptyReasonError
    }
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
      throw e
    } finally {
      setIsBulkCancelling(false)
    }
  }

  return (
    <Card
      title="その他申請"
      actions={
        selectedIds.size > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件を選択中</span>
            <ConfirmActionDialog
              triggerLabel="まとめて取消"
              triggerVariant="danger"
              title={`選択した${selectedIds.size}件の申請を取り消しますか?`}
              description="この操作は元に戻せません。選択した申請はすべて取消状態になります。"
              confirmLabel="まとめて取り消す"
              isPending={isBulkCancelling}
              error={bulkError}
              onConfirm={handleBulkCancel}
              onOpenChange={(open) => {
                if (open) {
                  setBulkReason('')
                  setBulkError(null)
                }
              }}
            >
              <FormField label="取消理由" htmlFor="bulk-cancel-reason" required>
                <Input
                  id="bulk-cancel-reason"
                  placeholder="取消理由"
                  value={bulkReason}
                  onChange={(e) => setBulkReason(e.target.value)}
                />
              </FormField>
            </ConfirmActionDialog>
          </div>
        ) : (
          <Button asChild>
            <Link to="/requests/new">新規作成</Link>
          </Button>
        )
      }
    >
      {requests.length === 0 ? (
        <EmptyState
          title="申請はまだありません。"
          description="新規作成から名刺作成や証明書発行などの申請を行えます。"
          action={
            <Button asChild variant="secondary" size="sm">
              <Link to="/requests/new">申請を作成</Link>
            </Button>
          }
        />
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
              const cancellable = isWorkflowRequestCancellable(request.status)
              const selected = selectedIds.has(request.id)
              return (
                <ClickableTableRow
                  key={request.id}
                  data-state={selected ? 'selected' : undefined}
                  onRowClick={() => navigate(`/requests/${request.id}`)}
                  rowLabel={`${request.title}の詳細を開く`}
                >
                  <TableCell onClick={(e) => e.stopPropagation()}>
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
                      onClick={(e) => e.stopPropagation()}
                    >
                      {request.title}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{request.request_type?.name}</TableCell>
                  <TableCell>
                    <Badge tone={tone}>{label}</Badge>
                  </TableCell>
                </ClickableTableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}
