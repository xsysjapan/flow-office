import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as backOfficeTasksApi from '../../api/backOfficeTasks'
import * as attendanceApi from '../../api/attendance'
import { ApiError } from '../../api/client'
import * as exportsApi from '../../api/exports'
import * as usersApi from '../../api/users'
import * as workflowRequestsApi from '../../api/workflowRequests'
import type {
  AttendanceMonth,
  AttendanceMonthlyCalculationTotals,
  BackOfficeTask,
  Paginated,
  User,
  WorkflowRequest,
} from '../../api/types'
import { BackOfficeTaskDetailPage } from './BackOfficeTaskDetailPage'

const assignee: User = {
  id: 'assignee-1',
  name: '担当者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
  effective_permissions: [
    'attendance.month_reopen',
    'backoffice_task.execute',
    'attendance.export',
    'attendance.confirmation_revert',
  ],
}

const zeroMonthlyCalculationTotals: AttendanceMonthlyCalculationTotals = {
  work_minutes: 0,
  payroll_work_minutes: 0,
  prescribed_work_minutes: 0,
  statutory_within_overtime_minutes: 0,
  statutory_excess_overtime_minutes: 0,
  statutory_excess_overtime_within_60h_minutes: 0,
  statutory_excess_overtime_over_60h_minutes: 0,
  weekly_statutory_excess_overtime_minutes: 0,
  late_night_work_minutes: 0,
  late_night_prescribed_work_minutes: 0,
  late_night_statutory_within_overtime_minutes: 0,
  late_night_statutory_excess_overtime_minutes: 0,
  legal_holiday_work_minutes: 0,
  prescribed_holiday_work_minutes: 0,
  late_night_legal_holiday_work_minutes: 0,
  late_night_prescribed_holiday_work_minutes: 0,
}

/** AttendanceMonthConfirmationSectionはuseAttendanceMonthByIdで対象月を解決した後、
 *  実際の表示・CSV/Excel出力・状態変更はAttendanceMonthReferenceTabs(useAttendanceMonth→
 *  fetchMonth)に委譲するため、両方のAPIをモックする必要がある。 */
function mockFetchMonth(month: AttendanceMonth) {
  return vi.spyOn(attendanceApi, 'fetchMonth').mockResolvedValue({
    days: [],
    month,
    flex_settlement_summary: null,
    monthly_calculation_totals: zeroMonthlyCalculationTotals,
  })
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: assignee }),
}))

const baseTask: BackOfficeTask = {
  id: 'backoffice-task-1',
  source_type: 'workflow_request',
  source_id: '10',
  task_type: 'expense_reimbursement',
  title: 'タクシー代の経理処理',
  status: 'not_started',
  assigned_department: '経理部',
  due_on: '2026-07-15',
  completed_at: null,
  created_at: '2026-07-01T00:00:00+09:00',
}

function renderPage(task: BackOfficeTask) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(backOfficeTasksApi, 'fetchBackOfficeTask').mockResolvedValue(task)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/backoffice-tasks/${task.id}`]}>
        <Routes>
          <Route path="/backoffice-tasks/:id" element={<BackOfficeTaskDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('BackOfficeTaskDetailPage', () => {
  it('shows a permission denied state instead of a generic error on 403', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.spyOn(backOfficeTasksApi, 'fetchBackOfficeTask').mockRejectedValue(new ApiError(403, 'Forbidden'))

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/backoffice-tasks/backoffice-task-1']}>
          <Routes>
            <Route path="/backoffice-tasks/:id" element={<BackOfficeTaskDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })

  it('shows task details and the status change control', async () => {
    renderPage(baseTask)

    expect(await screen.findByText('タクシー代の経理処理')).toBeInTheDocument()
    expect(screen.getByText('expense_reimbursement')).toBeInTheDocument()
    expect(screen.getByText('workflow_request #10')).toBeInTheDocument()
    expect(screen.getByText('未着手', { selector: 'span' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '更新する' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '← タスク一覧に戻る' })).toHaveAttribute('href', '/backoffice-tasks')
  })

  it('shows the assignee picker when the task has no assignee', async () => {
    renderPage(baseTask)

    expect(await screen.findByRole('button', { name: '割り当てる' })).toBeInTheDocument()
    expect(screen.getByText('未割り当て')).toBeInTheDocument()
  })

  it('hides the assignee picker when the task already has an assignee', async () => {
    renderPage({ ...baseTask, assignee })

    await screen.findByText('タクシー代の経理処理')
    expect(screen.queryByRole('button', { name: '割り当てる' })).not.toBeInTheDocument()
    expect(screen.getByText('担当者花子')).toBeInTheDocument()
  })

  it('assigns the selected user when 割り当てる is clicked', async () => {
    const paginatedUsers: Paginated<User> = {
      data: [assignee],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    }
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(backOfficeTasksApi, 'assignBackOfficeTask').mockResolvedValue({ ...baseTask, assignee })

    renderPage(baseTask)

    await userEvent.click(await screen.findByRole('combobox', { name: '担当者' }))
    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '花子')
    await userEvent.click(await screen.findByRole('option', { name: '担当者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '割り当てる' }))

    await waitFor(() =>
      expect(backOfficeTasksApi.assignBackOfficeTask).toHaveBeenCalledWith('backoffice-task-1', 'assignee-1'),
    )
  })

  it('changes the status with a comment when 更新する is clicked', async () => {
    vi.spyOn(backOfficeTasksApi, 'changeBackOfficeTaskStatus').mockResolvedValue({
      ...baseTask,
      status: 'processing',
    })

    renderPage(baseTask)

    await screen.findByText('タクシー代の経理処理')
    await userEvent.selectOptions(screen.getByLabelText('状態'), '処理中')
    await userEvent.type(screen.getByLabelText('コメント(任意)'), '発注しました')
    await userEvent.click(screen.getByRole('button', { name: '更新する' }))

    await waitFor(() =>
      expect(backOfficeTasksApi.changeBackOfficeTaskStatus).toHaveBeenCalledWith('backoffice-task-1', 'processing', '発注しました'),
    )
  })

  describe('attendance_month_confirmation task', () => {
    const attendanceMonthTask: BackOfficeTask = {
      ...baseTask,
      task_type: 'attendance_month_confirmation',
      source_type: 'attendance_month',
      source_id: 'attendance-month-1',
    }

    const baseMonth: AttendanceMonth = {
      id: 'attendance-month-1',
      user_id: 'user-1',
      year_month: '2026-07',
      status: 'approved',
      approver: undefined,
      submitted_at: '2026-07-31T00:00:00+09:00',
      approved_at: '2026-08-01T00:00:00+09:00',
      returned_at: null,
      return_comment: null,
      closed_at: null,
      snapshot: null,
      legal_holiday_warnings: [],
    }

    it('shows a 締める button when the attendance month is not closed', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue(baseMonth)
      mockFetchMonth(baseMonth)

      renderPage(attendanceMonthTask)

      expect(await screen.findByRole('button', { name: '締める' })).toBeInTheDocument()
    })

    it('closes the month and refetches after confirming in the dialog', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue(baseMonth)
      mockFetchMonth(baseMonth)
      vi.spyOn(attendanceApi, 'closeMonth').mockResolvedValue({ ...baseMonth, status: 'closed' })

      renderPage(attendanceMonthTask)

      await userEvent.click(await screen.findByRole('button', { name: '締める' }))
      expect(screen.getByText(/この操作は元に戻せません/)).toBeInTheDocument()
      await userEvent.click(screen.getByRole('button', { name: '締めを確定する' }))

      await waitFor(() => expect(attendanceApi.closeMonth).toHaveBeenCalledWith('attendance-month-1'))
    })

    it('downloads CSV and Excel for the attendance month from the task detail', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue(baseMonth)
      mockFetchMonth(baseMonth)
      const csvSpy = vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
      const excelSpy = vi.spyOn(exportsApi, 'downloadAttendanceExcel').mockResolvedValue(undefined)

      renderPage(attendanceMonthTask)

      await userEvent.click(await screen.findByRole('button', { name: 'CSV出力' }))
      await userEvent.click(screen.getByRole('button', { name: 'Excel出力' }))

      await waitFor(() =>
        expect(csvSpy).toHaveBeenCalledWith({
          year_month: ['2026-07'],
          user_id: ['user-1'],
          format: 'generic',
        }),
      )
      expect(excelSpy).toHaveBeenCalledWith({ year_month: ['2026-07'], user_id: ['user-1'] })
    })

    it('keeps the attendance month visible but hides the 締める button when already closed', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue({ ...baseMonth, status: 'closed' })
      mockFetchMonth({ ...baseMonth, status: 'closed' })

      renderPage(attendanceMonthTask)

      expect(await screen.findByText('月次勤怠')).toBeInTheDocument()
      expect(screen.getByText('2026-07')).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: '締める' })).not.toBeInTheDocument()
    })

    it('lets a user with attendance.month_reopen reopen an already-closed month from the task detail', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue({ ...baseMonth, status: 'closed' })
      mockFetchMonth({ ...baseMonth, status: 'closed' })
      const reopenMonth = vi
        .spyOn(attendanceApi, 'reopenMonth')
        .mockResolvedValue({ ...baseMonth, status: 'approved' })

      renderPage(attendanceMonthTask)

      await userEvent.click(await screen.findByRole('button', { name: '締めを取り消す' }))
      await userEvent.type(await screen.findByLabelText('取消理由'), 'タスク処理中に誤りに気づいたため')
      await userEvent.click(screen.getByRole('button', { name: '締めを取り消す' }))

      await waitFor(() =>
        expect(reopenMonth).toHaveBeenCalledWith('attendance-month-1', 'タスク処理中に誤りに気づいたため'),
      )
    })

    it('places the back-office status change above the attendance month closeout section', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue(baseMonth)
      mockFetchMonth(baseMonth)

      const { container } = renderPage(attendanceMonthTask)

      await screen.findByText('月次勤怠の締め処理')
      const content = container.textContent ?? ''
      expect(content.indexOf('状態を変更する')).toBeLessThan(content.indexOf('月次勤怠の締め処理'))
    })

    it('does not show the attendance month section for other task types', async () => {
      vi.spyOn(attendanceApi, 'fetchAttendanceMonthById').mockResolvedValue(baseMonth)

      renderPage(baseTask)

      await screen.findByText('タクシー代の経理処理')
      expect(screen.queryByText('月次勤怠の締め処理')).not.toBeInTheDocument()
    })
  })

  describe('attendance_confirmation_revert task', () => {
    const confirmationRevertTask: BackOfficeTask = {
      ...baseTask,
      task_type: 'attendance_confirmation_revert',
      source_type: 'workflow_request',
      source_id: 'workflow-request-1',
    }

    const applicant: User = {
      id: 'user-1',
      name: '申請者太郎',
      email: 'taro@example.com',
      department: null,
      job_title: null,
      employment_status: 'active',
      last_login_at: null,
    }

    const revertRequest: WorkflowRequest = {
      id: 'workflow-request-1',
      title: '勤怠確定取消依頼: 2026-07',
      status: 'approved',
      form_data: { target_year_month: '2026-07', reason: '打刻の登録漏れがあったため' },
      applicant,
      submitted_at: '2026-08-01T00:00:00+09:00',
      approved_at: '2026-08-02T00:00:00+09:00',
      returned_at: null,
      cancelled_at: null,
      created_at: '2026-08-01T00:00:00+09:00',
    }

    const approvedMonth: AttendanceMonth = {
      id: 'attendance-month-1',
      user_id: 'user-1',
      year_month: '2026-07',
      status: 'approved',
      approver: undefined,
      submitted_at: '2026-07-31T00:00:00+09:00',
      approved_at: '2026-08-01T00:00:00+09:00',
      returned_at: null,
      return_comment: null,
      closed_at: null,
      snapshot: null,
      legal_holiday_warnings: [],
    }

    it('shows the applicant, target month, and reason from the approved request', async () => {
      vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(revertRequest)
      mockFetchMonth(approvedMonth)

      renderPage(confirmationRevertTask)

      expect(await screen.findByText('申請者太郎')).toBeInTheDocument()
      expect(screen.getAllByText('2026-07').length).toBeGreaterThan(0)
      expect(screen.getByText('打刻の登録漏れがあったため')).toBeInTheDocument()
    })

    it('reverts the confirmation when a user with attendance.confirmation_revert confirms the dialog', async () => {
      vi.spyOn(workflowRequestsApi, 'fetchWorkflowRequest').mockResolvedValue(revertRequest)
      mockFetchMonth(approvedMonth)
      const revertConfirmation = vi
        .spyOn(attendanceApi, 'revertMonthConfirmation')
        .mockResolvedValue({ ...approvedMonth, status: 'not_submitted' })

      renderPage(confirmationRevertTask)

      await userEvent.click(await screen.findByRole('button', { name: '確定を取り消す' }))
      await userEvent.type(await screen.findByLabelText('取消理由'), '手続きミスが判明したため')
      await userEvent.click(screen.getByRole('button', { name: '確定取消を実行する' }))

      await waitFor(() =>
        expect(revertConfirmation).toHaveBeenCalledWith('attendance-month-1', '手続きミスが判明したため', 'workflow-request-1'),
      )
    })
  })
})
