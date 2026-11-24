import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { ListFilterBar } from './ListFilterBar'
import { filterWidth } from './filterWidth'
import { Input } from '../ui/input'

const meta = {
  title: 'Components/ListFilterBar',
  component: ListFilterBar,
  tags: ['autodocs'],
  args: { isFiltered: false, onClear: fn(), children: null },
} satisfies Meta<typeof ListFilterBar>

export default meta
type Story = StoryObj<typeof meta>

const items = (
  <>
    <div className={filterWidth.keyword}>
      <Input aria-label="キーワード" placeholder="タイトル・種別・担当部署" />
    </div>
    <div className={filterWidth.select}>
      <Input aria-label="ステータス" placeholder="ステータス" />
    </div>
  </>
)

export const Default: Story = { args: { children: items } }

export const Filtered: Story = { args: { children: items, isFiltered: true } }

export const WithAdvanced: Story = {
  args: {
    children: items,
    advanced: (
      <>
        <Input aria-label="管理番号" placeholder="管理番号" />
        <Input aria-label="名称" placeholder="名称" />
        <Input aria-label="設置場所" placeholder="設置場所" />
      </>
    ),
  },
}

export const WithAdvancedActive: Story = {
  args: { ...WithAdvanced.args, advancedActiveCount: 2, isFiltered: true },
}

export const Narrow: Story = {
  args: { ...WithAdvancedActive.args },
  decorators: [(Story) => <div style={{ width: 360 }}><Story /></div>],
}
