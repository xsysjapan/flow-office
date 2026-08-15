import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ClickableTableRow } from './ClickableTableRow'

const meta = {
  title: 'Components/ClickableTableRow',
  component: ClickableTableRow,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          '一覧の行クリックで対象オブジェクトの詳細を開く際の共通実装。行内にButton/Linkを置く場合はそちら側で`event.stopPropagation()`を呼ぶ。',
      },
    },
  },
} satisfies Meta<typeof ClickableTableRow>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    onRowClick: fn(),
    rowLabel: '山田太郎の詳細を開く',
  },
  render: (args) => (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>氏名</TableHead>
          <TableHead>状態</TableHead>
          <TableHead>操作</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <ClickableTableRow {...args}>
          <TableCell className="font-medium text-foreground">山田 太郎</TableCell>
          <TableCell>
            <Badge tone="success">有効</Badge>
          </TableCell>
          <TableCell>
            <div onClick={(e) => e.stopPropagation()}>
              <Button size="sm" variant="danger">
                削除
              </Button>
            </div>
          </TableCell>
        </ClickableTableRow>
      </TableBody>
    </Table>
  ),
}

export const Disabled: Story = {
  args: {
    onRowClick: fn(),
    rowLabel: '佐藤花子の詳細を開く',
    disabled: true,
  },
  render: (args) => (
    <Table>
      <TableBody>
        <ClickableTableRow {...args}>
          <TableCell className="font-medium text-foreground">佐藤 花子</TableCell>
          <TableCell>
            <Badge tone="neutral">削除済み</Badge>
          </TableCell>
        </ClickableTableRow>
      </TableBody>
    </Table>
  ),
}
