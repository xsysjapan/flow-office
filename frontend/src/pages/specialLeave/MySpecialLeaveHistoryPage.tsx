import { Card } from '../../components/Card/Card'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { useMySpecialLeaveHistory } from '../../hooks/useSpecialLeave'

/**
 * 自分の特別休暇履歴(付与・申請・承認・差戻し・取消・消化)を確認する。
 */
export function MySpecialLeaveHistoryPage() {
  const { data, isLoading, error } = useMySpecialLeaveHistory()

  return (
    <Card title="特別休暇履歴">
      <LeaveHistoryList domain="special_leave" events={data} isLoading={isLoading} error={error} />
    </Card>
  )
}
