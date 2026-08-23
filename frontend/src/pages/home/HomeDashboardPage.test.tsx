import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as backOfficeTasksApi from '../../api/backOfficeTasks'
import * as expenseClaimsApi from '../../api/expenseClaims'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type { AttendanceDay, BackOfficeTask, ExpenseClaim, User, WorkflowRequest } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { HomeDashboardPage } from './HomeDashboardPage'

const mockUser: User = {
  id: 'user-1',
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '開発部',
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  effective_features: [
    'attendance.entry',
    'workflow.requests',
    'backoffice.expenses',
    'backoffice.tasks',
  ],
}

const todayAttendance: AttendanceDay = {
  work_date: '2026-08-22',
  status: 'working',
  planned_start_at: null,
  planned_end_at: null,
  actual_start_at: '2026-08-22T09:00:00+09:00',
  actual_end_at: null,
  breaks: [],
  calculation: null,
  monthly_overtime: null,
} as unknown as AttendanceDay

function paginated<T>(data: T[]) {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 }, links: { next: null, prev: null } }
}

function renderPage(user: User = mockUser) {
  const authValue: AuthContextValue = {
    user,
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    applySession: vi.fn(),
    logout: vi.fn(),
  }
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={authValue}>
        <MemoryRouter>
          <HomeDashboardPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

describe('HomeDashboardPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(attendanceApi, 'fetchToday').mockResolvedValue(todayAttendance)
    vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
      days: [],
      month: null,
      flex_settlement_summary: null,
    } as unknown as Awaited<ReturnType<typeof attendanceApi.fetchMonth>>)
    vi.spyOn(workflowRequestsApi, 'fetchMyWorkflowRequests').mockResolvedValue(
      paginated([{ id: 'r1', status: 'submitted' } as WorkflowRequest]),
    )
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(
      paginated([{ id: 'e1', status: 'in_review' } as ExpenseClaim]),
    )
    vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequestsToApprove').mockResolvedValue(
      paginated([{ id: 'a1' } as WorkflowRequest]),
    )
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(
      paginated([{ id: 't1', status: 'not_started' } as BackOfficeTask]),
    )
  })

  it('shows today’s attendance panel', async () => {
    renderPage()

    expect(await screen.findByText('今日の勤怠')).toBeInTheDocument()
    expect(await screen.findByText('勤務中')).toBeInTheDocument()
  })

  it('shows the pending request count summing workflow requests and expense claims', async () => {
    renderPage()

    expect(await screen.findByText('自分の申請ステータス')).toBeInTheDocument()
    expect(
      await screen.findByText((_, element) => element?.tagName.toLowerCase() === 'p' && element.textContent === '2件 対応中'),
    ).toBeInTheDocument()
  })

  it('shows the approvals-pending count', async () => {
    renderPage()

    expect(await screen.findByText('承認待ち')).toBeInTheDocument()
    expect(workflowRequestsApi.fetchWorkflowRequestsToApprove).toHaveBeenCalled()
  })

  it('shows the open back-office task count', async () => {
    renderPage()

    expect(await screen.findByText('バックオフィスタスク')).toBeInTheDocument()
    expect(backOfficeTasksApi.fetchMyTasks).toHaveBeenCalled()
  })

  it('hides cards for features the user does not have', async () => {
    renderPage({ ...mockUser, effective_features: ['attendance.entry'] })

    expect(await screen.findByText('今日の勤怠')).toBeInTheDocument()
    expect(screen.queryByText('自分の申請ステータス')).not.toBeInTheDocument()
    expect(screen.queryByText('承認待ち')).not.toBeInTheDocument()
    expect(screen.queryByText('バックオフィスタスク')).not.toBeInTheDocument()
  })
})
