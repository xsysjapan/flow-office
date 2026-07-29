import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseEntryPresetsApi from '../../api/expenseEntryPresets'
import type { ExpenseEntryPreset, User } from '../../api/types'
import { ExpenseEntryPresetListPage } from './ExpenseEntryPresetListPage'

const applicant: User = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: applicant }),
}))

const personalPreset: ExpenseEntryPreset = {
  id: 1,
  visibility: 'personal',
  owner_user_id: 'applicant-1',
  name: '自宅⇔会社',
  description: null,
  preset_type: 'single_item',
  definition: [{ category_id: 1, description: '自宅 → 会社(電車)', amount: 420 }],
  is_active: true,
  usage_count: 3,
  last_used_at: null,
  created_by: 'applicant-1',
}

const systemPreset: ExpenseEntryPreset = {
  id: 2,
  visibility: 'system',
  owner_user_id: null,
  name: '深夜タクシー',
  description: null,
  preset_type: 'single_item',
  definition: [{ category_id: 2, description: null, amount: null }],
  is_active: true,
  usage_count: 0,
  last_used_at: null,
  created_by: null,
}

function renderPage(presets: ExpenseEntryPreset[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseEntryPresetsApi, 'fetchExpenseEntryPresets').mockResolvedValue(presets)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ExpenseEntryPresetListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseEntryPresetListPage', () => {
  it('shows an empty state when there are no presets', async () => {
    renderPage([])

    expect(await screen.findByText('プリセットはまだありません。')).toBeInTheDocument()
  })

  it('lists presets and lets the owner edit or delete their own personal preset', async () => {
    renderPage([personalPreset, systemPreset])

    expect(await screen.findByRole('link', { name: '自宅⇔会社' })).toHaveAttribute('href', '/expenses/presets/1')
    expect(screen.getByText('個人用')).toBeInTheDocument()
    expect(screen.getByText('システム標準')).toBeInTheDocument()
    // 個人用は本人のみ削除できる。システム標準は経理・管理者のみのため、一般社員には削除ボタンを出さない。
    expect(screen.getAllByRole('button', { name: '削除' })).toHaveLength(1)
    // システム標準は編集リンクの代わりに、ただのテキストとして表示する。
    expect(screen.getByText('深夜タクシー')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: '深夜タクシー' })).not.toBeInTheDocument()
  })

  it('deletes a preset after confirming', async () => {
    renderPage([personalPreset])
    const deletePreset = vi.spyOn(expenseEntryPresetsApi, 'deleteExpenseEntryPreset').mockResolvedValue(undefined)

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    await waitFor(() => expect(deletePreset).toHaveBeenCalledWith(1))
  })

  it('shows the new-preset link', async () => {
    renderPage([])

    await screen.findByText('プリセットはまだありません。')
    expect(screen.getByRole('link', { name: '新規作成' })).toHaveAttribute('href', '/expenses/presets/new')
  })
})
