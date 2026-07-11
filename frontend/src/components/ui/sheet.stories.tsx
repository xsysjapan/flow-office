import type { Meta, StoryObj } from '@storybook/react-vite'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from './sheet'
import { Button } from './button'

const meta = {
  title: 'UI/Sheet',
  tags: ['autodocs'],
  render: () => (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline">メニュー</Button>
      </SheetTrigger>
      <SheetContent side="left">
        <SheetHeader>
          <SheetTitle>メニュー</SheetTitle>
        </SheetHeader>
      </SheetContent>
    </Sheet>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
