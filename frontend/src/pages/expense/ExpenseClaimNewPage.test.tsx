import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseClaimsApi from '../../api/expenseClaims'
import * as expenseEntryPresetsApi from '../../api/expenseEntryPresets'
import * as usersApi from '../../api/users'
import type { ExpenseCategory, ExpenseClaim, ExpenseEntryPreset, User } from '../../api/types'
import { ExpenseClaimNewPage } from './ExpenseClaimNewPage'

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

const transportCategory: ExpenseCategory = {
  id: 1,
  code: 'transport',
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
  receipt_required_threshold: 0,
  approval_skip_threshold: null,
  is_active: true,
}

function draftClaim(overrides: Partial<ExpenseClaim> = {}): ExpenseClaim {
  return {
    id: 'claim-1',
    employee_id: 'applicant-1',
    period_from: null,
    period_to: null,
    status: 'draft',
    approver_user_id: null,
    total_amount: 0,
    submitted_at: null,
    approved_at: null,
    items: [],
    ...overrides,
  }
}

function renderPage(
  categories: ExpenseCategory[] = [transportCategory, lodgingCategory],
  initialPath = '/expenses/new',
  presets: ExpenseEntryPreset[] = [],
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)
  vi.spyOn(expenseEntryPresetsApi, 'fetchExpenseEntryPresets').mockResolvedValue(presets)
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
    links: { next: null, prev: null },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/expenses" element={<p>経費精算一覧</p>} />
          <Route path="/expenses/new" element={<ExpenseClaimNewPage />} />
          <Route path="/expenses/:id/edit" element={<ExpenseClaimNewPage />} />
          <Route path="/expenses/:id" element={<p>経費精算詳細ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** 「個別に経費登録」を選び、経費区分選択ステップへ進む。多くのテストは登録方法の
 *  選択自体ではなく、その先の区分選択・入力フォームの挙動を検証するために使う。 */
async function selectIndividualEntryMode() {
  await userEvent.click(await screen.findByRole('button', { name: '個別に登録する' }))
}

describe('ExpenseClaimNewPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the entry mode selection step first when starting a brand new claim', async () => {
    renderPage()

    expect(await screen.findByRole('button', { name: '個別に登録する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'まとめて登録する' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '交通費' })).not.toBeInTheDocument()
  })

  it('shows the category selection step after choosing 個別に経費登録, without asking for a target period', async () => {
    renderPage()
    await selectIndividualEntryMode()

    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '宿泊費' })).toBeInTheDocument()
    expect(screen.queryByLabelText('対象期間(開始)')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('対象期間(終了)')).not.toBeInTheDocument()
  })

  it('lets the user go back from category selection to the entry mode choice', async () => {
    renderPage()
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '登録方法の選択に戻る' }))

    expect(await screen.findByRole('button', { name: '個別に登録する' })).toBeInTheDocument()
  })

  it('shows a title step with clickable suggestions when choosing まとめて経費登録, and creates the claim with the chosen title', async () => {
    vi.setSystemTime(new Date('2026-07-15T00:00:00+09:00'))
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    const updateTitle = vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(
      draftClaim({ title: '2026年7月分交通費' }),
    )

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'まとめて登録する' }))

    expect(await screen.findByLabelText('タイトル')).toHaveValue('')
    const suggestion = screen.getByRole('button', { name: '2026年7月分交通費' })
    await userEvent.click(suggestion)

    await waitFor(() => expect(createClaim).toHaveBeenCalledWith())
    await waitFor(() => expect(updateTitle).toHaveBeenCalledWith('claim-1', '2026年7月分交通費'))
    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()

    vi.useRealTimers()
  })

  it('lets the user type a custom title in the まとめて経費登録 flow', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    const updateTitle = vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(draftClaim())

    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'まとめて登録する' }))

    const nextButton = screen.getByRole('button', { name: '次へ' })
    expect(nextButton).toBeDisabled()

    await userEvent.type(await screen.findByLabelText('タイトル'), '大阪出張分')
    expect(nextButton).toBeEnabled()
    await userEvent.click(nextButton)

    await waitFor(() => expect(createClaim).toHaveBeenCalledWith())
    await waitFor(() => expect(updateTitle).toHaveBeenCalledWith('claim-1', '大阪出張分'))
  })

  it('lets the user go back from the title step to the entry mode choice', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'まとめて登録する' }))
    await userEvent.click(await screen.findByRole('button', { name: '登録方法の選択に戻る' }))

    expect(await screen.findByRole('button', { name: '個別に登録する' })).toBeInTheDocument()
  })

  it('suggests a title from the first saved item when using 個別に経費登録', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'addExpenseItem').mockResolvedValue({
      id: 'item-1',
      category_id: 2,
      usage_date: '2026-07-10',
      description: 'ホテルABC',
      amount: 12000,
      project_id: null,
      evidence_type: 'receipt_required',
      fact_reference_type: null,
      fact_reference_id: null,
      commuting_deduction_amount: null,
    })
    const updateTitle = vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(
      draftClaim({ title: '宿泊費(2026-07-10)' }),
    )

    renderPage()
    await selectIndividualEntryMode()
    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))
    await userEvent.type(await screen.findByLabelText('利用日'), '2026-07-10')
    await userEvent.type(screen.getByLabelText('金額'), '12000')
    await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
    await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

    await waitFor(() =>
      expect(updateTitle).toHaveBeenCalledWith('claim-1', '宿泊費(2026-07-10)'),
    )
  })

  it('shows the item entry tools for a batch category without creating a draft claim yet', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())

    renderPage()
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '交通費' }))

    expect(await screen.findByRole('button', { name: '行を追加' })).toBeInTheDocument()
    expect(createClaim).not.toHaveBeenCalled()
  })

  it('lets a batch category be filled from a preset applicable to it, without showing presets for other categories', async () => {
    const presets: ExpenseEntryPreset[] = [
      {
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
      },
      {
        id: 2,
        visibility: 'personal',
        owner_user_id: 'applicant-1',
        name: '技術書購入',
        description: null,
        preset_type: 'single_item',
        definition: [{ category_id: 2, description: '技術書購入', amount: 3000 }],
        is_active: true,
        usage_count: 1,
        last_used_at: null,
        created_by: 'applicant-1',
      },
    ]
    const applyPreset = vi.spyOn(expenseEntryPresetsApi, 'applyExpenseEntryPreset').mockResolvedValue({
      id: 1,
      visibility: 'personal',
      owner_user_id: 'applicant-1',
      name: '自宅⇔会社',
      description: null,
      preset_type: 'single_item',
      definition: [{ category_id: 1, description: '自宅 → 会社(電車)', amount: 420 }],
      is_active: true,
      usage_count: 4,
      last_used_at: null,
      created_by: 'applicant-1',
    })

    renderPage([transportCategory, lodgingCategory], '/expenses/new', presets)
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '交通費' }))

    expect(await screen.findByRole('button', { name: '自宅⇔会社(1件)' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '技術書購入(1件)' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '自宅⇔会社(1件)' }))

    expect(applyPreset).toHaveBeenCalledWith(1)
    expect(await screen.findByDisplayValue('自宅 → 会社(電車)')).toBeInTheDocument()
    expect(screen.getByRole('spinbutton', { name: /1行目の金額/ })).toHaveValue(420)
  })

  it('creates a draft claim with no body only when the first rows are actually saved (batch category)', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'addExpenseItemsBulk').mockResolvedValue([])
    vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(draftClaim())

    renderPage()
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '交通費' }))
    expect(expenseClaimsApi.createExpenseClaim).not.toHaveBeenCalled()

    await userEvent.click(await screen.findByRole('button', { name: '行を追加' }))
    await userEvent.type(screen.getByLabelText('1行目の日付'), '2026-07-04')
    await userEvent.type(screen.getByLabelText('1行目の金額'), '420')

    await userEvent.click(screen.getByRole('button', { name: /明細を保存する/ }))

    await waitFor(() => expect(expenseClaimsApi.createExpenseClaim).toHaveBeenCalledWith())
    await waitFor(() =>
      expect(expenseClaimsApi.addExpenseItemsBulk).toHaveBeenCalledWith('claim-1', [
        expect.objectContaining({ usage_date: '2026-07-04', amount: 420, category_id: 1 }),
      ]),
    )
  })

  it('shows the single-item form for a single-entry category without creating a draft claim yet', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())

    renderPage()
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))

    expect(await screen.findByLabelText('利用日')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '行を追加' })).not.toBeInTheDocument()
    expect(createClaim).not.toHaveBeenCalled()
  })

  it('creates a draft claim only when the first single item is saved, then keeps reusing it', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'addExpenseItem').mockResolvedValue({
      id: 'item-1',
      category_id: 2,
      usage_date: '2026-07-10',
      description: 'ホテルABC',
      amount: 12000,
      project_id: null,
      evidence_type: 'receipt_required',
      fact_reference_type: null,
      fact_reference_id: null,
      commuting_deduction_amount: null,
    })

    renderPage()
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))
    expect(expenseClaimsApi.createExpenseClaim).not.toHaveBeenCalled()

    await userEvent.type(await screen.findByLabelText('利用日'), '2026-07-10')
    await userEvent.type(screen.getByLabelText('金額'), '12000')
    await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
    await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

    await waitFor(() => expect(expenseClaimsApi.createExpenseClaim).toHaveBeenCalledWith())
    await waitFor(() =>
      expect(expenseClaimsApi.addExpenseItem).toHaveBeenCalledWith('claim-1', expect.objectContaining({
        category_id: 2,
        usage_date: '2026-07-10',
        amount: 12000,
        description: 'ホテルABC',
      })),
    )

    expect(await screen.findByLabelText('宿泊先名')).toHaveValue('')
  })

  it('skips the category selection step when a ?category= shortcut param matches an active category', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())

    renderPage([transportCategory, lodgingCategory], '/expenses/new?category=lodging')

    expect(await screen.findByLabelText('利用日')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '宿泊費' })).not.toBeInTheDocument()
    expect(createClaim).not.toHaveBeenCalled()
  })

  it('ignores a ?category= shortcut param that does not match any active category', async () => {
    renderPage([transportCategory, lodgingCategory], '/expenses/new?category=unknown')

    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '宿泊費' })).toBeInTheDocument()
  })

  it('lets the user go back to category selection via 区分を変更する before anything is saved', async () => {
    renderPage([transportCategory, lodgingCategory], '/expenses/new?category=lodging')

    await screen.findByLabelText('利用日')
    await userEvent.click(screen.getByRole('button', { name: '区分を変更する' }))

    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '宿泊費' })).toBeInTheDocument()
  })

  it('lets the applicant set an optional title for the claim', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(
      draftClaim({
        items: [
          {
            id: 'item-1',
            category_id: 2,
            usage_date: '2026-07-10',
            description: 'ホテルABC',
            amount: 12000,
            project_id: null,
            evidence_type: 'receipt_required',
            fact_reference_type: null,
            fact_reference_id: null,
            commuting_deduction_amount: null,
          },
        ],
      }),
    )
    const updateTitle = vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(draftClaim())

    renderPage([transportCategory, lodgingCategory], '/expenses/claim-1/edit')

    const titleField = await screen.findByLabelText('申請タイトル')
    const saveButton = screen.getByRole('button', { name: '保存' })
    expect(saveButton).toBeDisabled()

    await userEvent.type(titleField, '大阪出張分')
    expect(saveButton).toBeEnabled()
    await userEvent.click(saveButton)

    await waitFor(() => expect(updateTitle).toHaveBeenCalledWith('claim-1', '大阪出張分'))
  })

  it('does not create a second claim when returning to pick another category (UC-X013)', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'updateExpenseClaimTitle').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(
      draftClaim({
        items: [
          {
            id: 'item-1',
            category_id: 2,
            usage_date: '2026-07-10',
            description: 'ホテルABC',
            amount: 12000,
            project_id: null,
            evidence_type: 'receipt_required',
            fact_reference_type: null,
            fact_reference_id: null,
            commuting_deduction_amount: null,
          },
        ],
      }),
    )
    vi.spyOn(expenseClaimsApi, 'addExpenseItem').mockResolvedValue({
      id: 'item-1',
      category_id: 2,
      usage_date: '2026-07-10',
      description: 'ホテルABC',
      amount: 12000,
      project_id: null,
      evidence_type: 'receipt_required',
      fact_reference_type: null,
      fact_reference_id: null,
      commuting_deduction_amount: null,
    })

    renderPage()
    await selectIndividualEntryMode()

    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))
    await userEvent.type(await screen.findByLabelText('利用日'), '2026-07-10')
    await userEvent.type(screen.getByLabelText('金額'), '12000')
    await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
    await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))
    await waitFor(() => expect(createClaim).toHaveBeenCalled())
    const callCountAfterFirstSave = createClaim.mock.calls.length

    await userEvent.click(await screen.findByRole('button', { name: '別の区分の明細を追加する' }))
    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '交通費' }))
    await screen.findByRole('button', { name: '行を追加' })
    expect(createClaim.mock.calls.length).toBe(callCountAfterFirstSave)
  })

  it('lets the applicant delete an unwanted draft while resuming its edit', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(
      draftClaim({
        items: [
          {
            id: 'item-1',
            category_id: 2,
            usage_date: '2026-07-10',
            description: 'ホテルABC',
            amount: 12000,
            project_id: null,
            evidence_type: 'receipt_required',
            fact_reference_type: null,
            fact_reference_id: null,
            commuting_deduction_amount: null,
          },
        ],
      }),
    )
    const deleteClaim = vi.spyOn(expenseClaimsApi, 'deleteExpenseClaim').mockResolvedValue(undefined)

    renderPage([transportCategory, lodgingCategory], '/expenses/claim-1/edit')

    await userEvent.click(await screen.findByRole('button', { name: 'この下書きを削除する' }))
    expect(deleteClaim).not.toHaveBeenCalled()
    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    await waitFor(() => expect(deleteClaim).toHaveBeenCalledWith('claim-1'))
    expect(await screen.findByText('経費精算一覧')).toBeInTheDocument()
  })
})
