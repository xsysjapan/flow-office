import { Link } from 'react-router-dom'
import { Badge, type BadgeTone } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { ExpenseClaimStatus } from '../../api/types'
import { useExpenseClaimsToApprove } from '../../hooks/useExpenseClaims'

const expenseClaimStatusMeta: Record<ExpenseClaimStatus, { label: string; tone: BadgeTone }> = {
  draft: { label: '下書き', tone: 'neutral' },
  in_review: { label: '申請中', tone: 'info' },
  returned: { label: '差戻し', tone: 'warning' },
  approved: { label: '承認済み', tone: 'success' },
  cancelled: { label: '取消', tone: 'neutral' },
}

/**
 * UC-X011手順1: 承認者向けの承認待ちの経費精算一覧。
 */
export function ExpenseClaimsToApprovePage() {
  const { data, isLoading, error } = useExpenseClaimsToApprove()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ちの経費精算一覧の取得に失敗しました。" />

  const claims = data ?? []

  return (
    <Card title="承認待ちの経費精算">
      {claims.length === 0 ? (
        <p className="text-sm text-muted-foreground">承認待ちの経費精算はありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>対象期間</TableHead>
              <TableHead>申請者</TableHead>
              <TableHead>明細件数</TableHead>
              <TableHead>合計金額</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {claims.map((claim) => {
              const { label, tone } = expenseClaimStatusMeta[claim.status]
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
                  <TableCell className="text-muted-foreground">{claim.employee?.name}</TableCell>
                  <TableCell className="text-muted-foreground">{claim.items.length}</TableCell>
                  <TableCell className="text-muted-foreground">{claim.total_amount.toLocaleString()}円</TableCell>
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
