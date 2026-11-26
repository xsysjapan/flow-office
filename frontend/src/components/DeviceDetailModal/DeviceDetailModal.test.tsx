import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as devicesApi from '../../api/devices'
import type { Device } from '../../api/types'
import { notify } from '../../lib/notify'
import { DeviceDetailModal } from './DeviceDetailModal'

const device: Device = {
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
  allows_offline: true,
  requires_location: false,
  punch_type_auto_detection_enabled: false,
  app_version: '1.2.0',
  last_seen_at: '2026-07-18T09:00:00+09:00',
  paired_at: '2026-07-01T09:00:00+09:00',
  disabled_at: null,
  revoked_at: null,
  deleted_at: null,
  scopes: [],
  created_at: '2026-07-01T00:00:00+09:00',
}

function renderModal(overrides: Partial<Device> = {}, props: { open?: boolean } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <DeviceDetailModal device={{ ...device, ...overrides }} {...props} />
    </QueryClientProvider>,
  )
}

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

describe('DeviceDetailModal', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('opens the modal from the trigger button and shows device details', async () => {
    const user = userEvent.setup()
    renderModal()

    await user.click(screen.getByRole('button', { name: '詳細' }))

    expect(screen.getByRole('heading', { name: '本社1階受付' })).toBeInTheDocument()
    expect(screen.getByText('稼働中')).toBeInTheDocument()
    expect(screen.getByDisplayValue('本社1階受付カウンター')).toBeInTheDocument()
  })

  it('shows a QR issuance button only for devices pending pairing', async () => {
    const user = userEvent.setup()
    renderModal({ status: 'pending_pairing' })

    await user.click(screen.getByRole('button', { name: '詳細' }))

    expect(screen.getByRole('button', { name: 'ペアリング用QRを発行' })).toBeInTheDocument()
  })

  it('does not show a QR issuance button for an active device', async () => {
    const user = userEvent.setup()
    renderModal({ status: 'active' })

    await user.click(screen.getByRole('button', { name: '詳細' }))

    expect(screen.queryByRole('button', { name: 'ペアリング用QRを発行' })).not.toBeInTheDocument()
  })

  it('issues a pairing claim and renders the QR code', async () => {
    const user = userEvent.setup()
    vi.spyOn(devicesApi, 'issueDevicePairingClaim').mockResolvedValue({
      device: { ...device, status: 'pending_pairing' },
      claim_token: '1|abc123secret',
      claim_url: 'https://example.com/flow-office/api/devices/pairing/claim',
    })
    renderModal({ status: 'pending_pairing' })

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.click(screen.getByRole('button', { name: 'ペアリング用QRを発行' }))

    expect(await screen.findByText('1|abc123secret')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: '端末ペアリング用QRコード' })).toBeInTheDocument()
  })

  it('lets the admin re-pair an already active device after confirming', async () => {
    const user = userEvent.setup()
    const issueSpy = vi.spyOn(devicesApi, 'issueDevicePairingClaim').mockResolvedValue({
      device: { ...device, status: 'pending_pairing' },
      claim_token: '1|resecret',
      claim_url: 'https://example.com/flow-office/api/devices/pairing/claim',
    })
    renderModal({ status: 'active' })

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.click(screen.getByRole('button', { name: '再ペアリング用QRを発行' }))
    expect(await screen.findByText('端末を再ペアリングしますか?')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: '発行する' }))

    expect(issueSpy).toHaveBeenCalledWith('device-1')
    expect(await screen.findByText('1|resecret')).toBeInTheDocument()
  })

  it('lets the admin disable an active device', async () => {
    const user = userEvent.setup()
    const disableSpy = vi.spyOn(devicesApi, 'disableDevice').mockResolvedValue({ ...device, status: 'disabled' })
    renderModal({ status: 'active' })

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.click(screen.getByRole('button', { name: '停止する' }))

    expect(disableSpy).toHaveBeenCalledWith('device-1')
  })

  it('lets the admin enable a disabled device', async () => {
    const user = userEvent.setup()
    const enableSpy = vi
      .spyOn(devicesApi, 'enableDevice')
      .mockResolvedValue({ ...device, status: 'pending_pairing', disabled_at: null })
    renderModal({ status: 'disabled', disabled_at: '2026-07-20T00:00:00+09:00' })

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.click(screen.getByRole('button', { name: '有効化する' }))

    expect(enableSpy).toHaveBeenCalledWith('device-1')
  })

  it('shows a heartbeat staleness badge for an active device with an old last_seen_at', async () => {
    const user = userEvent.setup()
    renderModal({ status: 'active', last_seen_at: '2000-01-01T00:00:00+09:00' })

    await user.click(screen.getByRole('button', { name: '詳細' }))

    expect(screen.getByText('疎通途絶(30分以上応答なし)')).toBeInTheDocument()
  })

  it('shows granted scopes and lets the admin grant a new one', async () => {
    const user = userEvent.setup()
    const grantSpy = vi.spyOn(devicesApi, 'grantDeviceScope').mockResolvedValue({
      ...device,
      scopes: ['attendance:read_result', 'admin:mode'],
    })
    renderModal({ scopes: ['attendance:read_result'] })

    await user.click(screen.getByRole('button', { name: '詳細' }))

    expect(screen.getByText('勤怠実績の参照')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('スコープを付与する'), '管理者モード(入館証等の現地登録)')
    await user.click(screen.getByRole('button', { name: '付与する' }))

    expect(grantSpy).toHaveBeenCalledWith('device-1', 'admin:mode')
  })

  it('shows the same role checkboxes offered at registration and lets the admin change them', async () => {
    const user = userEvent.setup()
    const updateRolesSpy = vi
      .spyOn(devicesApi, 'updateDeviceRoles')
      .mockResolvedValue({ ...device })
    renderModal({ roles: ['attendance_reader'] })

    await user.click(screen.getByRole('button', { name: '詳細' }))

    expect(screen.getByRole('checkbox', { name: '打刻リーダー' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '認証端末' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: '入退室管理' })).not.toBeChecked()

    await user.click(screen.getByRole('checkbox', { name: '入退室管理' }))
    await user.click(screen.getByRole('button', { name: '役割を保存' }))

    expect(updateRolesSpy).toHaveBeenCalledWith('device-1', ['attendance_reader', 'access_control'])
  })

  it('submits updated settings', async () => {
    const user = userEvent.setup()
    const updateSpy = vi.spyOn(devicesApi, 'updateDeviceSettings').mockResolvedValue({
      ...device,
      location_name: '本社2階会議室',
    })
    renderModal()

    await user.click(screen.getByRole('button', { name: '詳細' }))
    const locationInput = screen.getByLabelText('設置場所')
    await user.clear(locationInput)
    await user.type(locationInput, '本社2階会議室')
    await user.click(screen.getByRole('button', { name: '設定を保存' }))

    expect(updateSpy).toHaveBeenCalledWith(
      'device-1',
      expect.objectContaining({ name: '本社1階受付', location_name: '本社2階会議室' }),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('本社1階受付の設定を保存しました'))
  })

  it('shows 422 field errors of the settings form under each field exactly once', async () => {
    const user = userEvent.setup()
    const spy = vi.spyOn(devicesApi, 'updateDeviceSettings').mockRejectedValueOnce(
      new ApiError(422, '名称エラー。', {
        name: ['名称エラー。'],
        location_name: ['設置場所エラー。'],
        default_work_location_type: ['勤務形態区分エラー。'],
        timezone: ['タイムゾーンエラー。'],
        site_id: ['未知のエラー。'],
      }),
    )
    renderModal()

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.click(screen.getByRole('button', { name: '設定を保存' }))

    for (const message of ['名称エラー。', '設置場所エラー。', '勤務形態区分エラー。', 'タイムゾーンエラー。', '未知のエラー。']) {
      expect(await screen.findByText(message)).toBeInTheDocument()
    }
    expect(screen.getByText('入力内容を確認してください。')).toBeInTheDocument()
    spy.mockRestore()
  })

  it('shows 422 field errors of the roles form under the role checkboxes exactly once', async () => {
    const user = userEvent.setup()
    const spy = vi.spyOn(devicesApi, 'updateDeviceRoles').mockRejectedValueOnce(
      new ApiError(422, '役割エラー。', {
        role_types: ['役割エラー。'],
        'role_types.0': ['役割の値エラー。'],
        other: ['未知のエラー。'],
      }),
    )
    renderModal({ roles: ['attendance_reader'] })

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.click(screen.getByRole('button', { name: '役割を保存' }))

    for (const message of ['役割エラー。', '役割の値エラー。', '未知のエラー。']) {
      expect(await screen.findByText(message)).toBeInTheDocument()
    }
    expect(screen.getByText('入力内容を確認してください。')).toBeInTheDocument()
    spy.mockRestore()
  })

  it('shows 422 field errors of the scope grant under the select exactly once', async () => {
    const user = userEvent.setup()
    const spy = vi.spyOn(devicesApi, 'grantDeviceScope').mockRejectedValueOnce(
      new ApiError(422, 'スコープエラー。', { scope: ['スコープエラー。'], other: ['未知のエラー。'] }),
    )
    renderModal({ scopes: ['attendance:read_result'] })

    await user.click(screen.getByRole('button', { name: '詳細' }))
    await user.selectOptions(screen.getByLabelText('スコープを付与する'), '管理者モード(入館証等の現地登録)')
    await user.click(screen.getByRole('button', { name: '付与する' }))

    for (const message of ['スコープエラー。', '未知のエラー。']) {
      expect(await screen.findByText(message)).toBeInTheDocument()
    }
    expect(screen.getByText('入力内容を確認してください。')).toBeInTheDocument()
    spy.mockRestore()
  })
})
