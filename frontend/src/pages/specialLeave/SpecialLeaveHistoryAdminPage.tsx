import { useState } from 'react'
import { Card } from '../../components/Card/Card'
import { FormField } from '../../components/FormField/FormField'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { useSpecialLeaveHistoryForUser } from '../../hooks/useSpecialLeave'

/**
 * 管理者・人事担当者が対象社員を選んで特別休暇履歴を確認する。
 */
export function SpecialLeaveHistoryAdminPage() {
  const [userId, setUserId] = useState<string | undefined>(undefined)
  const { data, isLoading, error } = useSpecialLeaveHistoryForUser(userId ?? '')

  return (
    <Card title="特別休暇履歴">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="special-leave-history-user">
          <UserPicker id="special-leave-history-user" value={userId} onChange={setUserId} />
        </FormField>
      </div>

      {userId !== undefined && <LeaveHistoryList domain="special_leave" events={data} isLoading={isLoading} error={error} />}
    </Card>
  )
}
