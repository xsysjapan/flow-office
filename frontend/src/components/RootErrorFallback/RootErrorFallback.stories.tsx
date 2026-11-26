import type { Meta, StoryObj } from '@storybook/react-vite'
import { RootErrorFallback } from './RootErrorFallback'

const meta = {
  title: 'Components/RootErrorFallback',
  component: RootErrorFallback,
  tags: ['autodocs'],
} satisfies Meta<typeof RootErrorFallback>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
