import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { AttendanceSelectionActionBar } from './AttendanceSelectionActionBar'

const meta = {
  title: 'Components/AttendanceSelectionActionBar',
  component: AttendanceSelectionActionBar,
  tags: ['autodocs'],
  args: {
    selectedCount: 0,
    hasSpecialLeaveTypes: true,
    datesQuery: '2026-08-24,2026-08-25',
    onCancel: () => {},
  },
  decorators: [
    (Story) => (
      <MemoryRouter>
        <Story />
      </MemoryRouter>
    ),
  ],
} satisfies Meta<typeof AttendanceSelectionActionBar>

export default meta
type Story = StoryObj<typeof meta>

export const NoSelection: Story = {}

export const WithSelection: Story = {
  args: { selectedCount: 2 },
}

export const WithoutSpecialLeave: Story = {
  args: { selectedCount: 2, hasSpecialLeaveTypes: false },
}

/**
 * スマートフォン幅(375px)での表示確認用。選択件数+キャンセルを1行、申請導線ボタンを
 * 横スクロール可能な別行に分けることで、ボタン群のはみ出し・崩れを防ぐ。
 */
export const MobileWidth: Story = {
  args: { selectedCount: 2 },
  decorators: [
    (Story) => (
      <div style={{ width: 375, border: '1px dashed var(--color-border, #ccc)', padding: 8 }}>
        <Story />
      </div>
    ),
  ],
}
