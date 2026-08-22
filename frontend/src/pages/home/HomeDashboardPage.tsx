import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, CalendarClock, CheckCircle2, ClipboardList, Inbox } from 'lucide-react'
import { Badge } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { useAuth } from '../../auth/useAuth'
import { useTodayAttendance } from '../../hooks/useAttendance'
import { useMyBackOfficeTasks } from '../../hooks/useBackOfficeTasks'
import { useMyExpenseClaims } from '../../hooks/useExpenseClaims'
import { useMyWorkflowRequests, useWorkflowRequestsToApprove } from '../../hooks/useWorkflowRequests'
import { attendanceDayDisplayLabel } from '../../utils/statusLabels'
import { isoToTimeLiteral } from '../../utils/offsetDateTime'

/** 業務ステータスのうち「まだ処理が完了していない」ものだけを件数に含める。 */
const PENDING_WORKFLOW_STATUSES = new Set(['submitted', 'returned'])
const PENDING_EXPENSE_STATUSES = new Set(['in_review', 'returned'])
const OPEN_BACKOFFICE_STATUSES = new Set([
  'not_started',
  'in_review',
  'needs_fix',
  'processing',
  'ordered',
  'payment_scheduled',
  'shipped',
])

function formatTime(value: string | null | undefined): string {
  return isoToTimeLiteral(value) || '--:--'
}

function DashboardCard({
  title,
  to,
  icon: Icon,
  children,
}: {
  title: string
  to: string
  icon: typeof CalendarClock
  children: ReactNode
}) {
  return (
    <Card
      title={
        <span className="flex items-center gap-2">
          <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          {title}
        </span>
      }
      navigation={
        <Link
          to={to}
          className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          詳しく見る
          <ArrowRight className="size-3.5 shrink-0" aria-hidden="true" />
        </Link>
      }
    >
      {children}
    </Card>
  )
}

function BigNumber({ value, unit }: { value: number; unit: string }) {
  return (
    <p className="text-3xl font-bold tabular-nums text-foreground">
      {value}
      <span className="ml-1 text-sm font-normal text-muted-foreground">{unit}</span>
    </p>
  )
}

/**
 * ログイン後の着地点。案C: 個々の勤怠入力・申請一覧・承認一覧を1画面から俯瞰できるように、
 * 各ドメインの既存データ取得手段(hooks)をそのまま束ねるだけの薄いダッシュボード。
 * 申請センター統合APIは別タスクで新設中のため、ここでは既存の個別カウントで代用する
 * (対象外: 申請センター統合画面自体)。
 */
export function HomeDashboardPage() {
  const { user } = useAuth()
  const features = user?.effective_features
  const featureUnknown = features === undefined
  const has = (feature: string) => featureUnknown || Boolean(features?.includes(feature))

  const canSeeAttendance = has('attendance.entry')
  const canSeeWorkflow = has('workflow.requests')
  const canSeeExpense = has('backoffice.expenses')
  const canSeeApprovals =
    has('attendance.timesheet') ||
    has('paid_leave.requests') ||
    has('workflow.requests') ||
    has('backoffice.expenses')
  const canSeeBackOfficeTasks = has('backoffice.tasks')

  const { data: today, isLoading: isTodayLoading } = useTodayAttendance()
  const { data: myRequests, isLoading: isRequestsLoading } = useMyWorkflowRequests(canSeeWorkflow)
  const { data: myExpenses, isLoading: isExpensesLoading } = useMyExpenseClaims(canSeeExpense)
  const { data: toApprove, isLoading: isApprovalsLoading } = useWorkflowRequestsToApprove(
    { status: 'submitted' },
    canSeeApprovals,
  )
  const { data: myTasks, isLoading: isTasksLoading } = useMyBackOfficeTasks({}, canSeeBackOfficeTasks)

  const pendingRequestCount =
    (myRequests?.data.filter((r) => PENDING_WORKFLOW_STATUSES.has(r.status)).length ?? 0) +
    (myExpenses?.data.filter((c) => PENDING_EXPENSE_STATUSES.has(c.status)).length ?? 0)

  const approvalCount = toApprove?.meta.total ?? 0
  const openTaskCount = myTasks?.data.filter((t) => OPEN_BACKOFFICE_STATUSES.has(t.status)).length ?? 0

  return (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
      {canSeeAttendance && (
        <DashboardCard title="今日の勤怠状況" to="/attendance" icon={CalendarClock}>
          {isTodayLoading ? (
            <LoadingState />
          ) : today ? (
            <div className="flex flex-col gap-3">
              {(() => {
                const { label, tone } = attendanceDayDisplayLabel(today)
                return <Badge tone={tone}>{label}</Badge>
              })()}
              <p className="text-sm text-muted-foreground">
                出勤 {formatTime(today.actual_start_at)} / 退勤 {formatTime(today.actual_end_at)}
              </p>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">本日の勤怠情報はまだありません。</p>
          )}
        </DashboardCard>
      )}

      {canSeeWorkflow && (
        <DashboardCard title="自分の申請ステータス" to="/requests" icon={Inbox}>
          {isRequestsLoading || isExpensesLoading ? (
            <LoadingState />
          ) : (
            <div className="flex flex-col gap-1">
              <BigNumber value={pendingRequestCount} unit="件 対応中" />
              <p className="text-sm text-muted-foreground">
                その他申請・経費精算のうち、提出済み・差戻し中の件数の合計です。
              </p>
            </div>
          )}
        </DashboardCard>
      )}

      {canSeeApprovals && (
        <DashboardCard title="承認待ち" to="/approvals" icon={CheckCircle2}>
          {isApprovalsLoading ? (
            <LoadingState />
          ) : (
            <div className="flex flex-col gap-1">
              <BigNumber value={approvalCount} unit="件 承認待ち" />
              <p className="text-sm text-muted-foreground">自分宛の承認待ち申請の件数です。</p>
            </div>
          )}
        </DashboardCard>
      )}

      {canSeeBackOfficeTasks && (
        <DashboardCard title="バックオフィスタスク" to="/backoffice-tasks" icon={ClipboardList}>
          {isTasksLoading ? (
            <LoadingState />
          ) : (
            <div className="flex flex-col gap-1">
              <BigNumber value={openTaskCount} unit="件 未完了" />
              <p className="text-sm text-muted-foreground">自分が担当する未完了タスクの件数です。</p>
            </div>
          )}
        </DashboardCard>
      )}
    </div>
  )
}
