import type { Meta, StoryObj } from '@storybook/react-vite'
import { DevicePairingQr } from './DevicePairingQr'

const meta = {
  title: 'Components/DevicePairingQr',
  component: DevicePairingQr,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          '端末ペアリング(UC-D002)で発行されたclaim tokenをQRコード化して表示する。QRの中身は`<claimUrl>?claim_token=<token>`という単純なURL(device_id(内部PK)は含めない)。JSON形式にすると汎用QRリーダーがURL部分だけを抜き出してしまうため、URL自体にクエリ文字列として含めている。',
      },
    },
  },
} satisfies Meta<typeof DevicePairingQr>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    claimToken: '1|abcdEXAMPLEclaimToken1234567890',
    claimUrl: 'https://example.com/flow-office/api/devices/pairing/claim',
  },
}

export const Large: Story = {
  args: {
    claimToken: '1|abcdEXAMPLEclaimToken1234567890',
    claimUrl: 'https://example.com/flow-office/api/devices/pairing/claim',
    size: 256,
  },
}
