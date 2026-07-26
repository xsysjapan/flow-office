import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { ExpenseRouteTemplate } from '../../api/types'
import { ExpenseTemplateBulkGenerator } from './ExpenseTemplateBulkGenerator'

const activeTemplate: ExpenseRouteTemplate = {
  id: 1,
  scope: 'personal',
  employee_id: 'emp-1',
  name: '自宅⇔会社',
  origin: '自宅',
  destination: '会社',
  transport_type: '電車',
  amount: 500,
  category_id: 7,
  is_active: true,
}

const inactiveTemplate: ExpenseRouteTemplate = {
  id: 2,
  scope: 'personal',
  employee_id: 'emp-1',
  name: '廃止済みルート',
  origin: '自宅',
  destination: '旧オフィス',
  transport_type: '電車',
  amount: 400,
  category_id: 7,
  is_active: false,
}

describe('ExpenseTemplateBulkGenerator', () => {
  it('only lists active templates in the select', () => {
    render(<ExpenseTemplateBulkGenerator templates={[activeTemplate, inactiveTemplate]} onGenerate={vi.fn()} />)

    const select = screen.getByLabelText('テンプレート')
    expect(select).toHaveTextContent('自宅⇔会社')
    expect(select).not.toHaveTextContent('廃止済みルート')
  })

  it('disables the button when no template or no dates are selected', async () => {
    render(<ExpenseTemplateBulkGenerator templates={[activeTemplate]} onGenerate={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'まとめて追加' })).toBeDisabled()

    await userEvent.selectOptions(screen.getByLabelText('テンプレート'), String(activeTemplate.id))
    expect(screen.getByRole('button', { name: 'まとめて追加' })).toBeDisabled()
  })

  it('generates one item per selected date, copying fields from the template', async () => {
    const onGenerate = vi.fn()
    render(<ExpenseTemplateBulkGenerator templates={[activeTemplate]} onGenerate={onGenerate} />)

    await userEvent.selectOptions(screen.getByLabelText('テンプレート'), String(activeTemplate.id))

    const dayCells = await screen.findAllByText('15')
    await userEvent.click(dayCells[0])
    const dayCells20 = screen.getAllByText('20')
    await userEvent.click(dayCells20[0])

    expect(screen.getByText(/2日分・1,000円を追加します/)).toBeInTheDocument()

    const button = screen.getByRole('button', { name: 'まとめて追加' })
    expect(button).not.toBeDisabled()
    await userEvent.click(button)

    expect(onGenerate).toHaveBeenCalledTimes(1)
    const items = onGenerate.mock.calls[0][0]
    expect(items).toHaveLength(2)
    for (const item of items) {
      expect(item).toMatchObject({
        category_id: 7,
        origin: '自宅',
        destination: '会社',
        transport_type: '電車',
        amount: 500,
      })
      expect(item.usage_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    }
  })

  it('resets the calendar selection after generating, keeping the template selected', async () => {
    const onGenerate = vi.fn()
    render(<ExpenseTemplateBulkGenerator templates={[activeTemplate]} onGenerate={onGenerate} />)

    await userEvent.selectOptions(screen.getByLabelText('テンプレート'), String(activeTemplate.id))
    const dayCells = await screen.findAllByText('15')
    await userEvent.click(dayCells[0])

    await userEvent.click(screen.getByRole('button', { name: 'まとめて追加' }))

    expect(screen.queryByText(/日分・.*円を追加します/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'まとめて追加' })).toBeDisabled()
    expect(screen.getByLabelText('テンプレート')).toHaveValue(String(activeTemplate.id))
  })
})
