import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { expect, within } from 'storybook/test'
import type { BackOfficeTask } from '../../api/types'
import { BackOfficeTaskDetailPage } from './BackOfficeTaskDetailPage'

const assignee = {
  id: 'assignee-1',
  name: '担当者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

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
  title: 'Pages/BackOffice/BackOfficeTaskDetailPage',
  component: BackOfficeTaskDetailPage,
} satisfies Meta<typeof BackOfficeTaskDetailPage>

export default meta
type Story = StoryObj<typeof meta>

/** 未割当: 「担当者を割り当てる」フォームが出る */
export const Unassigned: Story = {
  render: withSeeded(baseTask),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('heading', { name: '担当者を割り当てる' })).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: '割り当てる' })).toBeInTheDocument()
  },
}

/** 割当済み・処理中: 「担当者を変更」フォームが出る(完了・取消以外は担当者を変更できる) */
export const Assigned: Story = {
  render: withSeeded({ ...baseTask, status: 'processing', assignee }),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('heading', { name: '担当者を変更' })).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: '変更する' })).toBeInTheDocument()
    await expect(canvas.queryByRole('button', { name: '割り当てる' })).not.toBeInTheDocument()
  },
}

/** 完了: 担当者フォームは出ない */
export const Completed: Story = {
  render: withSeeded({
    ...baseTask,
    status: 'completed',
    assignee,
    completed_at: '2026-07-10T09:00:00+09:00',
  }),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('タクシー代の経理処理')).toBeInTheDocument()
    await expect(canvas.queryByRole('heading', { name: '担当者を変更' })).not.toBeInTheDocument()
    await expect(canvas.queryByRole('button', { name: '変更する' })).not.toBeInTheDocument()
  },
}
