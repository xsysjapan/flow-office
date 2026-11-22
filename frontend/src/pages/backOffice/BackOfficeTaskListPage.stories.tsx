import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'
import { MemoryRouter } from 'react-router-dom'
import type { BackOfficeTask, BackOfficeTaskSearchResult, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { BackOfficeTaskListPage } from './BackOfficeTaskListPage'

const currentUser: User = {
  id: 'user-1',
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '人事部',
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

const authValue: AuthContextValue = {
  user: currentUser,
  status: 'authenticated',
  login: async () => {},
  completeLogin: async () => {},
  applySession: () => {},
  logout: async () => {},
}

function makeTask(overrides: Partial<BackOfficeTask> & Pick<BackOfficeTask, 'id' | 'title'>): BackOfficeTask {
  return {
    source_type: 'workflow_request',
    source_id: '1',
    task_type: 'expense_reimbursement',
    status: 'not_started',
    assigned_department: '経理部',
    due_on: '2026-07-15',
    completed_at: null,
    created_at: '2026-07-01T00:00:00+09:00',
    ...overrides,
  }
}

const unassignedTasks: BackOfficeTask[] = [
  makeTask({ id: 'task-1', title: 'タクシー代の経理処理' }),
  makeTask({ id: 'task-2', title: '名刺の再作成', task_type: 'business_card_order', assigned_department: '総務部', due_on: null }),
]

const myTasks: BackOfficeTask[] = [
  makeTask({
    id: 'task-3',
    title: '名刺発注',
    task_type: 'business_card_order',
    status: 'processing',
    assigned_department: '総務部',
    assignee: currentUser,
    due_on: '2026-07-20',
  }),
  makeTask({
    id: 'task-4',
    title: '月次勤怠確認',
    task_type: 'attendance_month_confirmation',
    status: 'in_review',
    assigned_department: '人事部',
    assignee: currentUser,
    due_on: null,
  }),
]

const othersTasks: BackOfficeTask[] = [
  makeTask({
    id: 'task-5',
    title: '経費精算の差戻し対応',
    status: 'needs_fix',
    assignee: colleague,
  }),
]

const completedTasks: BackOfficeTask[] = [
  makeTask({
    id: 'task-6',
    title: '名刺の完了済みタスク',
    task_type: 'business_card_order',
    status: 'completed',
    assignee: colleague,
    completed_at: '2026-07-10T10:00:00+09:00',
  }),
]

function searchResult(
  data: BackOfficeTask[],
  assignee_counts: BackOfficeTaskSearchResult['meta']['assignee_counts'],
): BackOfficeTaskSearchResult {
  return {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length, assignee_counts },
    links: { next: null, prev: null },
  }
}

const counts = { unassigned: 2, me: 2, others: 1, all: 5 }

interface Seed {
  /** 画面が発行するクエリのoptions(search・statusなど。undefinedの項目は省く) */
  options: Record<string, unknown>
  result: BackOfficeTaskSearchResult
}

/** 実際のクエリキー(['backoffice-tasks','list', options])でseedし、初期URLで画面を開く。 */
function StoryFrame({ initialEntry, seeds }: { initialEntry: string; seeds: Seed[] }) {
  const [queryClient] = useState(() => {
    const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
    for (const seed of seeds) {
      client.setQueryData(['backoffice-tasks', 'list', seed.options], seed.result)
    }
    return client
  })

  return (
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={authValue}>
        <MemoryRouter initialEntries={[initialEntry]}>
          <BackOfficeTaskListPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Pages/BackOffice/BackOfficeTaskListPage',
  component: BackOfficeTaskListPage,
} satisfies Meta<typeof BackOfficeTaskListPage>

export default meta
type Story = StoryObj<typeof meta>

/** 既定: 未割り当てタブ・未完了 */
export const UnassignedTab: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks"
      seeds={[
        { options: { assignee: 'unassigned', status: 'open', page: 1 }, result: searchResult(unassignedTasks, counts) },
      ]}
    />
  ),
}

export const MyTasksTab: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks?assignee=me"
      seeds={[{ options: { assignee: 'me', status: 'open', page: 1 }, result: searchResult(myTasks, counts) }]}
    />
  ),
}

export const OthersTasksTab: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks?assignee=others"
      seeds={[{ options: { assignee: 'others', status: 'open', page: 1 }, result: searchResult(othersTasks, counts) }]}
    />
  ),
}

export const AllTasksWithKeyword: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks?assignee=all&status=all&q=名刺"
      seeds={[
        {
          options: { search: '名刺', assignee: 'all', status: 'all', page: 1 },
          result: searchResult([...unassignedTasks.slice(1), ...myTasks.slice(0, 1), ...completedTasks], {
            unassigned: 1,
            me: 1,
            others: 0,
            all: 3,
          }),
        },
      ]}
    />
  ),
}

/** 未割り当てと割当済みを選ぶと、バーに「担当者を変更」が出る。完了は自分のタスクのみなので理由付きで無効 */
export const SelectedWithAssignedTask: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks"
      seeds={[
        {
          options: { assignee: 'unassigned', status: 'open', page: 1 },
          result: searchResult([unassignedTasks[0], ...othersTasks], { ...counts, unassigned: 1, others: 1, all: 2 }),
        },
      ]}
    />
  ),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('checkbox', { name: 'タクシー代の経理処理を選択' }))
    await userEvent.click(canvas.getByRole('checkbox', { name: '経費精算の差戻し対応を選択' }))
    await expect(canvas.getByText('2件を選択中')).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: '担当者を変更' })).toBeEnabled()
    await expect(canvas.getByRole('button', { name: '完了にする' })).toBeDisabled()
    await expect(canvas.getByText('自分のタスクのみ完了にできます')).toBeInTheDocument()
  },
}

/** 「担当者を変更」で一括操作ダイアログが開く(変更先はUserPicker) */
export const AssignDialogOpen: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks"
      seeds={[
        {
          options: { assignee: 'unassigned', status: 'open', page: 1 },
          result: searchResult(unassignedTasks, counts),
        },
      ]}
    />
  ),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('checkbox', { name: 'このページのタスクをすべて選択' }))
    await userEvent.click(canvas.getByRole('button', { name: '担当者を変更' }))
    await expect(await within(document.body).findByText('担当者を変更しますか?')).toBeInTheDocument()
  },
}

/** 自分のタスクだけ選ぶと一括完了が押せる */
export const SelectedMyTasks: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks?assignee=me"
      seeds={[{ options: { assignee: 'me', status: 'open', page: 1 }, result: searchResult(myTasks, counts) }]}
    />
  ),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('checkbox', { name: '名刺発注を選択' }))
    await expect(canvas.getByText('1件を選択中')).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: '完了にする' })).toBeEnabled()
  },
}

/** 「完了にする」で確認ダイアログが開く */
export const CompleteDialogOpen: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks?assignee=me"
      seeds={[{ options: { assignee: 'me', status: 'open', page: 1 }, result: searchResult(myTasks, counts) }]}
    />
  ),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('checkbox', { name: 'このページのタスクをすべて選択' }))
    await userEvent.click(canvas.getByRole('button', { name: '完了にする' }))
    await expect(await within(document.body).findByText('選択した2件を完了にしますか?')).toBeInTheDocument()
  },
}

/** 未割り当てタブが空(既定条件)。タブ別の空状態 */
export const EmptyUnassignedTab: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks"
      seeds={[{ options: { assignee: 'unassigned', status: 'open', page: 1 }, result: searchResult([], counts) }]}
    />
  ),
}

/** 検索語で0件。条件付きの空状態と検索条件のクリア */
export const EmptyFiltered: Story = {
  render: () => (
    <StoryFrame
      initialEntry="/backoffice-tasks?q=存在しない"
      seeds={[
        {
          options: { search: '存在しない', assignee: 'unassigned', status: 'open', page: 1 },
          result: searchResult([], counts),
        },
      ]}
    />
  ),
}
