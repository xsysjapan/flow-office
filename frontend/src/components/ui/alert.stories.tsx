import type { Meta, StoryObj } from '@storybook/react-vite'
import { CircleAlert } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from './alert'

const meta = {
  title: 'UI/Alert',
  component: Alert,
  tags: ['autodocs'],
  argTypes: {
    variant: { control: 'select', options: ['default', 'destructive'] },
  },
} satisfies Meta<typeof Alert>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <Alert {...args}>
      <CircleAlert />
      <AlertTitle>お知らせ</AlertTitle>
      <AlertDescription>締め日が近づいています。</AlertDescription>
    </Alert>
  ),
}

export const Destructive: Story = {
  render: (args) => (
    <Alert variant="destructive" {...args}>
      <CircleAlert />
      <AlertTitle>エラーが発生しました</AlertTitle>
      <AlertDescription>入力内容をご確認ください。</AlertDescription>
    </Alert>
  ),
}
