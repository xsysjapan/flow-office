import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { RevokeGrantButton } from './RevokeGrantButton'

const meta = {
  title: 'Components/RevokeGrantButton',
  component: RevokeGrantButton,
} satisfies Meta<typeof RevokeGrantButton>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    id: 'revoke-reason',
    title: '付与を取り消しますか?',
    description: 'この操作は元に戻せません。',
    onRevoke: fn(),
  },
}

export const Disabled: Story = {
  args: {
    id: 'revoke-reason',
    title: '付与を取り消しますか?',
    description: 'この操作は元に戻せません。',
    onRevoke: fn(),
    disabled: true,
    disabledReason: '既に消化された分は取り消せません。',
  },
}
