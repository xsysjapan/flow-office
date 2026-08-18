import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { LeaveUsageList } from './LeaveUsageList'

const meta = {
  title: 'Components/LeaveUsageList',
  component: LeaveUsageList,
} satisfies Meta<typeof LeaveUsageList>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    isLoading: false,
    onCancelRequest: fn(),
    usages: [
      {
        id: 'usage-1',
        usedOn: '2026-08-10',
        usedDays: 1,
        usedMinutes: null,
        usageType: 'full',
        requestStatus: 'approved',
        requestId: 'request-1',
      },
      {
        id: 'usage-2',
        usedOn: '2026-07-01',
        usedDays: 0.5,
        usedMinutes: null,
        usageType: 'am_half',
        requestStatus: 'cancelled',
        requestId: 'request-2',
      },
    ],
  },
}

export const MultiGrantRequest: Story = {
  args: {
    isLoading: false,
    onCancelRequest: fn(),
    usages: [
      {
        id: 'usage-3',
        usedOn: '2026-08-15',
        usedDays: 1,
        usedMinutes: null,
        usageType: 'full',
        requestStatus: 'approved',
        requestId: 'request-3',
      },
      {
        id: 'usage-4',
        usedOn: '2026-08-15',
        usedDays: 0,
        usedMinutes: null,
        usageType: 'full',
        requestStatus: 'approved',
        requestId: 'request-3',
      },
    ],
  },
}

export const Loading: Story = {
  args: {
    isLoading: true,
    onCancelRequest: fn(),
    usages: undefined,
  },
}

export const Empty: Story = {
  args: {
    isLoading: false,
    onCancelRequest: fn(),
    usages: [],
  },
}
