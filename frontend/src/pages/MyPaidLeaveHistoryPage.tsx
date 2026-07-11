import { Card } from '../components/Card/Card'
import { PaidLeaveHistoryList } from '../components/PaidLeaveHistoryList/PaidLeaveHistoryList'
import { useMyPaidLeaveHistory } from '../hooks/usePaidLeave'

/**
 * UC-P007: 自分の有給履歴(付与・申請・承認・差戻し・取消・消化)を確認する。
 */
export function MyPaidLeaveHistoryPage() {
  const { data, isLoading, error } = useMyPaidLeaveHistory()

  return (
    <Card title="有給履歴">
      <PaidLeaveHistoryList events={data} isLoading={isLoading} error={error} />
    </Card>
  )
}
