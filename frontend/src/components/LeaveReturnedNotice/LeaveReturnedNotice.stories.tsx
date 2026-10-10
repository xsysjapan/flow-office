import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { LeaveReturnedNotice } from './LeaveReturnedNotice'

const meta = {
  title: 'Components/LeaveReturnedNotice',
  component: LeaveReturnedNotice,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <MemoryRouter>
        <Story />
      </MemoryRouter>
    ),
  ],
  argTypes: {
    subjectType: {
      control: 'select',
      options: ['paid_leave_request', 'special_leave_request', 'compensatory_leave_request'],
    },
  },
} satisfies Meta<typeof LeaveReturnedNotice>

export default meta
type Story = StoryObj<typeof meta>

export const PaidLeave: Story = {
  args: { subjectType: 'paid_leave_request' },
}

export const SpecialLeave: Story = {
  args: { subjectType: 'special_leave_request' },
}

export const CompensatoryLeave: Story = {
  args: { subjectType: 'compensatory_leave_request' },
}
