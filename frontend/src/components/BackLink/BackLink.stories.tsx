import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { BackLink } from './BackLink'

const meta = {
  title: 'Components/BackLink',
  component: BackLink,
  tags: ['autodocs'],
  argTypes: {
    to: { control: 'text' },
    label: { control: 'text' },
  },
} satisfies Meta<typeof BackLink>

export default meta
type Story = StoryObj<typeof meta>

/** 遷移元(申請一覧の絞り込み付き)がある場合は、その画面へ戻る。 */
export const WithOrigin: Story = {
  decorators: [
    (Story) => (
      <MemoryRouter
        initialEntries={[
          {
            pathname: '/requests/abc',
            state: { backTo: { pathname: '/requests', search: '?status=pending', hash: '' } },
          },
        ]}
      >
        <Story />
      </MemoryRouter>
    ),
  ],
}

/** 遷移元が無い(URL直接)場合は、階層上の親へ戻る。 */
export const WithoutOrigin: Story = {
  decorators: [
    (Story) => (
      <MemoryRouter initialEntries={['/paid-leave/history']}>
        <Story />
      </MemoryRouter>
    ),
  ],
}
