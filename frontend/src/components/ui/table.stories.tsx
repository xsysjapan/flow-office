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

const wideColumns = [
  '氏名', 'メールアドレス', '社員番号', 'Microsoft', '主所属', '雇用区分',
  '部署', '役職', '在籍状況', 'アカウント状態', '管理元', 'グループ（所属）',
]

/** 社員一覧のように12列あり、横にはみ出す表。はみ出している側の端に影が出る(スクロールして確認する)。 */
export const WideTable: Story = {
  render: () => (
    <div className="max-w-3xl">
      <Table>
        <TableHeader>
          <TableRow>
            {wideColumns.map((column) => (
              <TableHead key={column}>{column}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {[1, 2, 3].map((row) => (
            <TableRow key={row}>
              {wideColumns.map((column) => (
                <TableCell key={column}>{`${column} ${row}`}</TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  ),
}
