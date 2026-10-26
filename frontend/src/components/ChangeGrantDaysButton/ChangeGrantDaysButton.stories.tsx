import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { ChangeGrantDaysButton } from './ChangeGrantDaysButton'

const meta = {
  title: 'Components/ChangeGrantDaysButton',
  component: ChangeGrantDaysButton,
} satisfies Meta<typeof ChangeGrantDaysButton>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    id: 'change-days',
    grantedOn: '2026-07-01',
    currentDays: 10,
    usedDays: 1,
    onChange: fn(),
  },
}

export const Disabled: Story = {
  args: {
    id: 'change-days',
    grantedOn: '2026-07-01',
    currentDays: 10,
    usedDays: 1,
    onChange: fn(),
    disabled: true,
    disabledReason: '最新の付与のみ変更できます。',
  },
}
