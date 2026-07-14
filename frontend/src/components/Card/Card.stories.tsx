import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { Card } from './Card'

const meta = {
  title: 'Components/Card',
  component: Card,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component: 'ページ実装で使う公開コンポーネント。内部で`ui/card`のCardHeader/CardTitle/CardContent等を組み立て、`title`/`actions`/`children`のみを受け取る。',
      },
    },
  },
} satisfies Meta<typeof Card>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    title: '今日の勤怠',
    children: <p>出勤: 09:00 / 退勤: 未</p>,
  },
}

export const WithActions: Story = {
  args: {
    title: '経費精算',
    actions: <Button variant="secondary">編集</Button>,
    children: <p>金額: 1,200円</p>,
  },
}
