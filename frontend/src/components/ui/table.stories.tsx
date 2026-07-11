import type { Meta, StoryObj } from '@storybook/react-vite'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './table'
import { Badge } from './badge'

const meta = {
  title: 'UI/Table',
  tags: ['autodocs'],
  render: () => (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>氏名</TableHead>
          <TableHead>種別</TableHead>
          <TableHead>ステータス</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow>
          <TableCell>山田 太郎</TableCell>
          <TableCell>経費精算</TableCell>
          <TableCell>
            <Badge variant="info">提出済み</Badge>
          </TableCell>
        </TableRow>
        <TableRow>
          <TableCell>佐藤 花子</TableCell>
          <TableCell>名刺申請</TableCell>
          <TableCell>
            <Badge variant="success">承認済み</Badge>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
