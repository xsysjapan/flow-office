import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useMyPaidLeaveGrants } from '../hooks/usePaidLeave'
import './MyPaidLeavePage.css'

/**
 * UC-P001: 自分の有給付与状況を確認する。
 */
export function MyPaidLeavePage() {
  const { data, isLoading, error } = useMyPaidLeaveGrants()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="有給情報の取得に失敗しました。" />

  const grants = data ?? []
  const totalRemaining = grants.reduce((sum, grant) => sum + grant.remaining_days, 0)

  return (
    <Card title="自分の有給">
      <p className="my-paid-leave__summary">
        残り<strong>{totalRemaining}</strong>日
      </p>

      {grants.length === 0 ? (
        <p>有給の付与はまだありません。</p>
      ) : (
        <table className="my-paid-leave__table">
          <thead>
            <tr>
              <th>付与日</th>
              <th>失効日</th>
              <th>付与日数</th>
              <th>使用日数</th>
              <th>残日数</th>
              <th>付与理由</th>
            </tr>
          </thead>
          <tbody>
            {grants.map((grant) => (
              <tr key={grant.id}>
                <td>{grant.granted_on}</td>
                <td>{grant.expires_on}</td>
                <td>{grant.granted_days}</td>
                <td>{grant.used_days}</td>
                <td>{grant.remaining_days}</td>
                <td>{grant.grant_reason ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  )
}
