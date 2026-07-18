import { useEffect, useState } from 'react'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { DevicePairingQr } from '../DevicePairingQr/DevicePairingQr'
import { Checkbox } from '../ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../ui/dialog'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { useGrantDeviceScope, useIssueDevicePairingClaim, useUpdateDeviceSettings } from '../../hooks/useDevices'
import type { Device, DeviceRoleType, DeviceScopeType, DeviceStatus, DeviceType, WorkLocationType } from '../../api/types'
import { WORK_LOCATION_TYPE_OPTIONS } from '../../utils/statusLabels'

const DEVICE_TYPE_LABELS: Record<DeviceType, string> = {
  android: 'Android',
  ios: 'iOS',
  web_browser: 'Webブラウザ',
  windows: 'Windows',
  macos: 'macOS',
  linux: 'Linux',
  nfc_reader: 'NFCリーダー',
  fingerprint_reader: '指紋リーダー',
  face_recognition_device: '顔認証端末',
  access_control_device: '入退室管理端末',
  iot_device: 'IoT機器',
  external_system: '外部システム',
  other: 'その他',
}

const DEVICE_ROLE_LABELS: Record<DeviceRoleType, string> = {
  attendance_reader: '打刻リーダー',
  authentication_device: '認証端末',
  access_control: '入退室管理',
  personal_operation: '個人操作',
  admin_operation: '管理操作',
  external_event_source: '外部イベント連携',
}

const DEVICE_STATUS_TONE: Record<DeviceStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
  pending_pairing: 'warning',
  active: 'success',
  disabled: 'neutral',
  revoked: 'danger',
}

const DEVICE_STATUS_LABELS: Record<DeviceStatus, string> = {
  pending_pairing: 'ペアリング待ち',
  active: '稼働中',
  disabled: '停止中',
  revoked: '失効済み',
}

const DEVICE_SCOPE_LABELS: Record<DeviceScopeType, string> = {
  'attendance:clock': '打刻の記録',
  'attendance:read_current_state': '現在の勤怠状態の参照',
  'attendance:read_result': '勤怠実績の参照',
  'identity:resolve': '利用者IDの解決',
  'device:heartbeat': '疎通確認',
  'admin:mode': '管理者モード(入館証等の現地登録)',
}

const GRANTABLE_DEVICE_SCOPES = Object.keys(DEVICE_SCOPE_LABELS) as DeviceScopeType[]

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('ja-JP') : '-'
}

export interface DeviceDetailModalProps {
  device: Device
  /** 一覧から個別にトリガーボタンを描画したくない場合(制御されたopen/onOpenChange)向け。省略時は自前のトリガーボタンを表示する。 */
  open?: boolean
  onOpenChange?: (open: boolean) => void
}

/**
 * 端末詳細をモーダルで表示する(docs/23-usecases-devices.md「端末管理画面(UI)」)。
 * ペアリング用QRの表示・再発行と、設置場所などの設定変更をここに集約する。
 * 役割・スコープの付与、停止・失効は既存の専用操作(DeviceListPage側)のまま扱う。
 */
export function DeviceDetailModal({ device, open: controlledOpen, onOpenChange }: DeviceDetailModalProps) {
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false)
  const isControlled = controlledOpen !== undefined
  const isOpen = isControlled ? controlledOpen : uncontrolledOpen

  const issuePairingClaim = useIssueDevicePairingClaim()
  const updateSettings = useUpdateDeviceSettings()
  const grantScope = useGrantDeviceScope()

  const grantedScopes = device.scopes ?? []
  const ungrantedScopes = GRANTABLE_DEVICE_SCOPES.filter((scope) => !grantedScopes.includes(scope))
  const [scopeToGrant, setScopeToGrant] = useState<DeviceScopeType | ''>('')

  const [name, setName] = useState(device.name)
  const [locationName, setLocationName] = useState(device.location_name ?? '')
  const [defaultWorkLocationType, setDefaultWorkLocationType] = useState<WorkLocationType | ''>(
    device.default_work_location_type ?? '',
  )
  const [timezone, setTimezone] = useState(device.timezone ?? '')
  const [allowOffline, setAllowOffline] = useState(device.allow_offline)
  const [requireLocation, setRequireLocation] = useState(device.require_location)
  const [autoDetectPunchType, setAutoDetectPunchType] = useState(device.auto_detect_punch_type)

  const handleOpenChange = (next: boolean) => {
    if (!isControlled) setUncontrolledOpen(next)
    onOpenChange?.(next)
    if (next) {
      setName(device.name)
      setLocationName(device.location_name ?? '')
      setDefaultWorkLocationType(device.default_work_location_type ?? '')
      setTimezone(device.timezone ?? '')
      setAllowOffline(device.allow_offline)
      setRequireLocation(device.require_location)
      setAutoDetectPunchType(device.auto_detect_punch_type)
      updateSettings.reset()
      issuePairingClaim.reset()
      grantScope.reset()
      setScopeToGrant('')
    }
  }

  const handleGrantScope = () => {
    if (!scopeToGrant) return
    grantScope.mutate(
      { deviceId: device.id, scope: scopeToGrant },
      { onSuccess: () => setScopeToGrant('') },
    )
  }

  // device propが差し替わる(親のクエリが最新化する)たびにフォームへ反映する。
  useEffect(() => {
    if (!isOpen) return
    setName(device.name)
    setLocationName(device.location_name ?? '')
    setDefaultWorkLocationType(device.default_work_location_type ?? '')
    setTimezone(device.timezone ?? '')
    setAllowOffline(device.allow_offline)
    setRequireLocation(device.require_location)
    setAutoDetectPunchType(device.auto_detect_punch_type)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [device.id])

  const handleSave = () => {
    updateSettings.mutate({
      deviceId: device.id,
      input: {
        name,
        location_name: locationName || undefined,
        default_work_location_type: defaultWorkLocationType || undefined,
        timezone: timezone || undefined,
        allow_offline: allowOffline,
        require_location: requireLocation,
        auto_detect_punch_type: autoDetectPunchType,
      },
    })
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      {!isControlled && (
        <Button size="sm" variant="secondary" onClick={() => handleOpenChange(true)}>
          詳細
        </Button>
      )}
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{device.name}</DialogTitle>
        </DialogHeader>

        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={DEVICE_STATUS_TONE[device.status]}>{DEVICE_STATUS_LABELS[device.status]}</Badge>
          <span className="text-sm text-muted-foreground">{DEVICE_TYPE_LABELS[device.device_type]}</span>
          <span className="text-sm text-muted-foreground">
            {(device.roles ?? []).map((role) => DEVICE_ROLE_LABELS[role]).join(' / ') || '役割未設定'}
          </span>
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted-foreground">最終通信</dt>
          <dd className="text-foreground">{formatDateTime(device.last_seen_at)}</dd>
          <dt className="text-muted-foreground">ペアリング日時</dt>
          <dd className="text-foreground">{formatDateTime(device.paired_at)}</dd>
          <dt className="text-muted-foreground">アプリバージョン</dt>
          <dd className="text-foreground">{device.app_version ?? '-'}</dd>
        </dl>

        {device.status === 'pending_pairing' && (
          <div className="rounded-md border border-border p-3">
            <p className="mb-2 text-sm font-medium text-foreground">ペアリング用QR</p>
            {issuePairingClaim.error && <ErrorMessage error={issuePairingClaim.error} />}
            {issuePairingClaim.data ? (
              <div className="max-w-xs text-xs text-foreground">
                <DevicePairingQr claimToken={issuePairingClaim.data.claim_token} />
                <p className="mt-2 break-all font-mono">{issuePairingClaim.data.claim_token}</p>
                <p className="mt-1">
                  (一度のみ表示・5分で失効します。画面を撮影・共有しないでください)
                </p>
              </div>
            ) : (
              <Button
                size="sm"
                variant="secondary"
                isLoading={issuePairingClaim.isPending}
                onClick={() => issuePairingClaim.mutate(device.id)}
              >
                ペアリング用QRを発行
              </Button>
            )}
          </div>
        )}

        <div className="rounded-md border border-border p-3">
          <p className="mb-3 text-sm font-medium text-foreground">設定変更</p>
          {updateSettings.error && <ErrorMessage error={updateSettings.error} />}
          {updateSettings.isSuccess && <p className="mb-2 text-sm text-success">設定を保存しました。</p>}

          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="名称" htmlFor="device-detail-name" required>
              <Input id="device-detail-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>
            <FormField label="設置場所" htmlFor="device-detail-location">
              <Input
                id="device-detail-location"
                value={locationName}
                onChange={(e) => setLocationName(e.target.value)}
                placeholder="例: 本社1階受付"
              />
            </FormField>
            <FormField label="自動反映する勤務形態区分" htmlFor="device-detail-work-location-type">
              <NativeSelect
                id="device-detail-work-location-type"
                value={defaultWorkLocationType}
                onChange={(e) => setDefaultWorkLocationType(e.target.value as WorkLocationType | '')}
              >
                <option value="">未設定(自動反映しない)</option>
                {WORK_LOCATION_TYPE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
            <FormField label="タイムゾーン" htmlFor="device-detail-timezone">
              <Input
                id="device-detail-timezone"
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                placeholder="例: Asia/Tokyo"
              />
            </FormField>
          </div>

          <div className="mt-3 flex flex-wrap gap-4">
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox checked={allowOffline} onCheckedChange={(v) => setAllowOffline(v === true)} />
              オフライン打刻を許可する
            </label>
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox checked={requireLocation} onCheckedChange={(v) => setRequireLocation(v === true)} />
              位置情報を必須にする
            </label>
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox checked={autoDetectPunchType} onCheckedChange={(v) => setAutoDetectPunchType(v === true)} />
              打刻種別を自動判定する
            </label>
          </div>

          <Button className="mt-4" isLoading={updateSettings.isPending} disabled={!name} onClick={handleSave}>
            保存する
          </Button>
        </div>

        <div className="rounded-md border border-border p-3">
          <p className="mb-2 text-sm font-medium text-foreground">スコープ(APIアクセス権)</p>
          {grantScope.error && <ErrorMessage error={grantScope.error} />}

          <div className="mb-3 flex flex-wrap gap-1">
            {grantedScopes.length === 0 ? (
              <span className="text-sm text-muted-foreground">付与済みのスコープはありません。</span>
            ) : (
              grantedScopes.map((scope) => <Badge key={scope}>{DEVICE_SCOPE_LABELS[scope]}</Badge>)
            )}
          </div>

          {ungrantedScopes.length > 0 && (
            <div className="flex flex-wrap items-end gap-2">
              <FormField label="スコープを付与する" htmlFor="device-detail-scope-select">
                <NativeSelect
                  id="device-detail-scope-select"
                  value={scopeToGrant}
                  onChange={(e) => setScopeToGrant(e.target.value as DeviceScopeType | '')}
                >
                  <option value="">選択してください</option>
                  {ungrantedScopes.map((scope) => (
                    <option key={scope} value={scope}>
                      {DEVICE_SCOPE_LABELS[scope]}
                    </option>
                  ))}
                </NativeSelect>
              </FormField>
              <Button
                size="sm"
                variant="secondary"
                isLoading={grantScope.isPending}
                disabled={!scopeToGrant}
                onClick={handleGrantScope}
              >
                付与する
              </Button>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
