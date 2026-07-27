import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { SingleExpenseItemForm } from './SingleExpenseItemForm'

describe('SingleExpenseItemForm', () => {
  describe('fieldSet=meal', () => {
    it('requires payee, participants, participant count and content before submitting', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="meal" categoryId={2} onSubmit={onSubmit} />)

      const button = screen.getByRole('button', { name: '明細を保存して続けて入力する' })
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-10')
      await userEvent.type(screen.getByLabelText('金額'), '8000')
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('取引先'), '居酒屋 花')
      await userEvent.type(screen.getByLabelText('参加者氏名'), '山田太郎、鈴木一郎')
      await userEvent.type(screen.getByLabelText('参加人数'), '2')
      await userEvent.type(screen.getByLabelText('内容'), '取引先との懇親会')

      expect(button).toBeEnabled()
      await userEvent.click(button)

      expect(onSubmit).toHaveBeenCalledWith({
        category_id: 2,
        usage_date: '2026-07-10',
        amount: 8000,
        description: '居酒屋 花 - 取引先との懇親会 (2名: 山田太郎、鈴木一郎)',
      })
    })

    it('resets the form after submitting so the next item can be entered', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="meal" categoryId={2} onSubmit={onSubmit} />)

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-10')
      await userEvent.type(screen.getByLabelText('金額'), '8000')
      await userEvent.type(screen.getByLabelText('取引先'), '居酒屋 花')
      await userEvent.type(screen.getByLabelText('参加者氏名'), '山田太郎')
      await userEvent.type(screen.getByLabelText('参加人数'), '2')
      await userEvent.type(screen.getByLabelText('内容'), '懇親会')
      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(screen.getByLabelText('利用日')).toHaveValue('')
      expect(screen.getByLabelText('金額')).toHaveValue(null)
      expect(screen.getByLabelText('取引先')).toHaveValue('')
      expect(screen.getByLabelText('参加者氏名')).toHaveValue('')
      expect(screen.getByLabelText('参加人数')).toHaveValue(null)
      expect(screen.getByLabelText('内容')).toHaveValue('')
    })
  })

  describe('fieldSet=lodging', () => {
    it('formats description with content when provided', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="lodging" categoryId={3} onSubmit={onSubmit} />)

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-11')
      await userEvent.type(screen.getByLabelText('金額'), '12000')
      await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
      await userEvent.type(screen.getByLabelText('内容'), '出張1泊')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith({
        category_id: 3,
        usage_date: '2026-07-11',
        amount: 12000,
        description: 'ホテルABC - 出張1泊',
      })
    })

    it('formats description with only the lodging name when content is empty', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="lodging" categoryId={3} onSubmit={onSubmit} />)

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-11')
      await userEvent.type(screen.getByLabelText('金額'), '12000')
      await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith({
        category_id: 3,
        usage_date: '2026-07-11',
        amount: 12000,
        description: 'ホテルABC',
      })
    })

    it('does not require content to submit', () => {
      render(<SingleExpenseItemForm fieldSet="lodging" categoryId={3} onSubmit={vi.fn()} />)
      expect(screen.queryByText('内容', { selector: 'label' })).toBeInTheDocument()
    })
  })

  describe('fieldSet=generic', () => {
    it('formats description with content when provided', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={onSubmit} />)

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      await userEvent.type(screen.getByLabelText('内容'), 'ノート・ペン購入')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith({
        category_id: 4,
        usage_date: '2026-07-12',
        amount: 3000,
        description: '文具店 - ノート・ペン購入',
      })
    })

    it('formats description with only the payee when content is empty', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={onSubmit} />)

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith({
        category_id: 4,
        usage_date: '2026-07-12',
        amount: 3000,
        description: '文具店',
      })
    })

    it('requires usage date, amount and payee before submitting', async () => {
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={vi.fn()} />)

      const button = screen.getByRole('button', { name: '明細を保存して続けて入力する' })
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('利用日'), '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      expect(button).toBeEnabled()
    })
  })
})
