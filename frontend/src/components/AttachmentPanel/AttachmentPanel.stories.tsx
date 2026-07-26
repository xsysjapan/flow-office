import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Attachment } from '../../api/types'
import { AttachmentPanel } from './AttachmentPanel'

const sampleAttachments: Attachment[] = [
  { id: 'attachment-1', file_name: 'receipt.pdf', mime_type: 'application/pdf', file_size: 20480, uploaded_by: 'applicant-1', created_at: null },
  { id: 'attachment-2', file_name: 'memo.txt', mime_type: 'text/plain', file_size: 512, uploaded_by: 'applicant-1', created_at: null },
]

function withSeeded(ownerId: string, attachments: Attachment[] | undefined, required = false) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  if (attachments) {
    queryClient.setQueryData(['attachments', 'ExpenseItem', ownerId], attachments)
  }

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <div className="max-w-md">
          <AttachmentPanel ownerType="ExpenseItem" ownerId={ownerId} required={required} />
        </div>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/AttachmentPanel',
  component: AttachmentPanel,
  args: { ownerType: 'ExpenseItem', ownerId: 'expense-item-1' },
} satisfies Meta<typeof AttachmentPanel>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded('expense-item-1', sampleAttachments),
}

export const Empty: Story = {
  render: withSeeded('expense-item-2', []),
}

export const ReadOnly: Story = {
  render: () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
    queryClient.setQueryData(['attachments', 'ExpenseItem', 'expense-item-3'], sampleAttachments)
    return (
      <QueryClientProvider client={queryClient}>
        <div className="max-w-md">
          <AttachmentPanel ownerType="ExpenseItem" ownerId="expense-item-3" readOnly />
        </div>
      </QueryClientProvider>
    )
  },
}

export const RequiredAndEmpty: Story = {
  render: withSeeded('expense-item-4', [], true),
}

export const Compact: Story = {
  render: () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
    queryClient.setQueryData(['attachments', 'ExpenseItem', 'expense-item-5'], sampleAttachments)
    return (
      <QueryClientProvider client={queryClient}>
        <div className="max-w-xs">
          <AttachmentPanel ownerType="ExpenseItem" ownerId="expense-item-5" compact />
        </div>
      </QueryClientProvider>
    )
  },
}
