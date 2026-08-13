import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseEntryPresetsApi from '../../api/expenseEntryPresets'
import type { ExpenseCategory, ExpenseEntryPreset, User } from '../../api/types'
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

const transportCategory: ExpenseCategory = {
  id: 1,
  code: 'transportation',
  name: '交通費',
  description: null,
  evidence_type_default: 'fact_reference_available',
  entry_mode: 'batch',
  field_definitions: null,
  receipt_required_threshold: null,
  approval_skip_threshold: null,
  is_active: true,
}

const lodgingCategory: ExpenseCategory = {
  id: 2,
  code: 'lodging',
  name: '宿泊費',
  description: null,
  evidence_type_default: 'receipt_required',
  entry_mode: 'single',
  field_definitions: null,
  receipt_required_threshold: null,
  approval_skip_threshold: null,
  is_active: true,
}

function renderPage(presets: ExpenseEntryPreset[], initialPath = '/expenses/presets') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue([transportCategory, lodgingCategory])
  // 名称検索・経費区分での絞り込みはAPI側で行うため、モックでも同じ絞り込みを再現する。
  const fetchPresets = vi
    .spyOn(expenseEntryPresetsApi, 'fetchExpenseEntryPresets')
    .mockImplementation((filters = {}) => {
      const matched = presets
        .filter((preset) => (filters.q ? preset.name.includes(filters.q) : true))
        .filter((preset) =>
          filters.category_id
            ? preset.definition.some((item) => item.category_id === filters.category_id)
            : true,
        )
      return Promise.resolve({
        data: matched,
        meta: { current_page: 1, last_page: 1, total: matched.length },
        links: { next: null, prev: null },
      })
    })

  return {
    fetchPresets,
    ...render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialPath]}>
          <ExpenseEntryPresetListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    ),
  }
}

describe('ExpenseEntryPresetListPage', () => {
  it('shows an empty state when there are no presets', async () => {
    renderPage([])

    expect(await screen.findByText('条件に一致するプリセットはありません。')).toBeInTheDocument()
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

    await screen.findByText('条件に一致するプリセットはありません。')
    expect(screen.getByRole('link', { name: '新規作成' })).toHaveAttribute('href', '/expenses/presets/new')
  })

  it('shows which 経費区分 each preset belongs to', async () => {
    renderPage([personalPreset, systemPreset])

    await screen.findByRole('link', { name: '自宅⇔会社' })
    // 区分名は絞り込みのselectにも出るため、表の行(cell)に絞って確認する。
    expect(screen.getByRole('cell', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: '宿泊費' })).toBeInTheDocument()
  })

  it('filters by name via the search box', async () => {
    renderPage([personalPreset, systemPreset])

    await screen.findByRole('link', { name: '自宅⇔会社' })
    await userEvent.type(screen.getByLabelText('名称で検索'), '深夜')

    await waitFor(() => expect(screen.queryByRole('link', { name: '自宅⇔会社' })).not.toBeInTheDocument())
    expect(screen.getByText('深夜タクシー')).toBeInTheDocument()
  })

  it('preselects the 経費区分 filter when arriving with a ?category_id= param from the entry screen', async () => {
    renderPage([personalPreset, systemPreset], '/expenses/presets?category_id=2')

    // 区分の選択肢は経費区分の取得後に描画されるため、値が反映されるまで待つ。
    await waitFor(() => expect(screen.getByLabelText('経費区分で絞り込む')).toHaveValue('2'))
    // 交通費のプリセットは、宿泊費で絞り込まれているので表示されない。
    expect(screen.queryByRole('link', { name: '自宅⇔会社' })).not.toBeInTheDocument()
    expect(screen.getByText('深夜タクシー')).toBeInTheDocument()
  })

  it('lets the 経費区分 filter be changed on the screen', async () => {
    renderPage([personalPreset, systemPreset])

    await screen.findByRole('link', { name: '自宅⇔会社' })
    await userEvent.selectOptions(screen.getByLabelText('経費区分で絞り込む'), '宿泊費')

    await waitFor(() => expect(screen.queryByRole('link', { name: '自宅⇔会社' })).not.toBeInTheDocument())
    expect(screen.getByText('深夜タクシー')).toBeInTheDocument()
  })
})
