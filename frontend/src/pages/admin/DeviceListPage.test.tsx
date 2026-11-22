import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as devicesApi from '../../api/devices'
import { ApiError } from '../../api/client'
import type { Device } from '../../api/types'
import { notify } from '../../lib/notify'
import { DeviceListPage } from './DeviceListPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

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
    scopes: [],
    created_at: '2026-07-10T00:00:00+09:00',
  },
]

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderPage(initialEntry = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue({
    data: devices,
    meta: { current_page: 1, last_page: 1, total: devices.length },
    links: { next: null, prev: null },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <DeviceListPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DeviceListPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('lists shared devices with their status', async () => {
    renderPage()

    expect(await screen.findByText('本社1階受付')).toBeInTheDocument()
    expect(screen.getByText('大阪支店リーダー')).toBeInTheDocument()
    expect(screen.getByText('稼働中')).toBeInTheDocument()
    expect(screen.getByText('ペアリング待ち')).toBeInTheDocument()
  })

  it('puts detail, revoke, and delete behind the row 「⋯」 menu', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('本社1階受付')

    expect(screen.queryByRole('button', { name: '詳細' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '失効させる' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /の操作$/ })).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'ペアリング用QRを発行' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '停止する' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '本社1階受付の操作' }))
    expect(await screen.findByRole('menuitem', { name: '詳細' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: '失効させる' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '削除する' })).not.toBeInTheDocument()
  })

  it('opens the device detail modal when a row is clicked', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('本社1階受付')
    await user.click(screen.getByText('本社1階受付'))

    expect(await screen.findByRole('heading', { name: '本社1階受付' })).toBeInTheDocument()
  })

  it('opens the device detail modal from the 詳細 menu item', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('大阪支店リーダー')
    await user.click(screen.getByRole('button', { name: '大阪支店リーダーの操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '詳細' }))

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
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('名古屋支店入口を登録しました')
    })
  })

  it('confirms before revoking from the row menu and shows a toast after revoking', async () => {
    const user = userEvent.setup()
    const revokeSpy = vi.spyOn(devicesApi, 'revokeDevice').mockResolvedValue(devices[0])
    renderPage()

    await screen.findByText('本社1階受付')
    await user.click(screen.getByRole('button', { name: '本社1階受付の操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '失効させる' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('端末を失効させますか?')).toBeInTheDocument()
    expect(revokeSpy).not.toHaveBeenCalled()

    await user.click(within(dialog).getByRole('button', { name: '失効させる' }))

    expect(revokeSpy.mock.calls[0]?.[0]).toBe('device-1')
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('本社1階受付を失効しました')
    })
  })

  it('shows the delete menu item only for disabled or revoked devices and confirms before deleting', async () => {
    const user = userEvent.setup()
    const fetchSpy = vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue({
      data: [
        { ...devices[0], status: 'disabled', disabled_at: '2026-07-01T00:00:00+09:00' },
        { ...devices[1], status: 'pending_pairing' },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    })
    const deleteSpy = vi.spyOn(devicesApi, 'deleteDevice').mockResolvedValue(undefined)
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <DeviceListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText('本社1階受付')
    await user.click(screen.getByRole('button', { name: '大阪支店リーダーの操作' }))
    expect(await screen.findByRole('menuitem', { name: '詳細' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '削除する' })).not.toBeInTheDocument()
    await user.keyboard('{Escape}')

    await user.click(screen.getByRole('button', { name: '本社1階受付の操作' }))
    await user.click(await screen.findByRole('menuitem', { name: '削除する' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('端末を削除しますか?')).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: '削除する' }))

    expect(deleteSpy).toHaveBeenCalledWith('device-1')
    await vi.waitFor(() => {
      expect(notify.success).toHaveBeenCalledWith('本社1階受付を削除しました')
    })
    fetchSpy.mockRestore()
  })

  it('opens the device detail modal when a row is activated via keyboard', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('本社1階受付')
    screen.getByRole('row', { name: '本社1階受付の詳細を開く' }).focus()
    await user.keyboard('{Enter}')

    expect(await screen.findByRole('heading', { name: '本社1階受付' })).toBeInTheDocument()
  })

  it('shows an empty state with no shared devices registered', async () => {
    const fetchSpy = vi.spyOn(devicesApi, 'fetchDevices').mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
      links: { next: null, prev: null },
    })
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <DeviceListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('登録済みの共有端末はまだありません。')).toBeInTheDocument()
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

  it('writes deviceId to the URL when the panel opens and removes it when closed', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('本社1階受付')
    expect(screen.getByTestId('location-search')).toBeEmptyDOMElement()
    await user.click(screen.getByText('本社1階受付'))
    expect(await screen.findByRole('heading', { name: '本社1階受付' })).toBeInTheDocument()
    expect(screen.getByTestId('location-search')).toHaveTextContent('?deviceId=device-1')

    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
    expect(screen.queryByRole('heading', { name: '本社1階受付' })).not.toBeInTheDocument()
  })

  it('opens the panel from ?deviceId even when the device is not on the current page', async () => {
    const fetchSpy = vi.spyOn(devicesApi, 'fetchDevice').mockResolvedValue({ ...devices[0], id: 'device-9', name: '別ページの端末' })
    renderPage('/?deviceId=device-9')

    expect(await screen.findByRole('heading', { name: '別ページの端末' })).toBeInTheDocument()
    expect(fetchSpy).toHaveBeenCalledWith('device-9')
    expect(screen.getByTestId('location-search')).toHaveTextContent('?deviceId=device-9')
  })

  it('removes deviceId from the URL when the device is not found (404)', async () => {
    vi.spyOn(devicesApi, 'fetchDevice').mockRejectedValue(new ApiError(404, 'not found'))
    renderPage('/?deviceId=missing')

    await screen.findByText('本社1階受付')
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
  })

  it('drops an invalid page from the URL and shows the first page', async () => {
    renderPage('/?page=abc&show_deleted=1')

    await screen.findByText('本社1階受付')
    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?show_deleted=1$/))
    expect(devicesApi.fetchDevices).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1 }))
  })
})
