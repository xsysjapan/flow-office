import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, User } from '../../api/types'
import { CompensatoryLeaveAdminPage } from './CompensatoryLeaveAdminPage'

const paginatedUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', 'search', '', 100], paginatedUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <CompensatoryLeaveAdminPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/CompensatoryLeave/CompensatoryLeaveAdminPage',
  component: CompensatoryLeaveAdminPage,
} satisfies Meta<typeof CompensatoryLeaveAdminPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
