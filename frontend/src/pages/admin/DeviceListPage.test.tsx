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
    id: 'device-1',
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
    deleted_at: null,
    roles: ['attendance_reader'],
    scopes: [],
    created_at: '2026-07-01T00:00:00+09:00',
  },
  {
    id: 'device-2',
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
    deleted_at: null,
    roles: ['attendance_reader'],
    scopes: [],
    created_at: '2026-07-10T00:00:00+09:00',
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue({
    data: devices,
    meta: { current_page: 1, last_page: 1, total: devices.length },
    links: { next: null, prev: null },
  })

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

  it('only offers navigate-to-detail, revoke, and delete as row actions', async () => {
    renderPage()

    await screen.findByText('本社1階受付')

    expect(screen.getAllByRole('button', { name: '詳細' })).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'ペアリング用QRを発行' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '停止する' })).not.toBeInTheDocument()
  })

  it('opens the device detail modal when a row is clicked', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('本社1階受付')
    await user.click(screen.getByText('本社1階受付'))

    expect(await screen.findByRole('heading', { name: '本社1階受付' })).toBeInTheDocument()
  })

  it('opens the device detail modal from the 詳細 button', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('大阪支店リーダー')
    await user.click(screen.getAllByRole('button', { name: '詳細' })[1])

    expect(await screen.findByRole('heading', { name: '大阪支店リーダー' })).toBeInTheDocument()
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

  it('shows a delete button only for disabled or revoked devices', async () => {
    const fetchSpy = vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue({
      data: [
        { ...devices[0], status: 'disabled', disabled_at: '2026-07-01T00:00:00+09:00' },
        { ...devices[1], status: 'pending_pairing' },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    })
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <DeviceListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText('本社1階受付')
    expect(screen.getAllByRole('button', { name: '削除する' })).toHaveLength(1)
    fetchSpy.mockRestore()
  })

  it('toggles the with_trashed query param when showing deleted devices', async () => {
    const user = userEvent.setup()
    const fetchSpy = vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue({
      data: devices,
      meta: { current_page: 1, last_page: 1, total: devices.length },
      links: { next: null, prev: null },
    })
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <DeviceListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText('本社1階受付')
    await user.click(screen.getByRole('button', { name: '削除済みを表示' }))

    expect(fetchSpy).toHaveBeenLastCalledWith({ ownerType: 'organization_shared', page: 1, withTrashed: true })
    fetchSpy.mockRestore()
  })
})
