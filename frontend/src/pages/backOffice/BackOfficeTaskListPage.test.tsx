import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as backOfficeTasksApi from '../../api/backOfficeTasks'
import { ApiError } from '../../api/client'
import { BULK_REJECTED_NOTICE } from '../../components/BulkActionDialog/BulkActionDialog'
import * as usersApi from '../../api/users'
import type { BackOfficeTask, BackOfficeTaskSearchResult, Paginated, User } from '../../api/types'
import { notify } from '../../lib/notify'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { BackOfficeTaskListPage } from './BackOfficeTaskListPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

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
    vi.mocked(notify.success).mockClear()
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

    expect(await screen.findByRole('tab', { name: '未割り当て 12件' })).toHaveTextContent('未割り当て12')
    expect(screen.getByRole('tab', { name: '自分のタスク 5件' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '他の担当者 40件' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'すべて 57件' })).toBeInTheDocument()
  })

  it('shows the task type as a display name for known codes and as-is for unknown ones', async () => {
    vi.spyOn(backOfficeTasksApi, 'fetchBackOfficeTasks').mockResolvedValue(
      searchResult([
        makeTask({ id: 'backoffice-task-1', title: '経理処理', task_type: 'expense_reimbursement' }),
        makeTask({ id: 'backoffice-task-2', title: '独自の依頼', task_type: '総務の依頼' }),
      ]),
    )

    renderPage()

    await screen.findByText('経理処理')
    expect(screen.getByText('経費の精算')).toBeInTheDocument()
    expect(screen.queryByText('expense_reimbursement')).not.toBeInTheDocument()
    expect(screen.getByText('総務の依頼')).toBeInTheDocument()
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

    await userEvent.click(screen.getByRole('tab', { name: '他の担当者 0件' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?assignee=others$/))
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ assignee: 'others', status: 'open' }),
    )
    expect(await screen.findByText('他の担当者に割り当てられたタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'すべて 0件' }))
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

  it('updates q in the URL 300ms after typing, drops page, and has no search button', async () => {
    renderPage(['/backoffice-tasks?page=2'])

    await userEvent.type(await screen.findByLabelText('キーワード'), '名刺')
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?q=%E5%90%8D%E5%88%BA$/))
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ search: '名刺', assignee: 'unassigned', status: 'open', page: 1 }),
    )
    expect(screen.queryByRole('button', { name: '検索' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: '検索条件をクリア' }).length).toBeGreaterThan(0)
  })

  it('applies the keyword immediately on Enter', async () => {
    renderPage()

    await userEvent.type(await screen.findByLabelText('キーワード'), '名刺{Enter}')

    expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?q=%E5%90%8D%E5%88%BA$/)
  })

  it('labels the keyword and status controls visibly', async () => {
    renderPage()

    expect(await screen.findByLabelText('キーワード')).toBeInTheDocument()
    expect(screen.getByLabelText('ステータス')).toBeInTheDocument()
  })

  it('clears q and status but keeps the assignee tab', async () => {
    renderPage(['/backoffice-tasks?assignee=me&q=a&status=completed&page=2'])

    await userEvent.click(await screen.findByRole('button', { name: '検索条件をクリア' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?assignee=me$/))
    expect(screen.getByLabelText('キーワード')).toHaveValue('')
  })

  it('keeps the tabs and keyword field when the fetch fails with a non-403 error', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockRejectedValue(new ApiError(422, '検索語が不正です。'))

    renderPage(['/backoffice-tasks?q=a'])

    expect(await screen.findByText('検索語が不正です。')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /未割り当て/ })).toBeInTheDocument()
    expect(screen.getByLabelText('キーワード')).toHaveValue('a')
  })

  it('removes invalid assignee, status and page from the URL and falls back to the defaults', async () => {
    renderPage(['/backoffice-tasks?assignee=x&status=y&page=abc&q=a'])

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?q=a$/))
    expect(backOfficeTasksApi.fetchBackOfficeTasks).toHaveBeenLastCalledWith(
      expect.objectContaining({ assignee: 'unassigned', status: 'open', search: 'a', page: 1 }),
    )
  })

  async function pickAssignee(label: string, search: string) {
    await userEvent.click(userPickerTrigger())
    await userEvent.type(await screen.findByPlaceholderText('担当者を選択'), search)
    await userEvent.click(await screen.findByRole('option', { name: label }))
  }

  /** ダイアログのスコープ(role=dialog)。ページ側にも同名のボタンがあるため */
  function dialog() {
    return within(screen.getByRole('dialog'))
  }

  it('shows the bulk bar with the two actions and the clear button only while rows are selected', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(
      searchResult([makeTask({ id: 'task-1', title: '未割当' })]),
    )

    renderPage()

    await screen.findByRole('checkbox', { name: '未割当を選択' })
    expect(screen.queryByRole('button', { name: '担当者を変更' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('checkbox', { name: '未割当を選択' }))
    expect(screen.getByRole('button', { name: '担当者を変更' })).toBeEnabled()
    expect(screen.getByRole('button', { name: '完了にする' })).toBeDisabled()
    expect(screen.getByText('自分のタスクのみ完了にできます')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '選択を解除' }))
    expect(screen.queryByText('1件を選択中')).not.toBeInTheDocument()
  })

  it('changes the assignee of a mixed selection from the dialog, notifies and clears the selection', async () => {
    const tasks = [
      makeTask({ id: 'task-unassigned', title: '未割り当てのタスク' }),
      makeTask({ id: 'task-assigned', title: '割当済みのタスク', status: 'processing', assignee: colleague }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginate([me]))
    const assignSpy = vi.spyOn(backOfficeTasksApi, 'bulkAssignBackOfficeTasks').mockResolvedValue({ data: [] })

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: '未割り当てのタスクを選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '割当済みのタスクを選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    // 旧バー内の入力欄・理由は無い
    expect(screen.queryByText('担当者を選択してください')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '担当者を変更' }))

    expect(await screen.findByText('担当者を変更しますか?')).toBeInTheDocument()
    expect(screen.getByText('選択した2件(未割り当て1件・割り当て済み1件)')).toBeInTheDocument()
    expect(
      screen.getByText(
        'ステータスは変わりません。未着手のタスクは確認中になります(申請種別の遷移ルールで許可されている場合)。新しい担当者と前の担当者に通知されます。',
      ),
    ).toBeInTheDocument()
    // 変更先を選ぶまでは実行できない
    expect(dialog().getByRole('button', { name: '2件の担当者を変更' })).toBeDisabled()

    await pickAssignee('自分 太郎(me@example.com)', '自分')
    expect(screen.getByText('選択した2件(未割り当て1件・割り当て済み1件。変更不要0件)')).toBeInTheDocument()
    await userEvent.click(dialog().getByRole('button', { name: '2件の担当者を変更' }))

    // 一括の担当変更のAPIを1回だけ呼ぶ(件ごとのAPIは呼ばない)
    await waitFor(() => expect(assignSpy).toHaveBeenCalledTimes(1))
    expect(assignSpy).toHaveBeenCalledWith(['task-unassigned', 'task-assigned'], 'me-1')
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('2件のタスクの担当者を自分 太郎さんに変更しました'))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.queryByText('2件を選択中')).not.toBeInTheDocument()
  })

  it('excludes rows already assigned to the picked user as 変更不要 and disables the action when nothing is left', async () => {
    const tasks = [
      makeTask({ id: 'task-a', title: 'タスクA', status: 'processing', assignee: colleague }),
      makeTask({ id: 'task-b', title: 'タスクB', status: 'processing', assignee: me }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginate([colleague, me]))
    const assignSpy = vi.spyOn(backOfficeTasksApi, 'bulkAssignBackOfficeTasks').mockResolvedValue({ data: [] })

    renderPage(['/backoffice-tasks?assignee=all'])

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タスクAを選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'タスクBを選択' }))
    await userEvent.click(screen.getByRole('button', { name: '担当者を変更' }))

    await pickAssignee('担当者花子(hanako@example.com)', '花子')
    expect(screen.getByText('選択した2件(未割り当て0件・割り当て済み2件。変更不要1件)')).toBeInTheDocument()
    await userEvent.click(dialog().getByRole('button', { name: '1件の担当者を変更' }))

    await waitFor(() => expect(assignSpy).toHaveBeenCalledTimes(1))
    // 担当者が既に花子のタスクAは対象外。タスクBだけを変更する
    expect(assignSpy).toHaveBeenCalledWith(['task-b'], 'assignee-1')
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('1件のタスクの担当者を担当者花子さんに変更しました'))
  })

  it('disables the assign action when every selected task is already assigned to the picked user', async () => {
    const tasks = [makeTask({ id: 'task-a', title: 'タスクA', status: 'processing', assignee: colleague })]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginate([colleague]))

    renderPage(['/backoffice-tasks?assignee=all'])

    await userEvent.click(await screen.findByRole('checkbox', { name: 'タスクAを選択' }))
    await userEvent.click(screen.getByRole('button', { name: '担当者を変更' }))
    await pickAssignee('担当者花子(hanako@example.com)', '花子')

    expect(screen.getByText('選択した1件(未割り当て0件・割り当て済み1件。変更不要1件)')).toBeInTheDocument()
    expect(dialog().getByRole('button', { name: '0件の担当者を変更' })).toBeDisabled()
  })

  it('shows every failure with its reason, says nothing was processed and keeps the whole selection when the bulk assign is rejected', async () => {
    const tasks = [
      makeTask({ id: 'task-ok', title: '成功するタスク' }),
      makeTask({ id: 'task-ng', title: '失敗するタスク' }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue(paginate([colleague]))
    vi.spyOn(backOfficeTasksApi, 'bulkAssignBackOfficeTasks').mockRejectedValue(
      new ApiError(
        422,
        '選んだ対象のうち、処理できないものがあります。',
        { 'items.task-ng': ['完了・取消済みのタスクは担当者を変更できません。'] },
        'bulk_operation_rejected',
        [{ id: 'task-ng', label: '失敗するタスク', message: '完了・取消済みのタスクは担当者を変更できません。' }],
      ),
    )

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: '成功するタスクを選択' }))
    await userEvent.click(screen.getByRole('checkbox', { name: '失敗するタスクを選択' }))
    await userEvent.click(screen.getByRole('button', { name: '担当者を変更' }))
    await pickAssignee('担当者花子(hanako@example.com)', '花子')
    await userEvent.click(dialog().getByRole('button', { name: '2件の担当者を変更' }))

    expect(await screen.findByText(BULK_REJECTED_NOTICE)).toBeInTheDocument()
    expect(screen.getByText('失敗するタスク: 完了・取消済みのタスクは担当者を変更できません。')).toBeInTheDocument()
    expect(notify.success).not.toHaveBeenCalled()
    // 何も確定していないので、選択はそのまま残る
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: '成功するタスクを選択', hidden: true })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '失敗するタスクを選択', hidden: true })).toBeChecked()

    const closeButtons = screen.getAllByRole('button', { name: '閉じる' })
    await userEvent.click(closeButtons[closeButtons.length - 1])

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(notify.success).not.toHaveBeenCalled()
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
  })

  it('bulk-completes my own tasks after the confirmation dialog and notifies', async () => {
    const tasks = [
      makeTask({ id: 'my-task-1', title: '月次勤怠確認1', status: 'processing', assignee: me }),
      makeTask({ id: 'my-task-2', title: '月次勤怠確認2', status: 'in_review', assignee: me }),
    ]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    const completeSpy = vi.spyOn(backOfficeTasksApi, 'bulkCompleteBackOfficeTasks').mockResolvedValue({ data: [] })

    renderPage(['/backoffice-tasks?assignee=me'])

    await userEvent.click(await screen.findByRole('checkbox', { name: 'このページのタスクをすべて選択' }))
    expect(screen.getByText('2件を選択中')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '完了にする' }))

    // 確認ダイアログを経るまでAPIは呼ばれない
    expect(await screen.findByText('選択した2件を完了にしますか?')).toBeInTheDocument()
    expect(
      screen.getByText(
        '完了すると担当者を変更できなくなります。申請種別の遷移ルールで完了にできないタスクが含まれていると、何も完了になりません。',
      ),
    ).toBeInTheDocument()
    expect(completeSpy).not.toHaveBeenCalled()

    await userEvent.click(dialog().getByRole('button', { name: '2件を完了にする' }))

    await waitFor(() => expect(completeSpy).toHaveBeenCalledWith(['my-task-1', 'my-task-2']))
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('2件のタスクを完了にしました'))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.queryByText('2件を選択中')).not.toBeInTheDocument()
  })

  it('lists the rejected tasks by title and keeps the selection when bulk completion is rejected', async () => {
    const tasks = [makeTask({ id: 'my-task-1', title: '月次勤怠確認1', status: 'processing', assignee: me })]
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(searchResult(tasks))
    vi.spyOn(backOfficeTasksApi, 'bulkCompleteBackOfficeTasks').mockRejectedValue(
      new ApiError(
        422,
        '選んだ対象のうち、処理できないものがあります。',
        { 'items.my-task-1': ['この処理区分では [processing] から [completed] への変更は許可されていません。'] },
        'bulk_operation_rejected',
        // 応答に表示名が無い場合は、画面が持つタスクのタイトルで表示する
        [{ id: 'my-task-1', message: 'この処理区分では [processing] から [completed] への変更は許可されていません。' }],
      ),
    )

    renderPage(['/backoffice-tasks?assignee=me'])

    await userEvent.click(await screen.findByRole('checkbox', { name: '月次勤怠確認1を選択' }))
    await userEvent.click(screen.getByRole('button', { name: '完了にする' }))
    await userEvent.click(dialog().getByRole('button', { name: '1件を完了にする' }))

    expect(await screen.findByText(BULK_REJECTED_NOTICE)).toBeInTheDocument()
    expect(
      screen.getByText('月次勤怠確認1: この処理区分では [processing] から [completed] への変更は許可されていません。'),
    ).toBeInTheDocument()
    expect(notify.success).not.toHaveBeenCalled()
    expect(screen.getByText('1件を選択中')).toBeInTheDocument()
  })

  it('clears the selection when the tab changes', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockResolvedValue(
      searchResult([makeTask({ id: 'task-1', title: '未割当' })]),
    )

    renderPage()

    await userEvent.click(await screen.findByRole('checkbox', { name: '未割当を選択' }))
    expect(screen.getByText('1件を選択中')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'すべて 0件' }))

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

    await userEvent.click(screen.getByRole('tab', { name: '自分のタスク 0件' }))
    expect(await screen.findByText('担当中のタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: '他の担当者 0件' }))
    expect(await screen.findByText('他の担当者に割り当てられたタスクはありません。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'すべて 0件' }))
    expect(await screen.findByText('タスクはありません。')).toBeInTheDocument()
    expect(screen.queryByText('条件に一致するタスクがありません。')).not.toBeInTheDocument()
  })

  it('shows a filtered empty state with a clear action when a keyword is set', async () => {
    renderPage(['/backoffice-tasks?q=%E5%AD%98%E5%9C%A8%E3%81%97%E3%81%AA%E3%81%84'])

    expect(await screen.findByText('条件に一致するタスクがありません。')).toBeInTheDocument()
    expect(screen.queryByText('未割り当てのタスクはありません。')).not.toBeInTheDocument()

    await userEvent.click(screen.getAllByRole('button', { name: '検索条件をクリア' })[1])

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
  })

  it('shows a permission denied state instead of a generic error on 403', async () => {
    vi.mocked(backOfficeTasksApi.fetchBackOfficeTasks).mockRejectedValue(new ApiError(403, 'Forbidden'))

    renderPage()

    expect(await screen.findByText(/権限がありません/)).toBeInTheDocument()
  })
})
