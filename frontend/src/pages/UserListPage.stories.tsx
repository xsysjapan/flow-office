import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, User } from '../api/types'
import { UserListPage } from './UserListPage'

const sample: User[] = [
  {
    id: 1,
    name: '山田太郎',
    email: 'yamada@example.com',
    department: '総務部',
    job_title: '主任',
    employment_status: 'active',
    roles: ['employee', 'general_affairs_staff'],
    last_login_at: '2026-07-08T09:00:00+09:00',
  },
  {
    id: 2,
    name: '佐藤花子',
    email: 'sato@example.com',
    department: '人事部',
    job_title: null,
    employment_status: 'active',
    roles: [],
    last_login_at: null,
  },
]

function withSeededList(data: Paginated<User>) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', ''], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <UserListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/UserListPage',
  component: UserListPage,
} satisfies Meta<typeof UserListPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithUsers: Story = {
  render: withSeededList({ data: sample, meta: { current_page: 1, last_page: 1, total: 2 }, links: { next: null, prev: null } }),
}

export const Empty: Story = {
  render: withSeededList({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, links: { next: null, prev: null } }),
}
