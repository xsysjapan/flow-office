import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { FieldProvenance, MonthlyAttendanceDraft } from '../../api/types'
import { MonthlyAttendanceDraftReviewPage } from './MonthlyAttendanceDraftReviewPage'

const draft: MonthlyAttendanceDraft = {
  id: 2,
  user_id: 42,
  target_month: '2026-07',
  status: 'needs_review',
  version: 3,
  source_type: 'work_report',
  source_reference: null,
  submitted_at: null,
  created_at: '2026-07-15T09:00:00+09:00',
}

const fields: FieldProvenance[] = [
  {
    id: 1,
    field_name: '2026-07-01:start_time',
    source_type: 'ai_inferred',
    confidence: 'medium',
    previous_value: null,
    confirmed_by_user_id: null,
    confirmed_at: null,
    created_at: '2026-07-15T09:00:00+09:00',
  },
  {
    id: 2,
    field_name: '2026-07-01:end_time',
    source_type: 'user_confirmed',
    confidence: null,
    previous_value: null,
    confirmed_by_user_id: 42,
    confirmed_at: '2026-07-15T09:05:00+09:00',
    created_at: '2026-07-15T09:00:00+09:00',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['monthly-attendance-drafts', 2], draft)
  queryClient.setQueryData(['monthly-attendance-draft-fields', 2], fields)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/attendance/monthly-drafts/2']}>
          <Routes>
            <Route path="/attendance/monthly-drafts/:id" element={<MonthlyAttendanceDraftReviewPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/MonthlyAttendanceDraftReviewPage',
  component: MonthlyAttendanceDraftReviewPage,
} satisfies Meta<typeof MonthlyAttendanceDraftReviewPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
