import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../components/ui/table'
import { useMyWorkflowRequests } from '../hooks/useWorkflowRequests'
import { workflowRequestStatusLabel } from '../utils/statusLabels'

/**
 * UC-W002手順6周辺: 自分の申請一覧。
 */
export function WorkflowRequestListPage() {
  const { data, isLoading, error } = useMyWorkflowRequests()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請一覧の取得に失敗しました。" />

  const requests = data?.data ?? []

  return (
    <Card title="自分の申請">
      {requests.length === 0 ? (
        <p className="text-sm text-muted-foreground">申請はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>タイトル</TableHead>
              <TableHead>種別</TableHead>
              <TableHead>ステータス</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {requests.map((request) => {
              const { label, tone } = workflowRequestStatusLabel(request.status)
              return (
                <TableRow key={request.id}>
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
