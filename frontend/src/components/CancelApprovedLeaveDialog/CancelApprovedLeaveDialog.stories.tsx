import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { CancelApprovedLeaveDialog, type ApprovedLeaveTarget } from './CancelApprovedLeaveDialog'

function Demo() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const [target, setTarget] = useState<ApprovedLeaveTarget | null>(null)

  return (
    <QueryClientProvider client={queryClient}>
      <Button onClick={() => setTarget({ kind: 'paid', id: 'paid-leave-request-1', label: '有給休暇' })}>
        有給休暇の承認を取り消す
      </Button>
      <CancelApprovedLeaveDialog target={target} onOpenChange={(open) => !open && setTarget(null)} onCancelled={() => setTarget(null)} />
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Components/CancelApprovedLeaveDialog',
  component: CancelApprovedLeaveDialog,
} satisfies Meta<typeof CancelApprovedLeaveDialog>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { target: null, onOpenChange: () => {}, onCancelled: () => {} },
  render: () => <Demo />,
}
