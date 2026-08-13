import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { EmptyState } from './EmptyState'

const meta = {
  title: 'Components/EmptyState',
  component: EmptyState,
  tags: ['autodocs'],
} satisfies Meta<typeof EmptyState>

export default meta
type Story = StoryObj<typeof meta>

export const InitialEmpty: Story = {
  args: {
    title: 'グループがまだありません。',
    description: '最初のグループを作成してください。',
    action: <Button>グループを作成</Button>,
  },
}

export const FilteredEmpty: Story = {
  args: {
    title: '条件に一致するグループがありません。',
    description: '検索条件を変更するか、条件をクリアしてください。',
    action: (
      <Button variant="secondary" size="sm">
        検索条件をクリア
      </Button>
    ),
  },
}

export const TitleOnly: Story = {
  args: {
    title: 'データがありません。',
  },
}
