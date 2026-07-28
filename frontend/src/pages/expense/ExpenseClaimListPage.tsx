import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog/ConfirmDialog'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useDeleteExpenseClaim, useMyExpenseClaims } from '../../hooks/useExpenseClaims'
import { expenseClaimStatusLabel } from '../../utils/statusLabels'

/**
 * UC-X010: 自分の経費精算一覧。まだ申請していない不要な下書きはここから削除できる。
 */
export function ExpenseClaimListPage() {
  const { data, isLoading, error } = useMyExpenseClaims()
  const deleteClaim = useDeleteExpenseClaim()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="経費精算一覧の取得に失敗しました。" />

  const claims = data?.data ?? []

  return (
    <Card
      title="自分の経費精算"
      actions={
        <Button asChild>
          <Link to="/expenses/new">新規作成</Link>
        </Button>
      }
    >
      {deleteClaim.error && <ErrorMessage error={deleteClaim.error} />}

      {claims.length === 0 ? (
        <p className="text-sm text-muted-foreground">経費精算はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>タイトル</TableHead>
              <TableHead>対象期間</TableHead>
              <TableHead>明細件数</TableHead>
              <TableHead>合計金額</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>承認者</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {claims.map((claim) => {
              const { label, tone } = expenseClaimStatusLabel(claim.status)
              const isEditable = claim.status === 'draft' || claim.status === 'returned'
              const isDeletable = claim.status === 'draft'
              return (
                <TableRow key={claim.id}>
                  <TableCell className="text-foreground">{claim.title ?? '-'}</TableCell>
                  <TableCell>
                    <Link
                      to={`/expenses/${claim.id}`}
                      className="font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {claim.period_from && claim.period_to ? `${claim.period_from} 〜 ${claim.period_to}` : '-'}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{claim.items.length}</TableCell>
                  <TableCell className="text-muted-foreground">{claim.total_amount.toLocaleString()}円</TableCell>
                  <TableCell>
                    <Badge tone={tone}>{label}</Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{claim.approver?.name ?? '-'}</TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      {isEditable && (
                        <Button asChild variant="secondary" size="sm">
                          <Link to={`/expenses/${claim.id}/edit`}>編集を続ける</Link>
                        </Button>
                      )}
                      {isDeletable && (
                        <ConfirmDialog
                          trigger={
                            <Button variant="danger" size="sm">
                              削除
                            </Button>
                          }
                          title="この下書きを削除しますか?"
                          description="削除すると元に戻せません。保存済みの明細もすべて削除されます。"
                          isConfirming={deleteClaim.isPending && deleteClaim.variables === claim.id}
                          onConfirm={() => deleteClaim.mutate(claim.id)}
                        />
                      )}
                    </div>
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
