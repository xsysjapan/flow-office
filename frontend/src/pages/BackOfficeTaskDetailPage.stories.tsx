import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { BackOfficeTask } from '../api/types'
import { BackOfficeTaskDetailPage } from './BackOfficeTaskDetailPage'

const assignee = {
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

function withSeeded(task: BackOfficeTask) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['backoffice-tasks', task.id], task)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/backoffice-tasks/${task.id}`]}>
          <Routes>
            <Route path="/backoffice-tasks/:id" element={<BackOfficeTaskDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/BackOfficeTaskDetailPage',
  component: BackOfficeTaskDetailPage,
} satisfies Meta<typeof BackOfficeTaskDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const Unassigned: Story = {
  render: withSeeded(baseTask),
}

export const Assigned: Story = {
  render: withSeeded({ ...baseTask, status: 'processing', assignee }),
}

export const Completed: Story = {
  render: withSeeded({
    ...baseTask,
    status: 'completed',
    assignee,
    completed_at: '2026-07-10T09:00:00+09:00',
  }),
}
