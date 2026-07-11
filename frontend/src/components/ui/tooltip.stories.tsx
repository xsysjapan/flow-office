import type { Meta, StoryObj } from '@storybook/react-vite'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from './tooltip'
import { Button } from './button'

const meta = {
  title: 'UI/Tooltip',
  tags: ['autodocs'],
  render: () => (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="sm">
            編集
          </Button>
        </TooltipTrigger>
        <TooltipContent>この操作は取り消せません</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
