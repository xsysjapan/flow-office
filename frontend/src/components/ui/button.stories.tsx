import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { Button } from './button'

const meta = {
  title: 'UI/Button',
  component: Button,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          'shadcn/ui相当の内部実装プリミティブ。`outline`/`ghost`/`link`やアイコンサイズなど、他コンポーネントの内部組み立てに使う全variantを持つ。ページ実装では直接使わず、`components/Button`を使うこと。',
      },
    },
  },
  args: { onClick: fn() },
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'secondary', 'destructive', 'outline', 'ghost', 'link'],
    },
    size: {
      control: 'select',
      options: ['default', 'sm', 'lg', 'icon'],
    },
  },
} satisfies Meta<typeof Button>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = { args: { children: '保存' } }
export const Secondary: Story = { args: { variant: 'secondary', children: 'キャンセル' } }
export const Destructive: Story = { args: { variant: 'destructive', children: '削除' } }
export const Outline: Story = { args: { variant: 'outline', children: '編集' } }
export const Ghost: Story = { args: { variant: 'ghost', children: '詳細' } }
export const Disabled: Story = { args: { children: '保存', disabled: true } }
