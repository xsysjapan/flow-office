import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as paidLeaveApi from '../../api/paidLeave'
import * as workCalendarsApi from '../../api/workCalendars'
import type { PaidLeaveGrantPolicies, PaidLeaveGrantRule } from '../../api/types'
import { PaidLeaveGrantRuleEditPage } from './PaidLeaveGrantRuleEditPage'
import { notify } from '../../lib/notify'

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
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([])

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
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('prefills the form with the existing rule values', async () => {
    renderPage()

    expect(await screen.findByLabelText('ルール名')).toHaveValue('正社員標準ルール')
    expect(screen.getByLabelText('最低出勤率')).toHaveValue(80)
    expect(screen.getByLabelText('通常 継続勤務6か月の付与日数')).toHaveValue(10)
    expect(screen.getByLabelText('入社日を基準に付与')).toBeChecked()
    expect(screen.queryByText(/既定の会社カレンダーの年度開始日/)).not.toBeInTheDocument()
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
        grant_cycle_type: 'anniversary',
        is_active: true,
        steps: [{ grant_category: 'normal', continuous_service_months: 6, grant_days: 10 }],
      }),
    )
    expect(await screen.findByText('一覧に戻りました')).toBeInTheDocument()
    expect(notify.success).toHaveBeenCalledWith('更新後ルールを保存しました')
  })

  it('shows the statutory minimum reference for each step', async () => {
    renderPage()
    expect(await screen.findByText('(この継続勤務月数の法定最低日数: 10日)')).toBeInTheDocument()
  })

  it('shows each 422 message once under its field and on the step row, under the heading above the buttons', async () => {
    vi.spyOn(paidLeaveApi, 'updatePaidLeaveGrantRule').mockRejectedValue(
      new ApiError(422, '最初のエラーの文です。', {
        name: ['ルール名のエラー'],
        min_attendance_rate: ['出勤率のエラー'],
        first_grant_after_months: ['初回のエラー'],
        grant_cycle_months: ['サイクルのエラー'],
        grant_cycle_type: ['付与方式のエラー'],
        is_active: ['有効のエラー'],
        'steps.0.grant_days': ['通常の行のエラー'],
        'steps.0.continuous_service_months': ['月数のエラー'],
        unknown_key: ['その他のエラー'],
      }),
    )
    renderPage()

    await screen.findByLabelText('ルール名')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    for (const message of [
      'ルール名のエラー',
      '出勤率のエラー',
      '初回のエラー',
      'サイクルのエラー',
      '付与方式のエラー',
      '有効のエラー',
      '通常の行のエラー',
      '月数のエラー',
      'その他のエラー',
    ]) {
      expect(await screen.findAllByText(message)).toHaveLength(1)
    }
    expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
  })
})
