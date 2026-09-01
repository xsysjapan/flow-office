import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, fn, screen, userEvent, within } from 'storybook/test'
import { CategoryCombobox } from './CategoryCombobox'

function Controlled(props: { suggestions: string[]; initialValue?: string }) {
  const [value, setValue] = useState(props.initialValue ?? '')
  return (
    <CategoryCombobox
      id="category"
      value={value}
      onChange={(next) => {
        setValue(next)
        fn()(next)
      }}
      suggestions={props.suggestions}
      placeholder="例: ノートPC"
    />
  )
}

const meta = {
  title: 'Components/CategoryCombobox',
  component: CategoryCombobox,
} satisfies Meta<typeof CategoryCombobox>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {
  args: { id: 'category', value: '', onChange: fn(), suggestions: ['ノートPC', 'デスクトップPC', 'モニター'] },
}

export const WithSuggestions: Story = {
  args: { id: 'category', value: '', onChange: fn(), suggestions: ['ノートPC', 'デスクトップPC', 'モニター'] },
  render: () => <Controlled suggestions={['ノートPC', 'デスクトップPC', 'モニター']} />,
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('combobox'))
    await expect(await screen.findByRole('option', { name: 'ノートPC' })).toBeInTheDocument()
  },
}

export const FreeTextAllowed: Story = {
  args: { id: 'category', value: '', onChange: fn(), suggestions: ['ノートPC'] },
  render: () => <Controlled suggestions={['ノートPC']} />,
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.type(canvas.getByRole('combobox'), '未登録カテゴリ')
    expect(canvas.getByRole('combobox')).toHaveValue('未登録カテゴリ')
  },
}
