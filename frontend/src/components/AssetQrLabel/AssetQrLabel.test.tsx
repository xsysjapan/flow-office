import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AssetQrLabel } from './AssetQrLabel'

const qrUrl = 'https://example.com/assets/qr/abc123'

describe('AssetQrLabel', () => {
  it('renders an accessible QR image encoding the asset qr_url', () => {
    render(<AssetQrLabel qrUrl={qrUrl} assetNo="EQ-00121" name="ノートPC" />)
    expect(screen.getByRole('img', { name: 'EQ-00121のQRコード' })).toBeInTheDocument()
  })

  it('displays the asset number and name for the printed label', () => {
    render(<AssetQrLabel qrUrl={qrUrl} assetNo="EQ-00121" name="ノートPC" />)
    expect(screen.getByText('EQ-00121')).toBeInTheDocument()
    expect(screen.getByText('ノートPC')).toBeInTheDocument()
  })

  it('accepts a custom size', () => {
    render(<AssetQrLabel qrUrl={qrUrl} assetNo="EQ-00121" name="ノートPC" size={256} />)
    const svg = screen.getByRole('img', { name: 'EQ-00121のQRコード' }).querySelector('svg')
    expect(svg).toHaveAttribute('height', '256')
    expect(svg).toHaveAttribute('width', '256')
  })
})
