import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useDevices, useDisableDevice, useIssueDevicePairingClaim, useRegisterDevice, useRevokeDevice } from '../../hooks/useDevices'
import type { Device, DeviceRoleType, DeviceStatus, DeviceType, WorkLocationType } from '../../api/types'
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

const REGISTERABLE_ROLE_TYPES: DeviceRoleType[] = ['attendance_reader', 'authentication_device', 'access_control']

/**
 * UC-D001〜UC-D005: 共有端末(打刻レコーダー等)の登録・ペアリングコード発行・停止/失効を行う。
 * 個人端末(owner_type=personal)は本人が/users/me/devicesから登録するため、ここでは
 * 組織共有端末(owner_type=organization_shared)のみを扱う。
 */
export function DeviceListPage() {
  const { data: devices, isLoading, error } = useDevices('organization_shared')
  const registerDevice = useRegisterDevice()
  const issuePairingClaim = useIssueDevicePairingClaim()
  const disableDevice = useDisableDevice()

  const [isFormOpen, setIsFormOpen] = useState(false)
  const [name, setName] = useState('')
  const [deviceType, setDeviceType] = useState<DeviceType>('android')
  const [roleTypes, setRoleTypes] = useState<DeviceRoleType[]>(['attendance_reader'])
  const [locationName, setLocationName] = useState('')
  const [defaultWorkLocationType, setDefaultWorkLocationType] = useState<WorkLocationType | ''>('')
  const [claimTokenByDevice, setClaimTokenByDevice] = useState<Record<number, string>>({})

  const toggleRoleType = (roleType: DeviceRoleType) => {
    setRoleTypes((prev) => (prev.includes(roleType) ? prev.filter((r) => r !== roleType) : [...prev, roleType]))
  }

  const resetForm = () => {
    setName('')
    setDeviceType('android')
    setRoleTypes(['attendance_reader'])
    setLocationName('')
    setDefaultWorkLocationType('')
  }

  const handleRegister = () => {
    registerDevice.mutate(
      {
        name,
        device_type: deviceType,
        role_types: roleTypes,
        location_name: locationName || undefined,
        default_work_location_type: defaultWorkLocationType || undefined,
      },
      {
        onSuccess: () => {
          resetForm()
          setIsFormOpen(false)
        },
      },
    )
  }

  const handleIssuePairingClaim = (device: Device) => {
    issuePairingClaim.mutate(device.id, {
      onSuccess: (result) => {
        setClaimTokenByDevice((prev) => ({ ...prev, [device.id]: result.claim_token }))
      },
    })
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="端末一覧の取得に失敗しました。" />

  const list = devices ?? []

  return (
    <Card
      title="端末管理"
      actions={
        <Button onClick={() => setIsFormOpen((v) => !v)} variant={isFormOpen ? 'secondary' : 'primary'}>
          {isFormOpen ? '閉じる' : '新規登録'}
        </Button>
      }
    >
      {registerDevice.error && <ErrorMessage error={registerDevice.error} />}
      {issuePairingClaim.error && <ErrorMessage error={issuePairingClaim.error} />}
      {disableDevice.error && <ErrorMessage error={disableDevice.error} />}

      {isFormOpen && (
        <div className="mb-6 rounded-md border border-border p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="名称" htmlFor="device-form-name" required>
              <Input id="device-form-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>
            <FormField label="端末種別" htmlFor="device-form-type" required>
              <NativeSelect
                id="device-form-type"
                value={deviceType}
                onChange={(e) => setDeviceType(e.target.value as DeviceType)}
              >
                {Object.entries(DEVICE_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
            <FormField label="設置場所" htmlFor="device-form-location">
              <Input
                id="device-form-location"
                value={locationName}
                onChange={(e) => setLocationName(e.target.value)}
                placeholder="例: 本社1階受付"
              />
            </FormField>
            <FormField label="自動反映する勤務形態区分" htmlFor="device-form-work-location-type">
              <NativeSelect
                id="device-form-work-location-type"
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
          </div>

          <fieldset className="mb-4">
            <legend className="mb-1.5 text-sm font-medium text-foreground">役割</legend>
            <div className="flex flex-wrap gap-4">
              {REGISTERABLE_ROLE_TYPES.map((roleType) => (
                <label key={roleType} className="flex items-center gap-2 text-sm text-foreground">
                  <Checkbox
                    checked={roleTypes.includes(roleType)}
                    onCheckedChange={() => toggleRoleType(roleType)}
                  />
                  {DEVICE_ROLE_LABELS[roleType]}
                </label>
              ))}
            </div>
          </fieldset>

          <Button
            isLoading={registerDevice.isPending}
            disabled={!name || roleTypes.length === 0}
            onClick={handleRegister}
          >
            登録する
          </Button>
        </div>
      )}

      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">登録済みの共有端末はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>名称</TableHead>
              <TableHead>種別</TableHead>
              <TableHead>設置場所</TableHead>
              <TableHead>役割</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>最終通信</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((device) => (
              <TableRow key={device.id}>
                <TableCell className="font-medium text-foreground">{device.name}</TableCell>
                <TableCell className="text-muted-foreground">{DEVICE_TYPE_LABELS[device.device_type]}</TableCell>
                <TableCell className="text-muted-foreground">{device.location_name ?? '-'}</TableCell>
                <TableCell className="text-muted-foreground">
                  {(device.roles ?? []).map((role) => DEVICE_ROLE_LABELS[role]).join(' / ') || '-'}
                </TableCell>
                <TableCell>
                  <Badge tone={DEVICE_STATUS_TONE[device.status]}>{DEVICE_STATUS_LABELS[device.status]}</Badge>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {device.last_seen_at ? new Date(device.last_seen_at).toLocaleString('ja-JP') : '未通信'}
                </TableCell>
                <TableCell>
                  <div className="flex flex-col items-start gap-2">
                    {device.status === 'pending_pairing' && (
                      <Button
                        size="sm"
                        variant="secondary"
                        isLoading={issuePairingClaim.isPending}
                        onClick={() => handleIssuePairingClaim(device)}
                      >
                        ペアリング用QRを発行
                      </Button>
                    )}
                    {claimTokenByDevice[device.id] && (
                      <div className="max-w-xs text-xs text-foreground">
                        <div className="flex items-center gap-2">
                          <span className="break-all font-mono">{claimTokenByDevice[device.id]}</span>
                          <CopyClaimTokenButton token={claimTokenByDevice[device.id]} />
                        </div>
                        <p className="mt-1">
                          (一度のみ表示・5分で失効します。端末アプリでQRコードを読み取るか、
                          カメラのない端末はこの文字列をコピーしてセットアップ画面に貼り付けて
                          ください。画面を撮影・共有しないでください)
                        </p>
                      </div>
                    )}
                    {device.status === 'active' && (
                      <Button
                        size="sm"
                        variant="secondary"
                        isLoading={disableDevice.isPending}
                        onClick={() => disableDevice.mutate(device.id)}
                      >
                        停止する
                      </Button>
                    )}
                    {device.status !== 'revoked' && <RevokeDeviceDialog device={device} />}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}

function CopyClaimTokenButton({ token }: { token: string }) {
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    await navigator.clipboard.writeText(token)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <Button size="sm" variant="secondary" onClick={handleCopy}>
      {copied ? 'コピーしました' : 'コピー'}
    </Button>
  )
}

function RevokeDeviceDialog({ device }: { device: Device }) {
  const [reason, setReason] = useState('')
  const revokeDevice = useRevokeDevice()

  return (
    <ConfirmActionDialog
      triggerLabel="失効させる"
      title="端末を失効させますか?"
      description={`「${device.name}」を失効させます。この端末のトークンは使用できなくなり、元に戻せません。`}
      confirmLabel="失効させる"
      isPending={revokeDevice.isPending}
      error={revokeDevice.error}
      onOpenChange={(open) => {
        if (open) {
          setReason('')
          revokeDevice.reset()
        }
      }}
      onConfirm={() => revokeDevice.mutate({ deviceId: device.id, reason: reason || undefined })}
    >
      <Input
        aria-label="失効理由"
        placeholder="失効理由(任意。例: 端末紛失)"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
    </ConfirmActionDialog>
  )
}
