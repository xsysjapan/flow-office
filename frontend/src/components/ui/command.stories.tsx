import type { Meta, StoryObj } from '@storybook/react-vite'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from './command'

const meta = {
  title: 'UI/Command',
  tags: ['autodocs'],
  render: () => (
    <Command className="w-72 rounded-md border border-border">
      <CommandInput placeholder="氏名またはメールアドレスで検索" />
      <CommandList>
        <CommandEmpty>該当する社員が見つかりません</CommandEmpty>
        <CommandGroup>
          <CommandItem>山田 太郎(taro.yamada@example.com)</CommandItem>
          <CommandItem>佐藤 花子(hanako.sato@example.com)</CommandItem>
        </CommandGroup>
      </CommandList>
    </Command>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
