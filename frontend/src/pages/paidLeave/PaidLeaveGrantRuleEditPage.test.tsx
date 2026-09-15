import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../../api/paidLeave'
import type { PaidLeaveGrantPolicies, PaidLeaveGrantRule } from '../../api/types'
import { PaidLeaveGrantRuleEditPage } from './PaidLeaveGrantRuleEditPage'

const rule: PaidLeaveGrantRule = {
  id: 1,
  name: '正社員標準ルール',
  work_style_id: null,
  min_attendance_rate: 80,
  first_grant_after_months: 6,
  grant_cycle_months: 12,
  is_active: true,
  steps: [{ continuous_service_months: 6, grant_days: 10 }],
}

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [{ continuous_service_months: 6, grant_days: 10 }],
  proportional_version: 'v1',
  proportional: [],
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantRules').mockResolvedValue([rule])
  vi.spyOn(paidLeaveApi, 'fetchPaidLeaveGrantPolicies').mockResolvedValue(policies)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/paid-leave/rules/1/edit']}>
        <Routes>
          <Route path="/admin/paid-leave/rules/:ruleId/edit" element={<PaidLeaveGrantRuleEditPage />} />
          <Route path="/admin/paid-leave" element={<div>一覧に戻りました</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PaidLeaveGrantRuleEditPage', () => {
  it('prefills the form with the existing rule values', async () => {
    renderPage()

    expect(await screen.findByLabelText('ルール名')).toHaveValue('正社員標準ルール')
    expect(screen.getByLabelText('最低出勤率')).toHaveValue(80)
    expect(screen.getByText('継続勤務6か月→10日')).toBeInTheDocument()
  })

  it('saves the edited rule and returns to the list', async () => {
    vi.spyOn(paidLeaveApi, 'updatePaidLeaveGrantRule').mockResolvedValue({ ...rule, name: '更新後ルール' })
    renderPage()

    const nameInput = await screen.findByLabelText('ルール名')
    await userEvent.clear(nameInput)
    await userEvent.type(nameInput, '更新後ルール')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(paidLeaveApi.updatePaidLeaveGrantRule).toHaveBeenCalledWith(1, {
        name: '更新後ルール',
        min_attendance_rate: 80,
        first_grant_after_months: 6,
        grant_cycle_months: 12,
        is_active: true,
        steps: [{ continuous_service_months: 6, grant_days: 10 }],
      }),
    )
    expect(await screen.findByText('一覧に戻りました')).toBeInTheDocument()
  })

  it('shows the statutory minimum reference for each step', async () => {
    renderPage()
    expect(await screen.findByText('(この継続勤務月数の法定最低日数: 10日)')).toBeInTheDocument()
  })
})
