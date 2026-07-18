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
          '端末ペアリング(UC-D002)で発行されたclaim tokenをQRコード化して表示する。QRの中身は`{url, claim_token}`のJSONで、device_id(内部PK)は含めない。',
      },
    },
  },
} satisfies Meta<typeof DevicePairingQr>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    claimToken: '1|abcdEXAMPLEclaimToken1234567890',
  },
}

export const Large: Story = {
  args: {
    claimToken: '1|abcdEXAMPLEclaimToken1234567890',
    size: 256,
  },
}
