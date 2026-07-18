import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as devicesApi from '../../api/devices'
import type { Device } from '../../api/types'
import { DeviceListPage } from './DeviceListPage'

const devices: Device[] = [
  {
    id: 1,
    owner_type: 'organization_shared',
    owner_user_id: null,
    name: '本社1階受付',
    device_type: 'android',
    status: 'active',
    site_id: null,
    location_name: '本社1階受付カウンター',
    default_work_location_type: 'office',
    timezone: null,
    allowed_punch_types: null,
    allow_offline: true,
    require_location: false,
    auto_detect_punch_type: false,
    app_version: '1.2.0',
    last_seen_at: '2026-07-18T00:00:00+09:00',
    paired_at: '2026-07-01T00:00:00+09:00',
    disabled_at: null,
    revoked_at: null,
    roles: ['attendance_reader'],
    scopes: [],
    created_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 2,
    owner_type: 'organization_shared',
    owner_user_id: null,
    name: '大阪支店リーダー',
    device_type: 'nfc_reader',
    status: 'pending_pairing',
    site_id: null,
    location_name: '大阪支店エントランス付近',
    default_work_location_type: null,
    timezone: null,
    allowed_punch_types: null,
    allow_offline: true,
    require_location: false,
    auto_detect_punch_type: false,
    app_version: null,
    last_seen_at: null,
    paired_at: null,
    disabled_at: null,
    revoked_at: null,
    roles: ['attendance_reader'],
    scopes: [],
    created_at: '2026-07-10T00:00:00+09:00',
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue(devices)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DeviceListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DeviceListPage', () => {
  it('lists shared devices with their status', async () => {
    renderPage()

    expect(await screen.findByText('本社1階受付')).toBeInTheDocument()
    expect(screen.getByText('大阪支店リーダー')).toBeInTheDocument()
    expect(screen.getByText('稼働中')).toBeInTheDocument()
    expect(screen.getByText('ペアリング待ち')).toBeInTheDocument()
  })

  it('shows a pairing-claim button only for devices pending pairing', async () => {
    renderPage()

    await screen.findByText('本社1階受付')

    expect(screen.getAllByRole('button', { name: 'ペアリング用QRを発行' })).toHaveLength(1)
  })

  it('issues a pairing claim token and displays it once', async () => {
    const user = userEvent.setup()
    vi.spyOn(devicesApi, 'issueDevicePairingClaim').mockResolvedValue({
      device: devices[1],
      claim_token: '1|abc123secret',
    })
    renderPage()

    await screen.findByText('大阪支店リーダー')
    await user.click(screen.getByRole('button', { name: 'ペアリング用QRを発行' }))

    expect(await screen.findByText('1|abc123secret')).toBeInTheDocument()
    // カメラのない端末向けに、claim tokenをコピーできるボタンも表示する。
    expect(screen.getByRole('button', { name: 'コピー' })).toBeInTheDocument()
  })

  it('opens the registration form and submits a new device', async () => {
    const user = userEvent.setup()
    const registerSpy = vi.spyOn(devicesApi, 'registerDevice').mockResolvedValue(devices[0])
    renderPage()

    await screen.findByText('本社1階受付')
    await user.click(screen.getByRole('button', { name: '新規登録' }))
    await user.type(screen.getByLabelText('名称'), '名古屋支店入口')
    await user.click(screen.getByRole('button', { name: '登録する' }))

    expect(registerSpy).toHaveBeenCalledWith(
      expect.objectContaining({ name: '名古屋支店入口', role_types: ['attendance_reader'] }),
    )
  })

  it('opens a confirmation dialog before revoking a device', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('本社1階受付')
    await user.click(screen.getAllByRole('button', { name: '失効させる' })[0])

    expect(await screen.findByText('端末を失効させますか?')).toBeInTheDocument()
  })
})
