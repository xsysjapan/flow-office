import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { PageHeader } from './PageHeader'

const meta = {
  title: 'Components/PageHeader',
  component: PageHeader,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <MemoryRouter initialEntries={['/admin/users']}>
        <Story />
      </MemoryRouter>
    ),
  ],
} satisfies Meta<typeof PageHeader>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    description: '社員・協力会社社員のアカウントを検索し、作成や詳細の確認・編集ができます。',
  },
}

export const WithActions: Story = {
  args: {
    description: '社員・協力会社社員のアカウントを検索し、作成や詳細の確認・編集ができます。',
    actions: (
      <>
        <Button variant="secondary">外部ID・HR連携</Button>
        <Button>新規作成</Button>
      </>
    ),
  },
}

export const WithMeta: Story = {
  args: {
    title: '会社カレンダー詳細',
    description: '会社カレンダーの基本設定、年度、休日ソースを確認・編集できます。',
    meta: <Badge>デフォルト</Badge>,
    actions: <Button variant="secondary">デフォルトに設定する</Button>,
  },
}
