import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { pickDate } from '../../test-support/pickerInteractions'
import { SingleExpenseItemForm } from './SingleExpenseItemForm'

describe('SingleExpenseItemForm', () => {
  describe('fieldSet=meal', () => {
    it('requires payee, participants, participant count and content before submitting', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="meal" categoryId={2} onSubmit={onSubmit} />)

      const button = screen.getByRole('button', { name: '明細を保存して続けて入力する' })
      expect(button).toBeDisabled()

      await pickDate(userEvent.setup(), '利用日', '2026-07-10')
      await userEvent.type(screen.getByLabelText('金額'), '8000')
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('取引先'), '居酒屋 花')
      await userEvent.type(screen.getByLabelText('参加者氏名'), '山田太郎、鈴木一郎')
      await userEvent.type(screen.getByLabelText('参加人数'), '2')
      await userEvent.type(screen.getByLabelText('内容'), '取引先との懇親会')

      expect(button).toBeEnabled()
      await userEvent.click(button)

      expect(onSubmit).toHaveBeenCalledWith(
        {
          category_id: 2,
          usage_date: '2026-07-10',
          amount: 8000,
          description: '居酒屋 花 - 取引先との懇親会 (2名: 山田太郎、鈴木一郎)',
          payment_bearer: 'employee',
          attributes: undefined,
        },
        null,
      )
    })

    it('resets the form after submitting so the next item can be entered', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="meal" categoryId={2} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-10')
      await userEvent.type(screen.getByLabelText('金額'), '8000')
      await userEvent.type(screen.getByLabelText('取引先'), '居酒屋 花')
      await userEvent.type(screen.getByLabelText('参加者氏名'), '山田太郎')
      await userEvent.type(screen.getByLabelText('参加人数'), '2')
      await userEvent.type(screen.getByLabelText('内容'), '懇親会')
      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(screen.getByLabelText('利用日')).toHaveTextContent('日付を選択')
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

      await pickDate(userEvent.setup(), '利用日', '2026-07-11')
      await userEvent.type(screen.getByLabelText('金額'), '12000')
      await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
      await userEvent.type(screen.getByLabelText('内容'), '出張1泊')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(
        {
          category_id: 3,
          usage_date: '2026-07-11',
          amount: 12000,
          description: 'ホテルABC - 出張1泊',
          payment_bearer: 'employee',
          attributes: undefined,
        },
        null,
      )
    })

    it('formats description with only the lodging name when content is empty', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="lodging" categoryId={3} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-11')
      await userEvent.type(screen.getByLabelText('金額'), '12000')
      await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(
        {
          category_id: 3,
          usage_date: '2026-07-11',
          amount: 12000,
          description: 'ホテルABC',
          payment_bearer: 'employee',
          attributes: undefined,
        },
        null,
      )
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

      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      await userEvent.type(screen.getByLabelText('内容'), 'ノート・ペン購入')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(
        {
          category_id: 4,
          usage_date: '2026-07-12',
          amount: 3000,
          description: '文具店 - ノート・ペン購入',
          payment_bearer: 'employee',
          attributes: undefined,
        },
        null,
      )
    })

    it('formats description with only the payee when content is empty', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(
        {
          category_id: 4,
          usage_date: '2026-07-12',
          amount: 3000,
          description: '文具店',
          payment_bearer: 'employee',
          attributes: undefined,
        },
        null,
      )
    })

    it('requires usage date, amount and payee before submitting', async () => {
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={vi.fn()} />)

      const button = screen.getByRole('button', { name: '明細を保存して続けて入力する' })
      expect(button).toBeDisabled()

      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      expect(button).toBeEnabled()
    })
  })

  describe('fieldSet=other', () => {
    it('does not require a payee, only usage date/amount and one of payee/content', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="other" categoryId={5} onSubmit={onSubmit} />)

      const button = screen.getByRole('button', { name: '明細を保存して続けて入力する' })
      expect(button).toBeDisabled()

      await pickDate(userEvent.setup(), '利用日', '2026-07-13')
      await userEvent.type(screen.getByLabelText('金額'), '500')
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('内容'), '郵送料の実費精算')
      expect(button).toBeEnabled()
      await userEvent.click(button)

      expect(onSubmit).toHaveBeenCalledWith(
        {
          category_id: 5,
          usage_date: '2026-07-13',
          amount: 500,
          description: '郵送料の実費精算',
          payment_bearer: 'employee',
          attributes: undefined,
        },
        null,
      )
    })

    it('formats description with both payee and content when a payee is also given', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="other" categoryId={5} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-13')
      await userEvent.type(screen.getByLabelText('金額'), '500')
      await userEvent.type(screen.getByLabelText('取引先'), '日本郵便')
      await userEvent.type(screen.getByLabelText('内容'), '郵送料')
      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(
        expect.objectContaining({ description: '日本郵便 - 郵送料' }),
        null,
      )
    })
  })

  describe('payment_bearer and attributes', () => {
    it('defaults payment_bearer to employee and lets it be changed', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      await userEvent.selectOptions(screen.getByLabelText('支払方法'), '法人カード')
      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ payment_bearer: 'corporate_card' }), null)
    })

    it('renders field definitions dynamically and saves values into attributes', async () => {
      const onSubmit = vi.fn()
      render(
        <SingleExpenseItemForm
          fieldSet="generic"
          categoryId={4}
          fieldDefinitions={[{ key: 'origin', label: '出発地', type: 'text', required: true }]}
          onSubmit={onSubmit}
        />,
      )

      const button = screen.getByRole('button', { name: '明細を保存して続けて入力する' })
      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      expect(button).toBeDisabled()

      await userEvent.type(screen.getByLabelText('出発地'), '名古屋')
      expect(button).toBeEnabled()
      await userEvent.click(button)

      expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ attributes: { origin: '名古屋' } }), null)
    })
  })

  describe('領収書の添付', () => {
    it('passes the selected receipt file to onSubmit alongside the item input, then clears it after submitting', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')

      const file = new File(['dummy'], 'receipt.png', { type: 'image/png' })
      const fileInput = screen.getByLabelText('領収書(任意)') as HTMLInputElement
      await userEvent.upload(fileInput, file)
      expect(fileInput.files?.[0]).toBe(file)

      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ category_id: 4 }), file)

      // 保存後はフォームがリセットされ、続けて入力する次の明細に前の領収書が残らないようにする。
      expect((screen.getByLabelText('領収書(任意)') as HTMLInputElement).files?.length).toBe(0)
    })

    it('submits with null when no receipt file is selected', async () => {
      const onSubmit = vi.fn()
      render(<SingleExpenseItemForm fieldSet="generic" categoryId={4} onSubmit={onSubmit} />)

      await pickDate(userEvent.setup(), '利用日', '2026-07-12')
      await userEvent.type(screen.getByLabelText('金額'), '3000')
      await userEvent.type(screen.getByLabelText('取引先'), '文具店')
      await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

      expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ category_id: 4 }), null)
    })
  })
})
