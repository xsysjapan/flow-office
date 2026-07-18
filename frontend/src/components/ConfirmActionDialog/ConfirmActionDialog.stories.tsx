import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn, userEvent, within } from 'storybook/test'
import { Input } from '../ui/input'
import { ConfirmActionDialog } from './ConfirmActionDialog'

const meta = {
  title: 'Components/ConfirmActionDialog',
  component: ConfirmActionDialog,
  args: {
    triggerLabel: '失効させる',
    title: '端末を失効させますか?',
    description: '「本社1階受付」を失効させます。この操作は取り消せません。',
    confirmLabel: '失効させる',
    onConfirm: fn(),
    isPending: false,
  },
} satisfies Meta<typeof ConfirmActionDialog>

export default meta
type Story = StoryObj<typeof meta>

async function openDialog(canvasElement: HTMLElement) {
  const canvas = within(canvasElement)
  await userEvent.click(canvas.getByRole('button', { name: '失効させる' }))
}

export const Closed: Story = {}

export const Open: Story = {
  play: async ({ canvasElement }) => openDialog(canvasElement),
}

export const WithReasonInput: Story = {
  args: {
    children: <Input aria-label="失効理由" placeholder="失効理由(任意)" />,
  },
  play: async ({ canvasElement }) => openDialog(canvasElement),
}

export const Pending: Story = {
  args: {
    isPending: true,
  },
  play: async ({ canvasElement }) => openDialog(canvasElement),
}

export const WithError: Story = {
  args: {
    error: new Error('失効に失敗しました。'),
  },
  play: async ({ canvasElement }) => openDialog(canvasElement),
}
