import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as backOfficeTasksApi from '../../api/backOfficeTasks'
import { ApiError } from '../../api/client'
import * as usersApi from '../../api/users'
import type { BackOfficeTask, Paginated, User } from '../../api/types'
import { BackOfficeTaskListPage } from './BackOfficeTaskListPage'

function paginate<T>(data: T[], meta?: Partial<Paginated<T>['meta']>): Paginated<T> {
  return {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length, ...meta },
    links: { next: null, prev: null },
  }
}

function renderPage(initialEntries: string[] = ['/backoffice-tasks']) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={initialEntries}>
        <BackOfficeTaskListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('BackOfficeTaskListPage', () => {
  it('shows empty states when there are no tasks', async () => {
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockResolvedValue(paginate([]))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate([]))

    renderPage()

    expect(await screen.findByText('未割り当てのタスクはありません。')).toBeInTheDocument()
    expect(screen.getByText('担当中のタスクはありません。')).toBeInTheDocument()
  })

  it('lists unassigned tasks and my tasks with status and due date', async () => {
    const unassigned: BackOfficeTask[] = [
      {
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
      },
    ]
    const mine: BackOfficeTask[] = [
      {
        id: 'backoffice-task-2',
        source_type: 'workflow_request',
        source_id: '11',
        task_type: 'business_card_order',
        title: '名刺発注',
        status: 'processing',
        assigned_department: '総務部',
        assignee: {
          id: 'assignee-1',
          name: '担当者花子',
          email: 'hanako@example.com',
          department: null,
          job_title: null,
          employment_status: 'active',
          last_login_at: null,
        },
        due_on: '2026-07-20',
        completed_at: null,
        created_at: '2026-07-02T00:00:00+09:00',
      },
    ]
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockResolvedValue(paginate(unassigned))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate(mine))

    renderPage()

    expect(await screen.findByRole('link', { name: 'タクシー代の経理処理' })).toHaveAttribute(
      'href',
      '/backoffice-tasks/backoffice-task-1',
    )
    expect(screen.getByText('未着手')).toBeInTheDocument()
    expect(screen.getByText('期限: 2026-07-15')).toBeInTheDocument()

    expect(screen.getByRole('link', { name: '名刺発注' })).toHaveAttribute('href', '/backoffice-tasks/backoffice-task-2')
    expect(screen.getByText('処理中')).toBeInTheDocument()
    expect(screen.getByText('担当者花子')).toBeInTheDocument()
  })

  it('bulk-assigns selected unassigned tasks to the picked user', async () => {
    const unassigned: BackOfficeTask[] = [
      {
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
      },
      {
        id: 'backoffice-task-3',
        source_type: 'workflow_request',
        source_id: '12',
        task_type: 'business_card_order',
        title: '名刺の再作成',
        status: 'not_started',
        assigned_department: '総務部',
        due_on: '2026-07-16',
        completed_at: null,
        created_at: '2026-07-03T00:00:00+09:00',
      },
    ]
    const pickedUser: User = {
      id: 'assignee-1',
      name: '担当者花子',
      email: 'hanako@example.com',
      department: null,
      job_title: null,
      employment_status: 'active',
      last_login_at: null,
    }
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockResolvedValue(paginate(unassigned))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate([]))
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginate([pickedUser]))
    const assignSpy = vi.spyOn(backOfficeTasksApi, 'assignBackOfficeTask').mockResolvedValue(unassigned[0])

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代の経理処理を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '名刺の再作成を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.type(await screen.findByPlaceholderText('担当者を選択'), '花子')
    await userEvent.click(await screen.findByRole('option', { name: '担当者花子(hanako@example.com)' }))

    await userEvent.click(screen.getByRole('button', { name: '割り当てる' }))

    await waitFor(() => expect(assignSpy).toHaveBeenCalledTimes(2))
    expect(assignSpy).toHaveBeenCalledWith('backoffice-task-1', 'assignee-1')
    expect(assignSpy).toHaveBeenCalledWith('backoffice-task-3', 'assignee-1')
  })

  it('searches both task lists and pages them independently', async () => {
    const task: BackOfficeTask = {
      id: 'search-task',
      source_type: 'workflow_request',
      source_id: '10',
      task_type: 'business_card',
      title: '検索対象',
      status: 'not_started',
      assigned_department: '総務部',
      due_on: null,
      completed_at: null,
      created_at: '2026-08-01T00:00:00+09:00',
    }
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks')
      .mockResolvedValueOnce(paginate([task], { last_page: 2, total: 21 }))
      .mockResolvedValueOnce(paginate([task], { last_page: 2, total: 21 }))
      .mockResolvedValue(paginate([task], { current_page: 2, last_page: 2, total: 21 }))
    const mineSpy = vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate([]))

    renderPage()

    await screen.findByText('検索対象')
    await userEvent.type(screen.getByRole('textbox', { name: 'タスクを検索' }), '月次勤怠')
    await userEvent.click(screen.getByRole('button', { name: '検索' }))

    await waitFor(() =>
      expect(mineSpy).toHaveBeenCalledWith({ search: '月次勤怠', page: 1 }),
    )

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))
    await waitFor(() =>
      expect(backOfficeTasksApi.fetchUnassignedTasks).toHaveBeenCalledWith({ search: '月次勤怠', page: 2 }),
    )
  })

  it('selects the current page and bulk-completes my tasks', async () => {
    const myTasks: BackOfficeTask[] = [
      {
        id: 'my-task-1',
        source_type: 'attendance_month',
        source_id: 'month-1',
        task_type: 'attendance_month_confirmation',
        title: '月次勤怠確認1',
        status: 'processing',
        assigned_department: '人事部',
        due_on: null,
        completed_at: null,
        created_at: '2026-08-01T00:00:00+09:00',
      },
      {
        id: 'my-task-2',
        source_type: 'attendance_month',
        source_id: 'month-2',
        task_type: 'attendance_month_confirmation',
        title: '月次勤怠確認2',
        status: 'in_review',
        assigned_department: '人事部',
        due_on: null,
        completed_at: null,
        created_at: '2026-08-02T00:00:00+09:00',
      },
    ]
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockResolvedValue(paginate([]))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate(myTasks))
    const completeSpy = vi.spyOn(backOfficeTasksApi, 'bulkCompleteBackOfficeTasks').mockResolvedValue(myTasks)

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'このページのタスクをすべて選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '選択したタスクを完了' }))

    await waitFor(() => expect(completeSpy).toHaveBeenCalledWith(['my-task-1', 'my-task-2']))
  })

  it('shows a filtered empty state distinct from the initial empty state when search has no matches', async () => {
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockResolvedValue(paginate([]))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate([]))

    renderPage(['/backoffice-tasks?q=%E5%AD%98%E5%9C%A8%E3%81%97%E3%81%AA%E3%81%84'])

    expect(await screen.findAllByText('条件に一致するタスクがありません。')).toHaveLength(2)
    expect(screen.getAllByRole('button', { name: '検索条件をクリア' }).length).toBeGreaterThan(0)
    expect(screen.queryByText('未割り当てのタスクはありません。')).not.toBeInTheDocument()
  })

  it('keeps the search term in the URL so it survives navigation and reload', async () => {
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockResolvedValue(paginate([]))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate([]))

    renderPage()

    await userEvent.type(await screen.findByRole('textbox', { name: 'タスクを検索' }), '名刺')
    await userEvent.click(screen.getByRole('button', { name: '検索' }))

    await waitFor(() =>
      expect(backOfficeTasksApi.fetchUnassignedTasks).toHaveBeenCalledWith({ search: '名刺', page: 1 }),
    )
    expect(screen.getByRole('textbox', { name: 'タスクを検索' })).toHaveValue('名刺')
    expect(screen.getByRole('button', { name: 'クリア' })).toBeInTheDocument()
  })

  it('shows a permission denied state instead of a generic error on 403', async () => {
    vi.spyOn(backOfficeTasksApi, 'fetchUnassignedTasks').mockRejectedValue(new ApiError(403, 'Forbidden'))
    vi.spyOn(backOfficeTasksApi, 'fetchMyTasks').mockResolvedValue(paginate([]))

    renderPage()

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })
})
