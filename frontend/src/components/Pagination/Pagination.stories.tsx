import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { Pagination } from './Pagination'

const meta = {
  title: 'Components/Pagination',
  component: Pagination,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component: '一覧画面(端末一覧など)向けの前/次ページ送り。lastPageが1以下の場合は何も表示しない。',
      },
    },
  },
  args: {
    onPageChange: fn(),
  },
} satisfies Meta<typeof Pagination>

export default meta
type Story = StoryObj<typeof meta>

export const MiddlePage: Story = {
  args: { currentPage: 2, lastPage: 5, total: 92 },
}

export const FirstPage: Story = {
  args: { currentPage: 1, lastPage: 5, total: 92 },
}

export const LastPage: Story = {
  args: { currentPage: 5, lastPage: 5, total: 92 },
}

export const SinglePage: Story = {
  args: { currentPage: 1, lastPage: 1, total: 3 },
}
