import type { Meta, StoryObj } from '@storybook/react-vite'
import { ApiError } from '../../api/client'
import { ErrorMessage } from './ErrorMessage'

const meta = {
  title: 'Components/ErrorMessage',
  component: ErrorMessage,
  tags: ['autodocs'],
} satisfies Meta<typeof ErrorMessage>

export default meta
type Story = StoryObj<typeof meta>

export const GenericError: Story = {
  args: { error: new Error('通信に失敗しました。') },
}

export const ApiErrorWithFieldErrors: Story = {
  args: {
    error: new ApiError(422, 'The title field is required.', {
      title: ['タイトルは必須です。'],
      approver_user_id: ['承認者を指定してください。'],
    }),
  },
}

export const UnknownError: Story = {
  args: { error: 'string-error' },
}
