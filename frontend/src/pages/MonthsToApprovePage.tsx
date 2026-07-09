import { useState } from 'react'
import { useAuth } from '../auth/useAuth'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useApproveMonth, useCloseMonth, useMonthsToApprove, useReturnMonth } from '../hooks/useAttendance'
import { attendanceMonthStatusLabel } from '../utils/statusLabels'
import './MonthsToApprovePage.css'

/**
 * UC-A009: 承認者向けの勤怠月次の承認・差戻し。
 * UC-A010: 管理者/人事による締め処理(admin・hr_staffロールのみ)。
 */
export function MonthsToApprovePage() {
  const { user } = useAuth()
  const { data, isLoading, error } = useMonthsToApprove()
  const approveMonth = useApproveMonth()
  const returnMonth = useReturnMonth()
  const closeMonth = useCloseMonth()

  const [comments, setComments] = useState<Record<number, string>>({})

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ちの勤怠月次の取得に失敗しました。" />

  const months = data ?? []
  const canClose = user?.roles?.includes('admin') || user?.roles?.includes('hr_staff')
  const actionError = approveMonth.error ?? returnMonth.error ?? closeMonth.error

  return (
    <Card title="承認待ちの勤怠月次">
      {actionError && <ErrorMessage error={actionError} />}

      {months.length === 0 ? (
        <p>承認待ちの勤怠月次はありません。</p>
      ) : (
        <ul className="months-to-approve-list">
          {months.map((month) => {
            const { label, tone } = attendanceMonthStatusLabel(month.status)
            const comment = comments[month.id] ?? ''

            return (
              <li key={month.id}>
                <div className="months-to-approve-list__row">
                  <div>
                    <span className="months-to-approve-list__year-month">{month.year_month}</span>
                    <span className="months-to-approve-list__user">社員ID: {month.user_id}</span>
                  </div>
                  <Badge tone={tone}>{label}</Badge>
                </div>

                <div className="months-to-approve-list__actions">
                  {month.status === 'submitted' && (
                    <>
                      <Button isLoading={approveMonth.isPending} onClick={() => approveMonth.mutate(month.id)}>
                        承認する
                      </Button>
                      <div className="months-to-approve-list__with-comment">
                        <input
                          placeholder="差戻しコメント"
                          value={comment}
                          onChange={(e) =>
                            setComments((prev) => ({ ...prev, [month.id]: e.target.value }))
                          }
                        />
                        <Button
                          variant="secondary"
                          isLoading={returnMonth.isPending}
                          disabled={!comment}
                          onClick={() => returnMonth.mutate({ id: month.id, comment })}
                        >
                          差戻す
                        </Button>
                      </div>
                    </>
                  )}

                  {canClose && month.status === 'approved' && (
                    <Button isLoading={closeMonth.isPending} onClick={() => closeMonth.mutate(month.id)}>
                      締め処理
                    </Button>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
