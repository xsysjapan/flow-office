import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { GroupOption } from '../../api/types'
import { GroupPicker } from './GroupPicker'

const groups: GroupOption[] = [
  { id: 'group-1', name: '営業部' },
  { id: 'group-2', name: '開発部' },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['groups', 'list'], groups)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <GroupPicker id="group" value={undefined} onChange={fn()} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/GroupPicker',
  component: GroupPicker,
} satisfies Meta<typeof GroupPicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { id: 'group', value: undefined, onChange: fn() },
  render: withSeeded(),
}
