import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useRequestTypes } from '../../hooks/useRequestTypes'

/**
 * UC-M002 / UC-W001: 管理者が申請種別を一覧・管理する。
 */
export function RequestTypeListPage() {
  const { data: requestTypes, isLoading, error } = useRequestTypes(true)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請種別の取得に失敗しました。" />

  const types = requestTypes ?? []

  return (
    <Card
      title="申請種別一覧"
      actions={
        <Button asChild>
          <Link to="/admin/request-types/new">新規作成</Link>
        </Button>
      }
    >
      {types.length === 0 ? (
        <p className="text-sm text-muted-foreground">申請種別はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>名称</TableHead>
              <TableHead>コード</TableHead>
              <TableHead>バックオフィス</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {types.map((type) => (
              <TableRow key={type.id}>
                <TableCell>
                  <Link
                    to={`/admin/request-types/${type.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline"
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
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}
