import type { Meta, StoryObj } from '@storybook/react-vite'
import { FormField } from './FormField'

const meta = {
  title: 'Components/FormField',
  component: FormField,
  tags: ['autodocs'],
} satisfies Meta<typeof FormField>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    label: 'タイトル',
    htmlFor: 'title',
    children: <input id="title" name="title" />,
  },
}

export const Required: Story = {
  args: {
    label: '承認者',
    htmlFor: 'approver',
    required: true,
    children: <input id="approver" name="approver" />,
  },
}

export const WithError: Story = {
  args: {
    label: 'タイトル',
    htmlFor: 'title-error',
    required: true,
    error: 'タイトルは必須です。',
    children: <input id="title-error" name="title" />,
  },
}
