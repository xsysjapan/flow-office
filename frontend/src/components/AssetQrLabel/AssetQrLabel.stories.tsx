import type { Meta, StoryObj } from '@storybook/react-vite'
import { AssetQrLabel } from './AssetQrLabel'

const meta = {
  title: 'Components/AssetQrLabel',
  component: AssetQrLabel,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          '備品詳細画面に表示するQRラベル。QRの中身は`AssetResource.qr_url`(備品詳細への遷移URL)で、物理的な備品に貼るラベルとしてブラウザの印刷機能でそのまま出力できるレイアウトにしている。',
      },
    },
  },
} satisfies Meta<typeof AssetQrLabel>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    qrUrl: 'https://example.com/assets/qr/EXAMPLE-QR-TOKEN-0001',
    assetNo: 'EQ-00121',
    name: 'ノートPC (ThinkPad X1)',
  },
}

export const LongName: Story = {
  args: {
    qrUrl: 'https://example.com/assets/qr/EXAMPLE-QR-TOKEN-0002',
    assetNo: 'EQ-00299',
    name: '会議室用プロジェクター (EPSON EB-2247U)',
    size: 220,
  },
}
