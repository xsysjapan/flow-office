import type { Meta, StoryObj } from '@storybook/react-vite'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from './dialog'
import { Button } from './button'

const meta = {
  title: 'UI/Dialog',
  tags: ['autodocs'],
  render: () => (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="destructive">申請を取り消す</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>申請を取り消しますか?</DialogTitle>
          <DialogDescription>この操作は取り消せません。</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="secondary">キャンセル</Button>
          <Button variant="destructive">取り消す</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
