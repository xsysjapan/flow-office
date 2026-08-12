import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { CreateCompanyCalendarModal } from './CreateCompanyCalendarModal'

function Decorator() {
  const [queryClient] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: false } } }))
  const [open, setOpen] = useState(true)

  return (
    <QueryClientProvider client={queryClient}>
      <CreateCompanyCalendarModal open={open} onOpenChange={setOpen} />
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Components/CreateCompanyCalendarModal',
  component: CreateCompanyCalendarModal,
} satisfies Meta<typeof CreateCompanyCalendarModal>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { open: true, onOpenChange: () => {} },
  render: () => <Decorator />,
}
