import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ExpenseRouteBuilder } from './ExpenseRouteBuilder'
import type { ExpenseCategory } from '../../api/types'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
  {
    id: 2,
    code: 'misc',
    name: 'その他',
    description: null,
    evidence_type_default: 'receipt_required',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

describe('ExpenseRouteBuilder', () => {
  it('decomposes a 3-stop route into per-segment items, dropping excluded segments', async () => {
    const user = userEvent.setup()
    const onGenerate = vi.fn()
    render(
      <ExpenseRouteBuilder categories={categories} defaultCategoryId={1} onGenerate={onGenerate} />,
    )

    await user.type(screen.getByLabelText('対象日'), '2026-07-26')

    await user.type(screen.getByLabelText('地点1'), '自宅')
    await user.type(screen.getByLabelText('地点2'), '会社')
    await user.click(screen.getByRole('button', { name: '地点を追加' }))
    await user.type(screen.getByLabelText('地点3'), '訪問先')

    const transportInputs = screen.getAllByLabelText('交通手段')
    const amountInputs = screen.getAllByLabelText('金額')
    const excludedCheckboxes = screen.getAllByLabelText('精算対象外')

    expect(transportInputs).toHaveLength(2)

    await user.type(transportInputs[0], '徒歩')
    await user.click(excludedCheckboxes[0])

    await user.type(transportInputs[1], '電車')
    await user.type(amountInputs[1], '500')

    await user.click(screen.getByRole('button', { name: '経路から明細を生成' }))

    expect(onGenerate).toHaveBeenCalledTimes(1)
    expect(onGenerate).toHaveBeenCalledWith([
      {
        category_id: 1,
        usage_date: '2026-07-26',
        description: '会社 → 訪問先(電車)',
        amount: 500,
      },
    ])
  })

  it('disables generate until at least 2 stops and a usage date are filled', async () => {
    const user = userEvent.setup()
    const onGenerate = vi.fn()
    render(<ExpenseRouteBuilder categories={categories} onGenerate={onGenerate} />)

    const generateButton = screen.getByRole('button', { name: '経路から明細を生成' })
    expect(generateButton).toBeDisabled()

    await user.type(screen.getByLabelText('対象日'), '2026-07-26')
    expect(generateButton).toBeDisabled()

    await user.type(screen.getByLabelText('地点1'), '自宅')
    expect(generateButton).toBeDisabled()

    await user.click(generateButton)
    expect(onGenerate).not.toHaveBeenCalled()
  })

  it('keeps at least 2 stops (remove is disabled at the minimum)', () => {
    render(<ExpenseRouteBuilder categories={categories} onGenerate={vi.fn()} />)
    const removeButtons = screen.getAllByRole('button', { name: /を削除$/ })
    expect(removeButtons).toHaveLength(2)
    for (const button of removeButtons) {
      expect(button).toBeDisabled()
    }
  })
})
