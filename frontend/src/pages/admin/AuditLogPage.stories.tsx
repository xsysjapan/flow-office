import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, StoredEvent } from '../../api/types'
import { AuditLogPage } from './AuditLogPage'

const events: StoredEvent[] = [
  {
    id: 1,
    event_id: 'evt-1',
    aggregate_type: 'workflow_request',
    aggregate_id: '1',
    version: 1,
    event_type: 'workflow_request.drafted',
    payload: { title: 'タクシー代' },
    occurred_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 2,
    event_id: 'evt-2',
    aggregate_type: 'workflow_request',
    aggregate_id: '1',
    version: 2,
    event_type: 'workflow_request.submitted',
    payload: {},
    occurred_at: '2026-07-01T01:00:00+09:00',
  },
]

const paginated: Paginated<StoredEvent> = {
  data: events,
  meta: { current_page: 1, last_page: 1, total: 2 },
  links: { next: null, prev: null },
}

const defaultFilters = {
  aggregate_type: undefined,
  aggregate_id: undefined,
  event_type: undefined,
  user_id: undefined,
  from: undefined,
  to: undefined,
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['audit-log', defaultFilters], paginated)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <AuditLogPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Admin/AuditLogPage',
  component: AuditLogPage,
} satisfies Meta<typeof AuditLogPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
