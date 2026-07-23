import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { AttendanceDay } from '../../api/types'
import { AttendanceDayRow } from './AttendanceDayRow'

const day: AttendanceDay = {
  id: 1,
  user_id: '11111111-1111-1111-1111-111111111111',
  work_date: '2026-07-06',
  status: 'clocked_out',
  actual_start_at: '2026-07-06T09:00:00+09:00',
  actual_end_at: '2026-07-06T18:00:00+09:00',
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
}

const meta = {
  title: 'Components/AttendanceDayRow',
  component: AttendanceDayRow,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <MemoryRouter>
        <ul>
          <Story />
        </ul>
      </MemoryRouter>
    ),
  ],
} satisfies Meta<typeof AttendanceDayRow>

export default meta
type Story = StoryObj<typeof meta>

export const Recorded: Story = {
  args: { date: '2026-07-06', day },
}

export const NotEntered: Story = {
  args: { date: '2026-07-07', day: undefined },
}

export const WithWarnings: Story = {
  args: { date: '2026-07-08', day: undefined, warnings: ['未入力'] },
}
