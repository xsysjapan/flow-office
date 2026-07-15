import type { Meta, StoryObj } from '@storybook/react-vite'
import { Duration } from './Duration'

const meta = {
  title: 'Components/Duration',
  component: Duration,
  tags: ['autodocs'],
} satisfies Meta<typeof Duration>

export default meta
type Story = StoryObj<typeof meta>

export const HoursOnly: Story = {
  args: { minutes: 480 },
}

export const HoursAndMinutes: Story = {
  args: { minutes: 495 },
}

export const MinutesOnly: Story = {
  args: { minutes: 30 },
}