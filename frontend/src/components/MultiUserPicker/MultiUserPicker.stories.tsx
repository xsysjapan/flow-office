import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { Paginated, User } from '../../api/types'
import { MultiUserPicker } from './MultiUserPicker'

const users: User[] = [
  {
    id: 'user-1',
    name: '対象太郎',
    email: 'taro@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
  {
    id: 'user-2',
    name: '対象花子',
    email: 'hanako@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
]

const paginatedUsers: Paginated<User> = {
  data: users,
  meta: { current_page: 1, last_page: 1, total: users.length },
  links: { next: null, prev: null },
}

function withSeeded(value: string[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', 'search', '', 100], paginatedUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MultiUserPicker id="targets" value={value} onChange={fn()} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/MultiUserPicker',
  component: MultiUserPicker,
} satisfies Meta<typeof MultiUserPicker>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {
  args: { id: 'targets', value: [], onChange: fn() },
  render: withSeeded([]),
}

export const WithSelection: Story = {
  args: { id: 'targets', value: ['user-1', 'user-2'], onChange: fn() },
  render: withSeeded(['user-1', 'user-2']),
}
