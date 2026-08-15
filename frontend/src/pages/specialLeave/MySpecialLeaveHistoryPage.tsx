import { Card } from '../../components/Card/Card'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { useMySpecialLeaveHistory } from '../../hooks/useSpecialLeave'

/**
 * 自分の特別休暇履歴(付与・申請・承認・差戻し・取消・消化)を確認する。
 */
export function MySpecialLeaveHistoryPage() {
  const { data, isLoading, error } = useMySpecialLeaveHistory()
  const isEmpty = !isLoading && !error && (data?.length ?? 0) === 0

  return (
    <Card title="特別休暇履歴">
      {isEmpty ? (
        <EmptyState title="特別休暇履歴はまだありません。" description="特別休暇を申請すると、ここに履歴が表示されます。" />
      ) : (
        <LeaveHistoryList domain="special_leave" events={data} isLoading={isLoading} error={error} />
      )}
    </Card>
  )
}
