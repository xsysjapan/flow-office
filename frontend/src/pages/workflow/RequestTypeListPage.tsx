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
import { useRequestTypes } from '../../hooks/useRequestTypes'

/**
 * UC-M002 / UC-W001: 管理者が申請種別を一覧・管理する。
 */
export function RequestTypeListPage() {
  const navigate = useNavigate()
  const forwardState = useForwardState()
  const { data: requestTypes, isLoading, error } = useRequestTypes(true)

  const description = '申請種別の一覧から、申請フォームと承認ルートを作成・編集できます。'
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
        <ErrorMessage error={error} fallback="申請種別の取得に失敗しました。" />
      </div>
    )

  const types = requestTypes ?? []

  return (
    <div className="space-y-6">
    <PageHeader
      description={description}
      actions={
        <Button asChild>
          <Link to="/admin/request-types/new" state={forwardState}>
            新規作成
          </Link>
        </Button>
      }
    />
    <Card>
      {types.length === 0 ? (
        <EmptyState
          title="申請種別はまだありません。"
          description="「新規作成」から申請種別を作成できます。"
          action={
            <Button asChild>
              <Link to="/admin/request-types/new" state={forwardState}>
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
              <TableHead>バックオフィス</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {types.map((type) => (
              <ClickableTableRow
                key={type.id}
                onRowClick={() => navigate(`/admin/request-types/${type.id}`, { state: forwardState })}
                rowLabel={`${type.name}の詳細を開く`}
              >
                <TableCell className="whitespace-normal min-w-48">
                  <Link
                    to={`/admin/request-types/${type.id}`}
                    state={forwardState}
                    className="font-medium text-foreground hover:text-primary hover:underline"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {type.name}
                  </Link>
                </TableCell>
                <TableCell className="text-muted-foreground">{type.code}</TableCell>
                <TableCell className="text-muted-foreground">
                  {type.requires_backoffice_task ? (type.backoffice_task_type ?? '未設定') : '-'}
                </TableCell>
                <TableCell>
                  <Badge tone={type.is_active ? 'success' : 'neutral'}>{type.is_active ? '有効' : '無効'}</Badge>
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
