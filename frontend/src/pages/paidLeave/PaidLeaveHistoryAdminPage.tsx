import { useSearchParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { FormField } from '../../components/FormField/FormField'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { usePaidLeaveHistoryForUser } from '../../hooks/usePaidLeave'

/**
 * UC-P007: 管理者・人事担当者が対象社員を選んで有給履歴を確認する。
 * 対象社員(表示対象の絞り込み)はURLに反映し、リロード・共有時も選択状態を保つ。
 */
export function PaidLeaveHistoryAdminPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const userId = searchParams.get('userId') ?? undefined
  const { data, isLoading, error } = usePaidLeaveHistoryForUser(userId ?? '')

  const handleUserChange = (value: string | undefined) => {
    const next = new URLSearchParams(searchParams)
    if (value) {
      next.set('userId', value)
    } else {
      next.delete('userId')
    }
    setSearchParams(next, { replace: true })
  }

  const isEmpty = userId !== undefined && !isLoading && !error && (data?.length ?? 0) === 0

  return (
    <Card title="有給履歴">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="paid-leave-history-user">
          <UserPicker id="paid-leave-history-user" value={userId} onChange={handleUserChange} />
        </FormField>
      </div>

      {userId === undefined ? (
        <EmptyState title="対象社員を選択してください。" description="社員を選ぶと、その社員の有給履歴を確認できます。" />
      ) : isEmpty ? (
        <EmptyState
          title="有給履歴はまだありません。"
          description="対象社員が有給を申請・付与されると、ここに履歴が表示されます。"
          action={
            <Button variant="secondary" onClick={() => handleUserChange(undefined)}>
              社員選択をクリア
            </Button>
          }
        />
      ) : (
        <LeaveHistoryList domain="paid_leave" events={data} isLoading={isLoading} error={error} />
      )}
    </Card>
  )
}
