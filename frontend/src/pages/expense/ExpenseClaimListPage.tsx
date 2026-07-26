import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useMyExpenseClaims } from '../../hooks/useExpenseClaims'
import { expenseClaimStatusLabel } from '../../utils/statusLabels'

/**
 * UC-X010: 自分の経費精算一覧。
 */
export function ExpenseClaimListPage() {
  const { data, isLoading, error } = useMyExpenseClaims()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="経費精算一覧の取得に失敗しました。" />

  const claims = data ?? []

  return (
    <Card
      title="自分の経費精算"
      actions={
        <Button asChild>
          <Link to="/expenses/new">新規作成</Link>
        </Button>
      }
    >
      {claims.length === 0 ? (
        <p className="text-sm text-muted-foreground">経費精算はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>対象期間</TableHead>
              <TableHead>明細件数</TableHead>
              <TableHead>合計金額</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>承認者</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {claims.map((claim) => {
              const { label, tone } = expenseClaimStatusLabel(claim.status)
              return (
                <TableRow key={claim.id}>
                  <TableCell>
                    <Link
                      to={`/expenses/${claim.id}`}
                      className="font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {claim.period_from} 〜 {claim.period_to}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{claim.items.length}</TableCell>
                  <TableCell className="text-muted-foreground">{claim.total_amount.toLocaleString()}円</TableCell>
                  <TableCell>
                    <Badge tone={tone}>{label}</Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{claim.approver?.name ?? '-'}</TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}
