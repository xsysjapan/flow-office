import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { notify } from '../../lib/notify'
import { Toaster } from './sonner'

const meta = {
  title: 'UI/Sonner',
  component: Toaster,
} satisfies Meta<typeof Toaster>

export default meta
type Story = StoryObj<typeof meta>

export const Success: Story = {
  render: () => (
    <Button onClick={() => notify.success('3件のタスクの担当者を山田 太郎さんに変更しました')}>成功を通知する</Button>
  ),
}

export const WithDescription: Story = {
  render: () => (
    <Button onClick={() => notify.success('ステータスを処理中に変更しました', { description: 'コメントを記録しました。' })}>
      説明つきで通知する
    </Button>
  ),
}
