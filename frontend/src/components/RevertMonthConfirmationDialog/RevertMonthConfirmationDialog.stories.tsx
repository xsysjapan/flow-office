import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { userEvent, within } from 'storybook/test'
import { RevertMonthConfirmationDialog } from './RevertMonthConfirmationDialog'

const meta = {
  title: 'Components/RevertMonthConfirmationDialog',
  component: RevertMonthConfirmationDialog,
  args: {
    monthId: 'attendance-month-1',
    yearMonth: '2026-07',
  },
  decorators: [
    (Story) => (
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <Story />
      </QueryClientProvider>
    ),
  ],
} satisfies Meta<typeof RevertMonthConfirmationDialog>

export default meta
type Story = StoryObj<typeof meta>

async function openDialog(canvasElement: HTMLElement) {
  const canvas = within(canvasElement)
  await userEvent.click(canvas.getByRole('button', { name: '未提出に戻す' }))
}

export const Closed: Story = {}

export const Open: Story = {
  play: async ({ canvasElement }) => openDialog(canvasElement),
}

export const SubmitWithoutMessage: Story = {
  play: async ({ canvasElement }) => {
    await openDialog(canvasElement)
    const dialog = within(canvasElement.ownerDocument.body)
    await userEvent.click(dialog.getByRole('button', { name: '未提出に戻す' }))
  },
}
