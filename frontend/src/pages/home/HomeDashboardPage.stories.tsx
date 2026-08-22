import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { AttendanceDay, BackOfficeTask, ExpenseClaim, User, WorkflowRequest } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { HomeDashboardPage } from './HomeDashboardPage'

const mockUser: User = {
  id: 'user-1',
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '開発部',
  job_title: 'エンジニア',
  employment_status: 'active',
  last_login_at: null,
  effective_features: [
    'attendance.entry',
    'workflow.requests',
    'backoffice.expenses',
    'backoffice.tasks',
  ],
}

const mockAuthValue: AuthContextValue = {
  user: mockUser,
  status: 'authenticated',
  login: async () => {},
  completeLogin: async () => {},
  applySession: () => {},
  logout: async () => {},
}

const todayAttendance: AttendanceDay = {
  work_date: '2026-08-22',
  status: 'working',
  planned_start_at: '2026-08-22T09:00:00+09:00',
  planned_end_at: '2026-08-22T18:00:00+09:00',
  actual_start_at: '2026-08-22T09:03:00+09:00',
  actual_end_at: null,
  breaks: [],
  calculation: null,
  monthly_overtime: null,
} as unknown as AttendanceDay

function paginated<T>(data: T[]) {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 }, links: { next: null, prev: null } }
}

function buildQueryClient() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'today'], todayAttendance)
  queryClient.setQueryData(['workflow-requests', 'mine'], paginated([{ id: 'r1', status: 'submitted' } as WorkflowRequest]))
  queryClient.setQueryData(['expense-claims', 'mine'], paginated([{ id: 'e1', status: 'in_review' } as ExpenseClaim]))
  queryClient.setQueryData(
    ['workflow-requests', 'to-approve', 'submitted', '', 1],
    paginated([{ id: 'a1' } as WorkflowRequest, { id: 'a2' } as WorkflowRequest, { id: 'a3' } as WorkflowRequest]),
  )
  queryClient.setQueryData(['backoffice-tasks', 'mine', {}], paginated([{ id: 't1', status: 'not_started' } as BackOfficeTask]))
  return queryClient
}

const meta = {
  title: 'Pages/HomeDashboardPage',
  component: HomeDashboardPage,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <QueryClientProvider client={buildQueryClient()}>
        <AuthContext.Provider value={mockAuthValue}>
          <MemoryRouter>
            <Story />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    ),
  ],
} satisfies Meta<typeof HomeDashboardPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
