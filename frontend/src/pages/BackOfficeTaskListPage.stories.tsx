import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { BackOfficeTask, Paginated } from '../api/types'
import { BackOfficeTaskListPage } from './BackOfficeTaskListPage'

const assignee = {
  id: 2,
  name: '担当者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const unassignedTasks: BackOfficeTask[] = [
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

const myTasks: BackOfficeTask[] = [
  {
    id: 'backoffice-task-2',
    source_type: 'workflow_request',
    source_id: '11',
    task_type: 'business_card_order',
    title: '名刺発注',
    status: 'processing',
    assigned_department: '総務部',
    assignee,
    due_on: '2026-07-20',
    completed_at: null,
    created_at: '2026-07-02T00:00:00+09:00',
  },
]

function paginate<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

function withSeededLists(unassigned: BackOfficeTask[], mine: BackOfficeTask[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['backoffice-tasks', 'unassigned'], paginate(unassigned))
  queryClient.setQueryData(['backoffice-tasks', 'mine'], paginate(mine))

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <BackOfficeTaskListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/BackOfficeTaskListPage',
  component: BackOfficeTaskListPage,
} satisfies Meta<typeof BackOfficeTaskListPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithTasks: Story = {
  render: withSeededLists(unassignedTasks, myTasks),
}

export const Empty: Story = {
  render: withSeededLists([], []),
}
