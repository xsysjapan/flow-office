import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../components/ui/table'
import { useWorkflowRequestsToApprove } from '../hooks/useWorkflowRequests'
import { workflowRequestStatusLabel } from '../utils/statusLabels'

/**
 * UC-W003/UC-W004: 承認者向けの承認待ち一覧(汎用申請)。
 */
export function ApprovalsPage() {
  const { data, isLoading, error } = useWorkflowRequestsToApprove()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ち一覧の取得に失敗しました。" />

  const requests = data?.data ?? []

  return (
    <Card title="承認待ちの申請">
      {requests.length === 0 ? (
        <p className="text-sm text-muted-foreground">承認待ちの申請はありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>タイトル</TableHead>
              <TableHead>申請者</TableHead>
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
                  <TableCell className="text-muted-foreground">{request.applicant?.name}</TableCell>
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
