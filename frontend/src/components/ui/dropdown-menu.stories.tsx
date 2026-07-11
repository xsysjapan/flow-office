import type { Meta, StoryObj } from '@storybook/react-vite'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from './dropdown-menu'
import { Button } from './button'

const meta = {
  title: 'UI/DropdownMenu',
  tags: ['autodocs'],
  render: () => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline">山田 太郎</Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent>
        <DropdownMenuItem>プロフィール</DropdownMenuItem>
        <DropdownMenuItem variant="destructive">ログアウト</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
