import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { GroupMember, GroupOption, Paginated, User } from '../../api/types'
import { GrantTargetPicker } from './GrantTargetPicker'

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
]

const paginatedUsers: Paginated<User> = {
  data: users,
  meta: { current_page: 1, last_page: 1, total: users.length },
  links: { next: null, prev: null },
}

const groups: GroupOption[] = [{ id: 'group-1', name: '営業部' }]

const members: GroupMember[] = [
  { user_id: 'member-1', name: '営業一郎', email: 'ichiro@example.com', membership_kind: 'primary', is_primary: true },
  { user_id: 'member-2', name: '営業二郎', email: 'jiro@example.com', membership_kind: 'primary', is_primary: true },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', 'search', '', 100], paginatedUsers)
  queryClient.setQueryData(['groups', 'list'], groups)
  queryClient.setQueryData(['groups', 'members', 'group-1'], members)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <GrantTargetPicker idPrefix="grant" onResolvedChange={fn()} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/GrantTargetPicker',
  component: GrantTargetPicker,
} satisfies Meta<typeof GrantTargetPicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { idPrefix: 'grant', onResolvedChange: fn() },
  render: withSeeded(),
}
