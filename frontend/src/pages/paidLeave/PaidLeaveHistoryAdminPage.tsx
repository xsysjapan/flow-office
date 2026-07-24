import { useState } from 'react'
import { Card } from '../../components/Card/Card'
import { FormField } from '../../components/FormField/FormField'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { usePaidLeaveHistoryForUser } from '../../hooks/usePaidLeave'

/**
 * UC-P007: 管理者・人事担当者が対象社員を選んで有給履歴を確認する。
 */
export function PaidLeaveHistoryAdminPage() {
  const [userId, setUserId] = useState<string | undefined>(undefined)
  const { data, isLoading, error } = usePaidLeaveHistoryForUser(userId ?? '')

  return (
    <Card title="有給履歴">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="paid-leave-history-user">
          <UserPicker id="paid-leave-history-user" value={userId} onChange={setUserId} />
        </FormField>
      </div>

      {userId !== undefined && <LeaveHistoryList domain="paid_leave" events={data} isLoading={isLoading} error={error} />}
    </Card>
  )
}
