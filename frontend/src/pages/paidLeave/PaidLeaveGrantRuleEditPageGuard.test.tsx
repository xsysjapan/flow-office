import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../../api/paidLeave'
import * as workCalendarsApi from '../../api/workCalendars'
import type { PaidLeaveGrantRule } from '../../api/types'
import { renderWithGuard } from '../../test/renderWithGuard'
import { PaidLeaveGrantRuleEditPage } from './PaidLeaveGrantRuleEditPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const rule: PaidLeaveGrantRule = {
  id: 1,
  name: '正社員標準ルール',
  work_style_id: null,
  min_attendance_rate: 80,
  first_grant_after_months: 6,
  grant_cycle_months: 12,
  grant_cycle_type: 'anniversary',
  is_active: true,
  steps: [{ grant_category: 'normal', continuous_service_months: 6, grant_days: 10 }],
}

describe('PaidLeaveGrantRuleEditPage leave guard', () => {
  afterEach(() => vi.restoreAllMocks())

  it('save navigates to the list without the confirm dialog', async () => {
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantRules').mockResolvedValue([rule])
    vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantPolicies').mockResolvedValue({
      version: 'v1',
      normal: [{ continuous_service_months: 6, grant_days: 10 }],
      proportional_version: 'v1',
      proportional: [],
    })
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([])
    vi.spyOn(paidLeaveApi, 'updatePaidLeaveGrantRule').mockResolvedValue({ ...rule, name: '更新後ルール' })
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    renderWithGuard(
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/admin/paid-leave/rules/:ruleId/edit" element={<PaidLeaveGrantRuleEditPage />} />
          <Route path="/admin/paid-leave" element={<div>一覧に戻りました</div>} />
        </Routes>
      </QueryClientProvider>,
      { initialEntries: ['/admin/paid-leave/rules/1/edit'] },
    )

    const nameInput = await screen.findByLabelText('ルール名')
    await waitFor(() => expect(nameInput).toHaveValue('正社員標準ルール'))
    await userEvent.type(nameInput, 'X')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    expect(await screen.findByText('一覧に戻りました')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })
})
