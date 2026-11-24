import { Link, useNavigate } from 'react-router-dom'
import { useForwardState } from '../../navigation/backState'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { ExpenseEvidenceType } from '../../api/types'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'

const evidenceTypeLabels: Record<ExpenseEvidenceType, string> = {
  fact_reference_available: '実績参照のみ',
  receipt_required: 'レシート必須',
  receipt_optional: 'レシート任意',
}

/**
 * UC-X001: 管理者が経費区分マスタを一覧・管理する。
 */
export function ExpenseCategoryListPage() {
  const navigate = useNavigate()
  const forwardState = useForwardState()
  const { data: categories, isLoading, error } = useExpenseCategories(true)

  const description = '経費区分の一覧から、証憑要件や承認省略ルールを作成・編集できます。'
  if (isLoading)
    return (
      <div className="space-y-6">
        <PageHeader description={description} />
        <LoadingState />
      </div>
    )
  if (error)
    return (
      <div className="space-y-6">
        <PageHeader description={description} />
        <ErrorMessage error={error} fallback="経費区分の取得に失敗しました。" />
      </div>
    )

  const items = categories ?? []

  return (
    <div className="space-y-6">
    <PageHeader
      description={description}
      actions={
        <Button asChild>
          <Link to="/admin/expense-categories/new" state={forwardState}>
            新規作成
          </Link>
        </Button>
      }
    />
    <Card>
      {items.length === 0 ? (
        <EmptyState
          title="経費区分はまだありません。"
          description="「新規作成」から経費区分を作成できます。"
          action={
            <Button asChild>
              <Link to="/admin/expense-categories/new" state={forwardState}>
                新規作成
              </Link>
            </Button>
          }
        />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="whitespace-normal min-w-48">名称</TableHead>
              <TableHead>コード</TableHead>
              <TableHead>証憑タイプ既定</TableHead>
              <TableHead>レシート必須しきい値</TableHead>
              <TableHead>承認省略しきい値</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.map((category) => (
              <ClickableTableRow
                key={category.id}
                onRowClick={() => navigate(`/admin/expense-categories/${category.id}`, { state: forwardState })}
                rowLabel={`${category.name}の詳細を開く`}
              >
                <TableCell className="whitespace-normal min-w-48">
                  <Link
                    to={`/admin/expense-categories/${category.id}`}
                    state={forwardState}
                    className="font-medium text-foreground hover:text-primary hover:underline"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {category.name}
                  </Link>
                </TableCell>
                <TableCell className="text-muted-foreground">{category.code}</TableCell>
                <TableCell className="text-muted-foreground">
                  {evidenceTypeLabels[category.evidence_type_default]}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {category.receipt_required_threshold != null ? `${category.receipt_required_threshold}円` : '-'}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {category.approval_skip_threshold != null ? `${category.approval_skip_threshold}円` : '-'}
                </TableCell>
                <TableCell>
                  <Badge tone={category.is_active ? 'success' : 'neutral'}>
                    {category.is_active ? '有効' : '無効'}
                  </Badge>
                </TableCell>
              </ClickableTableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
    </div>
  )
}
