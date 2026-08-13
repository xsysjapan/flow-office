import { Card } from '../../components/Card/Card'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { useMyPaidLeaveHistory } from '../../hooks/usePaidLeave'

/**
 * UC-P007: 自分の有給履歴(付与・申請・承認・差戻し・取消・消化)を確認する。
 */
export function MyPaidLeaveHistoryPage() {
  const { data, isLoading, error } = useMyPaidLeaveHistory()
  const isEmpty = !isLoading && !error && (data?.length ?? 0) === 0

  return (
    <Card title="有給履歴">
      {isEmpty ? (
        <EmptyState title="有給履歴はまだありません。" description="有給を申請すると、ここに履歴が表示されます。" />
      ) : (
        <LeaveHistoryList domain="paid_leave" events={data} isLoading={isLoading} error={error} />
      )}
    </Card>
  )
}
