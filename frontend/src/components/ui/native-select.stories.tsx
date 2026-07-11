import type { Meta, StoryObj } from '@storybook/react-vite'
import { NativeSelect } from './native-select'

const meta = {
  title: 'UI/NativeSelect',
  component: NativeSelect,
  tags: ['autodocs'],
  render: (args) => (
    <NativeSelect {...args}>
      <option value="">選択してください</option>
      <option value="expense_reimbursement">経費精算</option>
      <option value="business_card">名刺申請</option>
    </NativeSelect>
  ),
} satisfies Meta<typeof NativeSelect>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
export const Disabled: Story = { args: { disabled: true } }
