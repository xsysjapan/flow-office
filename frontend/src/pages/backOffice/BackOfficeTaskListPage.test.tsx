import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as backOfficeTasksApi from '../../api/backOfficeTasks'
import { ApiError } from '../../api/client'
import * as usersApi from '../../api/users'
import type { BackOfficeTask, BackOfficeTaskSearchResult, Paginated, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { BackOfficeTaskListPage } from './BackOfficeTaskListPage'

const me: User = {
  id: 'me-1',
  name: '自分 太郎',
  email: 'me@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const colleague: User = {
  id: 'assignee-1',
  name: '担当者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const zeroCounts = { unassigned: 0, me: 0, others: 0, all: 0 }

function makeTask(overrides: Partial<BackOfficeTask> & Pick<BackOfficeTask, 'id' | 'title'>): BackOfficeTask {
  return {
    source_type: 'workflow_request',
    source_id: '1',
    task_type: 'expense_reimbursement',
    status: 'not_started',
    assigned_department: '経理部',
    due_on: null,
    completed_at: null,
    created_at: '2026-07-01T00:00:00+09:00',
    ...overrides,
  }
}

function searchResult(
  data: BackOfficeTask[],
  meta: Partial<BackOfficeTaskSearchResult['meta']> = {},
): BackOfficeTaskSearchResult {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      total: data.length,
      assignee_counts: zeroCounts,
      ...meta,
    },
    links: { next: null, prev: null },
  }
}

function paginate<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

/** UserPickerのトリガー(role=combobox)。comboboxの名前は内容から取れないのでidで特定する */
function userPickerTrigger(): HTMLElement {
  return document.getElementById('bulk-assignee') as HTMLElement
}

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderPage(initialEntries: string[] = ['/backoffice-tasks']) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const authValue: AuthContextValue = {
    user: me,
    status: 'authenticated',
    login: vi.fn(),
    completeLogin: vi.fn(),
    applySession: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={authValue}>
        <MemoryRouter initialEntries={initialEntries}>
          <BackOfficeTaskListPage />
          <LocationProbe />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

describe('BackOfficeTaskListPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(backOfficeTasksApi, 'fetchBackOfficeTasks').mockResolvedValue(searchResult([]))
  })

  it('loads the unassigned tab with the open status by default and keeps the default out of the URL', async () => {
    renderPage()

    expect(await screen.findByText('未割り当てのタスクはありません。')).toBeInTheDocument()
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ assignee: 'unassigned', status: 'open', page: 1 }),
    )
    expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/)
  })

  it('shows the assignee counts on the tabs with accessible names that include the count', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(
      searchResult([], { assignee_counts: { unassigned: 12, me: 5, others: 40, all: 57 } }),
    )

    renderPage()

    expect(await screen.findByRole('tab', { name: '未割り当て(12件)' })).toHaveTextContent('未割り当て 12')
    expect(screen.getByRole('tab', { name: '自分のタスク(5件)' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '他の担当者(40件)' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'すべて(57件)' })).toBeInTheDocument()
  })

  it('lists tasks with status, due date and assignee name', async () => {
    const tasks = [
      makeTask({
        id: 'backoffice-task-1',
        title: 'タクシー代の経理処理',
        status: 'not_started',
        due_on: '2026-07-15',
      }),
      makeTask({
        id: 'backoffice-task-2',
        title: '名刺発注',
        status: 'processing',
        assignee: colleague,
        due_on: '2026-07-20',
      }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))

    renderPage(['/backoffice-tasks?assignee=all'])

    const table = within(await screen.findByRole('table'))
    expect(table.getByRole('link', { name: 'タクシー代の経理処理' })).toHaveAttribute(
      'href',
      '/backoffice-tasks/backoffice-task-1',
    )
    expect(table.getByText('未着手')).toBeInTheDocument()
    expect(table.getByText('期限: 2026-07-15')).toBeInTheDocument()
    expect(table.getByRole('link', { name: '名刺発注' })).toHaveAttribute('href', '/backoffice-tasks/backoffice-task-2')
    expect(table.getByText('処理中')).toBeInTheDocument()
    expect(table.getByText('担当者花子')).toBeInTheDocument()
  })

  it('switches the assignee tab, writes it to the URL and refetches with the new scope', async () => {
    renderPage()
    await screen.findByText('未割り当てのタスクはありません。')

    await userEvent.click(screen.getByRole('tab', { name: '他の担当者(0件)' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?assignee=others$/))
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ assignee: 'others', status: 'open' }),
    )
    expect(await screen.findByText('他の担当者に割り当てられたタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'すべて(0件)' }))
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?assignee=all$/))
  })

  it('writes the status select to the URL and passes it to the API', async () => {
    renderPage()
    await screen.findByText('未割り当てのタスクはありません。')

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'ステータス' }), 'completed')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?status=completed$/))
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'completed', assignee: 'unassigned' }),
    )
  })

  it('passes the keyword to the API and keeps it in the URL', async () => {
    renderPage()

    await userEvent.type(await screen.findByRole('textbox', { name: 'タスクを検索' }), '名刺')
    await userEvent.click(screen.getByRole('button', { name: '検索' }))

    await waitFor(() =>
      expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
        expect.objectContaining({ search: '名刺', assignee: 'unassigned', status: 'open', page: 1 }),
      ),
    )
    expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?q=%E5%90%8D%E5%88%BA$/)
    expect(screen.getByRole('textbox', { name: 'タスクを検索' })).toHaveValue('名刺')
    expect(screen.getByRole('button', { name: 'クリア' })).toBeInTheDocument()
  })

  it('removes invalid assignee, status and page from the URL and falls back to the defaults', async () => {
    renderPage(['/backoffice-tasks?assignee=x&status=y&page=abc&q=a'])

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?q=a$/))
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ assignee: 'unassigned', status: 'open', search: 'a', page: 1 }),
    )
  })

  it('disables bulk assignment with a reason when the selection contains an assigned task', async () => {
    const tasks = [
      makeTask({ id: 'task-unassigned', title: '未割り当てのタスク' }),
      makeTask({ id: 'task-assigned', title: '割当済みのタスク', assignee: colleague }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: '未割り当てのタスクを選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '割当済みのタスクを選択' }))

    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '割り当てる' })).toBeDisabled()
    expect(screen.getByText('未割り当てのタスクのみ割り当てられます')).toBeInTheDocument()
    expect(screen.queryByText('担当者を選択してください')).not.toBeInTheDocument()
  })

  it('bulk-assigns selected unassigned tasks to the picked user', async () => {
    const tasks = [
      makeTask({ id: 'backoffice-task-1', title: 'タクシー代の経理処理' }),
      makeTask({ id: 'backoffice-task-3', title: '名刺の再作成', task_type: 'business_card_order' }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginate([colleague]))
    const assignSpy = vi.spyOn(backOfficeTasksApi, 'assignBackOfficeTask').mockResolvedValue(tasks[0])

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タクシー代の経理処理を選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '名刺の再作成を選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '割り当てる' })).toBeDisabled()
    expect(screen.getByText('担当者を選択してください')).toBeInTheDocument()

    await userEvent.click(userPickerTrigger())
    await userEvent.type(await screen.findByPlaceholderText('担当者を選択'), '花子')
    await userEvent.click(await screen.findByRole('option', { name: '担当者花子(hanako@example.com)' }))

    expect(screen.getByRole('button', { name: '割り当てる' })).toBeEnabled()
    await userEvent.click(screen.getByRole('button', { name: '割り当てる' }))

    await waitFor(() => expect(assignSpy).toHaveBeenCalledTimes(2))
    expect(assignSpy).toHaveBeenCalledWith('backoffice-task-1', 'assignee-1')
    expect(assignSpy).toHaveBeenCalledWith('backoffice-task-3', 'assignee-1')
  })

  it('disables bulk completion with a reason when the selection contains others or unassigned tasks', async () => {
    const tasks = [
      makeTask({ id: 'my-task-1', title: '自分の確認1', status: 'processing', assignee: me }),
      makeTask({ id: 'other-task-1', title: '他人の確認1', status: 'processing', assignee: colleague }),
      makeTask({ id: 'free-task-1', title: '未割当の確認1', status: 'not_started' }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))

    renderPage(['/backoffice-tasks?assignee=all'])

    await userEvent.click(await screen.findByRole('checkbox', { name: '自分の確認1を選択' }))
    expect(screen.getByRole('button', { name: '選択したタスクを完了' })).toBeEnabled()

    await userEvent.click(screen.getByRole('checkbox', { name: '他人の確認1を選択' }))
    expect(screen.getByRole('button', { name: '選択したタスクを完了' })).toBeDisabled()
    expect(screen.getByText('自分のタスクのみ一括完了できます')).toBeInTheDocument()
  })

  it('bulk-completes my own tasks', async () => {
    const tasks = [
      makeTask({ id: 'my-task-1', title: '月次勤怠確認1', status: 'processing', assignee: me }),
      makeTask({ id: 'my-task-2', title: '月次勤怠確認2', status: 'in_review', assignee: me }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    const completeSpy = vi.spyOn(backOfficeTasksApi, 'bulkCompleteBackOfficeTasks').mockResolvedValue(tasks)

    renderPage(['/backoffice-tasks?assignee=me'])

    await userEvent.click(await screen.findByRole('checkbox', { name: 'このページのタスクをすべて選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '選択したタスクを完了' }))

    await waitFor(() => expect(completeSpy).toHaveBeenCalledWith(['my-task-1', 'my-task-2']))
  })

  it('clears the selection when the tab changes', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(
      searchResult([makeTask({ id: 'task-1', title: '未割当' })]),
    )

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: '未割当を選択' }))
    expect(screen.getByText('1件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'すべて(0件)' }))

    await waitFor(() => expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument())
  })

  it('clears the selection when the page changes', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(
      searchResult([makeTask({ id: 'task-1', title: '検索対象' })], { last_page: 2, total: 21 }),
    )

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: '検索対象を選択' }))
    expect(screen.getByText('1件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?page=2$/))
    expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument()
  })

  it('shows a tab-specific empty state when no keyword or non-default status is set', async () => {
    renderPage()

    expect(await screen.findByText('未割り当てのタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: '自分のタスク(0件)' }))
    expect(await screen.findByText('担当中のタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: '他の担当者(0件)' }))
    expect(await screen.findByText('他の担当者に割り当てられたタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'すべて(0件)' }))
    expect(await screen.findByText('タスクはありません。')).toBeInTheDocument()
    expect(screen.queryByText('条件に一致するタスクがありません。')).not.toBeInTheDocument()
  })

  it('shows a filtered empty state with a clear action when a keyword is set', async () => {
    renderPage(['/backoffice-tasks?q=%E5%AD%98%E5%9C%A8%E3%81%97%E3%81%AA%E3%81%84'])

    expect(await screen.findByText('条件に一致するタスクがありません。')).toBeInTheDocument()
    expect(screen.queryByText('未割り当てのタスクはありません。')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '検索条件をクリア' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
  })

  it('shows a permission denied state instead of a generic error on 403', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockRejectedValue(new ApiError(403, 'Forbidden'))

    renderPage()

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })
})
