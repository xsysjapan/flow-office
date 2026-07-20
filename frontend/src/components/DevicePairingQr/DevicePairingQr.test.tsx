import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { DevicePairingQr } from './DevicePairingQr'

const claimUrl = 'https://example.com/flow-office/api/devices/pairing/claim'

describe('DevicePairingQr', () => {
  it('renders an accessible QR image', () => {
    render(<DevicePairingQr claimToken="1|token" claimUrl={claimUrl} />)
    expect(screen.getByRole('img', { name: '端末ペアリング用QRコード' })).toBeInTheDocument()
  })

  it('encodes the claim token as a URL query string rather than JSON', () => {
    render(<DevicePairingQr claimToken="1|abc def" claimUrl={claimUrl} />)
    const svg = screen.getByRole('img', { name: '端末ペアリング用QRコード' }).querySelector('svg')
    expect(svg).toBeTruthy()
  })

  it('accepts a custom size', () => {
    render(<DevicePairingQr claimToken="1|abcdef" claimUrl={claimUrl} size={256} />)
    const svg = screen.getByRole('img', { name: '端末ペアリング用QRコード' }).querySelector('svg')
    expect(svg).toHaveAttribute('height', '256')
    expect(svg).toHaveAttribute('width', '256')
  })

  it('accepts a custom claim URL', () => {
    render(<DevicePairingQr claimToken="1|abcdef" claimUrl="https://example.com/devices/pairing/claim" />)
    expect(screen.getByRole('img', { name: '端末ペアリング用QRコード' })).toBeInTheDocument()
  })
})
