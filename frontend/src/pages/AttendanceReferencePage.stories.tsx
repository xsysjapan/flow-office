import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, User } from '../api/types'
import { AttendanceReferencePage } from './AttendanceReferencePage'

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', ''], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AttendanceReferencePage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/AttendanceReferencePage',
  component: AttendanceReferencePage,
} satisfies Meta<typeof AttendanceReferencePage>

export default meta
type Story = StoryObj<typeof meta>

export const NoEmployeeSelected: Story = {
  render: withSeeded(),
}
