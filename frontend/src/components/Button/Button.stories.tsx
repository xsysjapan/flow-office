import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { Button } from './Button'

const meta = {
  title: 'Components/Button',
  component: Button,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          'ページ実装で使う公開コンポーネント。内部で`ui/button`をラップし、業務用途を絞った`variant`と`isLoading`合成ロジックを持つ。',
      },
    },
  },
  args: {
    onClick: fn(),
  },
  argTypes: {
    variant: {
      control: 'select',
      options: ['primary', 'secondary', 'danger'],
    },
  },
} satisfies Meta<typeof Button>

export default meta
type Story = StoryObj<typeof meta>

export const Primary: Story = {
  args: {
    variant: 'primary',
    children: '出勤',
  },
}

export const Secondary: Story = {
  args: {
    variant: 'secondary',
    children: 'キャンセル',
  },
}

export const Danger: Story = {
  args: {
    variant: 'danger',
    children: '取り消す',
  },
}

export const Loading: Story = {
  args: {
    variant: 'primary',
    isLoading: true,
    children: '出勤',
  },
}

export const Disabled: Story = {
  args: {
    variant: 'primary',
    disabled: true,
    children: '出勤',
  },
}
