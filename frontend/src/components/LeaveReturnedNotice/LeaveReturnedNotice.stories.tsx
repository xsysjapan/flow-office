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
    workflowRequestId: {
      control: 'text',
    },
  },
} satisfies Meta<typeof LeaveReturnedNotice>

export default meta
type Story = StoryObj<typeof meta>

export const WithRequestDetailLink: Story = {
  args: { workflowRequestId: 'workflow-request-1' },
}

export const WithoutWorkflowRequest: Story = {
  args: { workflowRequestId: null },
}
