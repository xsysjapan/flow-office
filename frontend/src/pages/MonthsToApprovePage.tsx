import { useState } from 'react'
import { useAuth } from '../auth/useAuth'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import { useApproveMonth, useCloseMonth, useMonthsToApprove, useReturnMonth } from '../hooks/useAttendance'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../utils/statusLabels'
import { hasAnyRole, ROLE } from '../utils/roles'

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
  const canClose = hasAnyRole(user?.roles, [ROLE.ADMIN, ROLE.HR_STAFF])
  const actionError = approveMonth.error ?? returnMonth.error ?? closeMonth.error

  return (
    <Card title="承認待ちの勤怠月次">
      {actionError && <ErrorMessage error={actionError} />}

      {months.length === 0 ? (
        <p className="text-sm text-muted-foreground">承認待ちの勤怠月次はありません。</p>
      ) : (
        <ul className="divide-y divide-border">
          {months.map((month) => {
            const { label, tone } = attendanceMonthStatusLabel(month.status)
            const comment = comments[month.id] ?? ''

            return (
              <li key={month.id} className="py-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-semibold text-foreground">{month.year_month}</span>
                    <span className="text-sm text-muted-foreground">社員ID: {month.user_id}</span>
                  </div>
                  <Badge tone={tone}>{label}</Badge>
                </div>

                {month.legal_holiday_warnings.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-2">
                    {month.legal_holiday_warnings.map((warning) => (
                      <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
                        {legalHolidayWarningLabel(warning)}
                      </Badge>
                    ))}
                  </div>
                )}

                <div className="mt-2 flex flex-wrap items-center gap-3">
                  {month.status === 'submitted' && (
                    <>
                      <Button isLoading={approveMonth.isPending} onClick={() => approveMonth.mutate(month.id)}>
                        承認する
                      </Button>
                      <div className="flex items-center gap-2">
                        <Input
                          placeholder="差戻しコメント"
                          value={comment}
                          onChange={(e) => setComments((prev) => ({ ...prev, [month.id]: e.target.value }))}
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
