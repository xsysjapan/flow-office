import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, User } from '../../api/types'
import { AttendanceExportPage } from './AttendanceExportPage'

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
        <AttendanceExportPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/AttendanceExportPage',
  component: AttendanceExportPage,
} satisfies Meta<typeof AttendanceExportPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
