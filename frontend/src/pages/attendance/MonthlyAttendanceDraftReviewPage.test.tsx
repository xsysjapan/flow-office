import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as monthlyAttendanceDraftsApi from '../../api/monthlyAttendanceDrafts'
import * as usersApi from '../../api/users'
import type { FieldProvenance, MonthlyAttendanceDraft, Paginated, User } from '../../api/types'
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

const paginatedUsers: Paginated<User> = {
  data: [
    {
      id: 7,
      name: '承認者花子',
      email: 'hanako@example.com',
      department: null,
      job_title: null,
      employment_status: 'active',
      last_login_at: null,
    },
  ],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(monthlyAttendanceDraftsApi, 'fetchMonthlyAttendanceDraft').mockResolvedValue(draft)
  vi.spyOn(monthlyAttendanceDraftsApi, 'fetchMonthlyAttendanceDraftFields').mockResolvedValue(fields)
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/attendance/monthly-drafts/2']}>
        <Routes>
          <Route path="/attendance/monthly-drafts/:id" element={<MonthlyAttendanceDraftReviewPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('MonthlyAttendanceDraftReviewPage', () => {
  it('shows the draft status and each field with its source', async () => {
    renderPage()

    expect(await screen.findByText('要確認')).toBeInTheDocument()
    expect(screen.getByText('出勤時刻')).toBeInTheDocument()
    expect(screen.getByText('退勤時刻')).toBeInTheDocument()
    expect(screen.getByText('AI推定(要確認)')).toBeInTheDocument()
    expect(screen.getByText('本人確認済み')).toBeInTheDocument()
  })

  it('only shows a confirm button for unconfirmed AI-inferred fields', async () => {
    renderPage()

    await screen.findByText('要確認')

    expect(screen.getAllByRole('button', { name: '内容を確認した' })).toHaveLength(1)
  })

  it('confirms an AI-inferred field', async () => {
    const user = userEvent.setup()
    const confirmSpy = vi
      .spyOn(monthlyAttendanceDraftsApi, 'confirmMonthlyAttendanceDraftField')
      .mockResolvedValue({ field_name: '2026-07-01:start_time', confirmed_at: '2026-07-15T09:10:00+09:00' })
    renderPage()

    await screen.findByText('要確認')
    await user.click(screen.getByRole('button', { name: '内容を確認した' }))

    expect(confirmSpy).toHaveBeenCalledWith(2, 1)
  })

  it('disables the submit button until the draft is ready and an approver is chosen', async () => {
    renderPage()

    await screen.findByText('要確認')

    expect(screen.getByRole('button', { name: '申請する' })).toBeDisabled()
  })

  it('submits the draft once ready and an approver is selected', async () => {
    const user = userEvent.setup()
    const readyDraft: MonthlyAttendanceDraft = { ...draft, status: 'ready_to_submit' }
    vi.spyOn(monthlyAttendanceDraftsApi, 'fetchMonthlyAttendanceDraft').mockResolvedValue(readyDraft)
    vi.spyOn(monthlyAttendanceDraftsApi, 'fetchMonthlyAttendanceDraftFields').mockResolvedValue(fields)
    vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue(paginatedUsers)
    const submitSpy = vi.spyOn(monthlyAttendanceDraftsApi, 'submitMonthlyAttendanceDraft').mockResolvedValue(readyDraft)

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/attendance/monthly-drafts/2']}>
          <Routes>
            <Route path="/attendance/monthly-drafts/:id" element={<MonthlyAttendanceDraftReviewPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText('申請可能')
    await user.click(screen.getByRole('combobox'))
    await user.type(screen.getByPlaceholderText('氏名またはメールアドレスで検索'), '花子')
    await user.click(await screen.findByRole('option', { name: '承認者花子(hanako@example.com)' }))
    await user.click(screen.getByRole('button', { name: '申請する' }))

    expect(submitSpy).toHaveBeenCalledWith(2, 7)
  })
})
