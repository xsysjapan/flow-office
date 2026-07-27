import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { SingleExpenseItemForm } from './SingleExpenseItemForm'

const meta = {
  title: 'Components/SingleExpenseItemForm',
  component: SingleExpenseItemForm,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          'UC-X004b〜d: 会食・宿泊・消耗品/その他の単発経費を1件ずつ入力するフォーム。`fieldSet`で入力項目とdescriptionの整形フォーマットが切り替わる。保存後はフォームがリセットされ、続けて次の1件を入力できる。',
      },
    },
  },
  args: {
    categoryId: 1,
    onSubmit: fn(),
  },
} satisfies Meta<typeof SingleExpenseItemForm>

export default meta
type Story = StoryObj<typeof meta>

export const Meal: Story = {
  args: {
    fieldSet: 'meal',
  },
}

export const Lodging: Story = {
  args: {
    fieldSet: 'lodging',
  },
}

export const Generic: Story = {
  args: {
    fieldSet: 'generic',
  },
}

export const Submitting: Story = {
  args: {
    fieldSet: 'generic',
    isSubmitting: true,
  },
}
