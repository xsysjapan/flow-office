import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useMyMonthlyAttendanceDrafts } from '../../hooks/useMonthlyAttendanceDrafts'
import { monthlyDraftStatusLabel } from '../../utils/statusLabels'

/**
 * UC-R001/UC-R002: Claude等(MCP経由)が作成した月次勤怠下書きを一覧し、レビュー画面へ
 * 遷移する。下書きの新規作成自体はMCPツール経由で行うため、このページからは作成しない。
 */
export function MyMonthlyAttendanceDraftsPage() {
  const { data: drafts, isLoading, error } = useMyMonthlyAttendanceDrafts()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="月次勤怠下書きの取得に失敗しました。" />

  const list = drafts ?? []

  return (
    <Card title="月次勤怠下書き">
      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          下書きはまだありません。作業報告書からの月次勤怠下書きは、Claude等のAIアプリ(MCP連携)から作成できます。
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>対象月</TableHead>
              <TableHead>作成元</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>作成日</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((draft) => {
              const meta = monthlyDraftStatusLabel(draft.status)
              return (
                <TableRow key={draft.id}>
                  <TableCell>
                    <Link
                      to={`/attendance/monthly-drafts/${draft.id}`}
                      className="font-medium text-foreground hover:text-primary hover:underline"
                    >
                      {draft.target_month}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{draft.source_type ?? '-'}</TableCell>
                  <TableCell>
                    <Badge tone={meta.tone}>{meta.label}</Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {draft.created_at ? new Date(draft.created_at).toLocaleDateString('ja-JP') : '-'}
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
