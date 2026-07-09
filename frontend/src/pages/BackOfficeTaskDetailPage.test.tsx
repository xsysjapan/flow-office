import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as backOfficeTasksApi from '../api/backOfficeTasks'
import * as usersApi from '../api/users'
import type { BackOfficeTask, Paginated, User } from '../api/types'
import { BackOfficeTaskDetailPage } from './BackOfficeTaskDetailPage'

const assignee: User = {
  id: 2,
  name: '担当者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const baseTask: BackOfficeTask = {
  id: 1,
  source_type: 'workflow_request',
  source_id: 10,
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
  it('shows task details and the status change control', async () => {
    renderPage(baseTask)

    expect(await screen.findByText('タクシー代の経理処理')).toBeInTheDocument()
    expect(screen.getByText('expense_reimbursement')).toBeInTheDocument()
    expect(screen.getByText('workflow_request #10')).toBeInTheDocument()
    expect(screen.getByText('未着手', { selector: 'span' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '更新する' })).toBeInTheDocument()
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
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    vi.spyOn(backOfficeTasksApi, 'assignBackOfficeTask').mockResolvedValue({ ...baseTask, assignee })

    renderPage(baseTask)

    await userEvent.type(await screen.findByPlaceholderText('氏名またはメールアドレスで検索'), '花子')
    await userEvent.click(await screen.findByRole('button', { name: '担当者花子(hanako@example.com)' }))
    await userEvent.click(screen.getByRole('button', { name: '割り当てる' }))

    await waitFor(() =>
      expect(backOfficeTasksApi.assignBackOfficeTask).toHaveBeenCalledWith(1, 2),
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
      expect(backOfficeTasksApi.changeBackOfficeTaskStatus).toHaveBeenCalledWith(1, 'processing', '発注しました'),
    )
  })
})
