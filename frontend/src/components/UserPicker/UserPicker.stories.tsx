import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, fn, screen, userEvent, within } from 'storybook/test'
import type { Paginated, User } from '../../api/types'
import { UserPicker } from './UserPicker'

const users: User[] = [
  {
    id: 1,
    name: '申請者太郎',
    email: 'taro@example.com',
    department: null,
    job_title: null,
    employment_status: 'active',
    last_login_at: null,
  },
  {
    id: 2,
    name: '承認者花子',
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

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', ''], paginatedUsers)
  queryClient.setQueryData(['users', '花'], paginatedUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <UserPicker id="approver" value={undefined} onChange={fn()} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/UserPicker',
  component: UserPicker,
} satisfies Meta<typeof UserPicker>

export default meta
type Story = StoryObj<typeof meta>

export const Closed: Story = {
  args: { id: 'approver', value: undefined, onChange: fn() },
  render: withSeeded(),
}

export const Open: Story = {
  args: { id: 'approver', value: undefined, onChange: fn() },
  render: withSeeded(),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('combobox'))
    await userEvent.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '花')
    await expect(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' })).toBeInTheDocument()
  },
}
