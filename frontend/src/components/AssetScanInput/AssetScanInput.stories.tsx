import type { Meta, StoryObj } from '@storybook/react'
import { AssetScanInput } from './AssetScanInput'

const meta: Meta<typeof AssetScanInput> = {
  title: 'components/AssetScanInput',
  component: AssetScanInput,
}
export default meta

type Story = StoryObj<typeof AssetScanInput>

export const Default: Story = {
  args: {
    id: 'scan-input-default',
    label: '貸出対象に追加する備品',
    onSubmit: () => {},
  },
}

export const Disabled: Story = {
  args: {
    id: 'scan-input-disabled',
    label: '貸出対象に追加する備品',
    onSubmit: () => {},
    disabled: true,
    disabledReason: '先に貸出先ユーザーを選択してください。',
  },
}
