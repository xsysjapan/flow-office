import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { FormLayout } from '../FormLayout/FormLayout'
import { FormActions } from './FormActions'

const meta = {
  title: 'Components/FormActions',
  component: FormActions,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <FormLayout>
        <Story />
      </FormLayout>
    ),
  ],
} satisfies Meta<typeof FormActions>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    children: (
      <>
        <Button variant="secondary">キャンセル</Button>
        <Button variant="primary">保存</Button>
      </>
    ),
  },
}

export const ThreeButtons: Story = {
  args: {
    children: (
      <>
        <Button variant="secondary">キャンセル</Button>
        <Button variant="secondary">下書き保存</Button>
        <Button variant="primary">申請する</Button>
      </>
    ),
  },
}
