import { useEffect, useState } from 'react'
import { useSearchParamsKeepingBackState } from '../../navigation/backState'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DeviceDetailModal } from '../../components/DeviceDetailModal/DeviceDetailModal'
import { Pagination } from '../../components/Pagination/Pagination'
import { Checkbox } from '../../components/ui/checkbox'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../../components/ui/dropdown-menu'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { ApiError } from '../../api/client'
import { useDeleteDevice, useDevice, useDevices, useRegisterDevice, useRevokeDevice } from '../../hooks/useDevices'
import type { Device, DeviceRoleType, DeviceStatus, DeviceType, WorkLocationType } from '../../api/types'
import { notify } from '../../lib/notify'
import { fieldErrors } from '../../utils/fieldErrors'
import { isValidPageParam } from '../../utils/searchParamValidators'
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

const REGISTER_ERROR_KEYS = ['name', 'device_type', 'location_name', 'default_work_location_type', 'role_types', 'role_types.*'] as const
const REVOKE_ERROR_KEYS = ['reason'] as const

const REGISTERABLE_ROLE_TYPES: DeviceRoleType[] = ['attendance_reader', 'authentication_device', 'access_control']

// 打刻リーダーは10分に1回heartbeatを送信する想定のため、最終通信からこれ以上経過した
// 稼働中(active)端末は「現在は動いていない」とみなす(status自体は変更しない)。
const HEARTBEAT_STALE_AFTER_MINUTES = 30

function isHeartbeatStale(device: Device): boolean {
  if (device.status !== 'active') return false
  if (!device.last_seen_at) return true
  return Date.now() - new Date(device.last_seen_at).getTime() > HEARTBEAT_STALE_AFTER_MINUTES * 60 * 1000
}

/**
 * UC-D001〜UC-D005: 共有端末(打刻レコーダー等)の一覧・登録・失効・削除を行う。
 * 個人端末(owner_type=personal)は本人が/users/me/devicesから登録するため、ここでは
 * 組織共有端末(owner_type=organization_shared)のみを扱う。一覧行から直接行える操作は
 * 詳細へ遷移(行クリックでも可)・失効・削除のみとし、ペアリングQR表示・停止・再ペアリングは
 * DeviceDetailModal(詳細画面)に集約する。
 */
export function DeviceListPage() {
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()
  const pageParam = searchParams.get('page')
  const isInvalidPage = pageParam !== null && !isValidPageParam(pageParam)
  const page = pageParam !== null && !isInvalidPage ? Number(pageParam) : 1
  /** 詳細パネルの対象端末はURLの`deviceId`に載せる(SKILL.md §2.10) */
  const selectedDeviceId = searchParams.get('deviceId')
  const showDeleted = searchParams.get('show_deleted') === '1'
  const { data: devices, isLoading, error } = useDevices({
    ownerType: 'organization_shared',
    page,
    withTrashed: showDeleted,
  })
  const registerDevice = useRegisterDevice()

  const [isFormOpen, setIsFormOpen] = useState(false)
  const [name, setName] = useState('')
  const [deviceType, setDeviceType] = useState<DeviceType>('android')
  const [roleTypes, setRoleTypes] = useState<DeviceRoleType[]>(['attendance_reader'])
  const [locationName, setLocationName] = useState('')
  const [defaultWorkLocationType, setDefaultWorkLocationType] = useState<WorkLocationType | ''>('')
  // 行内の「⋯」メニューから開く失効・削除の確認ダイアログの対象
  const [revokeTarget, setRevokeTarget] = useState<Device | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Device | null>(null)

  // 対象端末が現在のページの一覧に無くても開けるよう、一覧に無いときだけ個別に取得する
  const selectedInList = devices?.data.find((device) => device.id === selectedDeviceId)
  const { data: fetchedDevice, error: deviceError } = useDevice(
    selectedDeviceId !== null && !selectedInList ? selectedDeviceId : undefined,
  )
  const isDeviceNotFound = deviceError instanceof ApiError && deviceError.status === 404

  function setSelectedDeviceId(id: string | null) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (id === null) next.delete('deviceId')
        else next.set('deviceId', id)
        return next
      },
      { replace: true },
    )
  }

  // 不正なページは1ページ目として扱い、存在しない端末のIDと共にURLからキーを消す
  useEffect(() => {
    if (!isInvalidPage && !isDeviceNotFound) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (isInvalidPage) next.delete('page')
        if (isDeviceNotFound) next.delete('deviceId')
        return next
      },
      { replace: true },
    )
  }, [isInvalidPage, isDeviceNotFound, setSearchParams])

  const changeShowDeleted = (checked: boolean) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (checked) next.set('show_deleted', '1')
        else next.delete('show_deleted')
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }

  const clearConditions = () => changeShowDeleted(false)

  function changePage(nextPage: number) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (nextPage > 1) next.set('page', String(nextPage))
        else next.delete('page')
        return next
      },
      { replace: true },
    )
  }

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
    const registeredName = name
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
          notify.success(`${registeredName}を登録しました`)
          resetForm()
          setIsFormOpen(false)
        },
      },
    )
  }

  const header = (
    <PageHeader
      description="打刻レコーダー等の共有端末を登録し、詳細確認・失効・削除ができます。"
      actions={
        <Button onClick={() => setIsFormOpen((v) => !v)} variant={isFormOpen ? 'secondary' : 'primary'}>
          {isFormOpen ? '閉じる' : '新規作成'}
        </Button>
      }
    />
  )
  if (isLoading)
    return (
      <div className="space-y-6">
        <PageHeader description="打刻レコーダー等の共有端末を登録し、詳細確認・失効・削除ができます。" />
        <LoadingState />
      </div>
    )

  const list = devices?.data ?? []
  const selectedDevice = selectedInList ?? (selectedDeviceId !== null && fetchedDevice?.id === selectedDeviceId ? fetchedDevice : null)

  const { of: registerOf, ofAny: registerOfAny, rest: registerRest } = fieldErrors(
    registerDevice.error,
    REGISTER_ERROR_KEYS,
  )

  return (
    <div className="space-y-6">
    {header}
    <Card>
      {isFormOpen && (
        <div className="mb-6 rounded-md border border-border p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="名称" htmlFor="device-form-name" required error={registerOf('name')}>
              <Input id="device-form-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>
            <FormField label="端末種別" htmlFor="device-form-type" required error={registerOf('device_type')}>
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
            <FormField label="設置場所" htmlFor="device-form-location" error={registerOf('location_name')}>
              <Input
                id="device-form-location"
                value={locationName}
                onChange={(e) => setLocationName(e.target.value)}
                placeholder="例: 本社1階受付"
              />
            </FormField>
            <FormField label="自動反映する勤務形態区分" htmlFor="device-form-work-location-type" error={registerOf('default_work_location_type')}>
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
            {registerOfAny('role_types').map((message) => (
              <p key={message} className="mt-1.5 text-xs text-destructive">
                {message}
              </p>
            ))}
          </fieldset>

          <Button
            isLoading={registerDevice.isPending}
            disabled={!name || roleTypes.length === 0}
            onClick={handleRegister}
          >
            登録する
          </Button>
          {(!name || roleTypes.length === 0) && (
            <p className="mt-1.5 text-xs text-muted-foreground">
              {!name ? '名称を入力してください。' : '役割を1つ以上選択してください。'}
            </p>
          )}

          {registerRest != null && <ErrorMessage error={registerRest} />}
        </div>
      )}

      <ListFilterBar className="mb-4" isFiltered={showDeleted} onClear={clearConditions}>
        <label className="flex h-9 items-center gap-2 text-sm text-foreground">
          <Checkbox checked={showDeleted} onCheckedChange={(checked) => changeShowDeleted(checked === true)} />
          削除済みの端末も表示
        </label>
      </ListFilterBar>

      {error ? (
        <ErrorMessage error={error} fallback="端末一覧の取得に失敗しました。" />
      ) : list.length === 0 ? (
        <EmptyState
          title={showDeleted ? '削除済みの共有端末はありません。' : '共有端末はまだありません。'}
          description={
            showDeleted
              ? '「削除済みの端末も表示」をオフにすると稼働中の端末が表示されます。'
              : '「新規作成」から共有端末を作成できます。'
          }
          action={showDeleted || isFormOpen ? undefined : <Button onClick={() => setIsFormOpen(true)}>新規作成</Button>}
        />
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="whitespace-normal min-w-48">名称</TableHead>
                <TableHead>種別</TableHead>
                <TableHead className="whitespace-normal min-w-48">設置場所</TableHead>
                <TableHead>役割</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>最終通信</TableHead>
                <TableHead>操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.map((device) => {
                const isDeleted = device.deleted_at !== null
                return (
                  <ClickableTableRow
                    key={device.id}
                    onRowClick={() => setSelectedDeviceId(device.id)}
                    rowLabel={`${device.name}の詳細を開く`}
                    disabled={isDeleted}
                  >
                    <TableCell className="whitespace-normal min-w-48 font-medium text-foreground">{device.name}</TableCell>
                    <TableCell className="text-muted-foreground">{DEVICE_TYPE_LABELS[device.device_type]}</TableCell>
                    <TableCell className="whitespace-normal min-w-48 text-muted-foreground">{device.location_name ?? '-'}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {(device.roles ?? []).map((role) => DEVICE_ROLE_LABELS[role]).join(' / ') || '-'}
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        <Badge tone={DEVICE_STATUS_TONE[device.status]}>{DEVICE_STATUS_LABELS[device.status]}</Badge>
                        {isHeartbeatStale(device) && <Badge tone="warning">疎通途絶</Badge>}
                        {isDeleted && <Badge tone="danger">削除済み</Badge>}
                      </div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {device.last_seen_at ? new Date(device.last_seen_at).toLocaleString('ja-JP') : '未通信'}
                    </TableCell>
                    <TableCell>
                      {isDeleted ? (
                        <span className="text-xs text-muted-foreground">-</span>
                      ) : (
                        <div className="flex flex-wrap items-start gap-2" onClick={(e) => e.stopPropagation()}>
                          <DeviceRowMenu
                            device={device}
                            onDetail={() => setSelectedDeviceId(device.id)}
                            onRevoke={() => setRevokeTarget(device)}
                            onDelete={() => setDeleteTarget(device)}
                          />
                        </div>
                      )}
                    </TableCell>
                  </ClickableTableRow>
                )
              })}
            </TableBody>
          </Table>
          {devices && (
            <Pagination
              currentPage={devices.meta.current_page}
              lastPage={devices.meta.last_page}
              total={devices.meta.total}
              onPageChange={changePage}
            />
          )}
        </>
      )}

      <RevokeDeviceDialog device={revokeTarget} onClose={() => setRevokeTarget(null)} />
      <DeleteDeviceDialog device={deleteTarget} onClose={() => setDeleteTarget(null)} />

      {selectedDevice && (
        <DeviceDetailModal
          device={selectedDevice}
          open={selectedDeviceId !== null}
          onOpenChange={(open) => {
            if (!open) setSelectedDeviceId(null)
          }}
        />
      )}
    </Card>
    </div>
  )
}

/**
 * 行末の「⋯」メニュー。詳細・失効・削除をまとめる。失効・削除は項目を選ぶとメニューを閉じてから
 * 確認ダイアログを開く(ダイアログをメニュー内に残さない)。
 */
function DeviceRowMenu({
  device,
  onDetail,
  onRevoke,
  onDelete,
}: {
  device: Device
  onDetail: () => void
  onRevoke: () => void
  onDelete: () => void
}) {
  const [open, setOpen] = useState(false)
  const runAfterClose = (action: () => void) => (event: Event) => {
    event.preventDefault()
    setOpen(false)
    action()
  }
  const canRevoke = device.status !== 'revoked'
  const canDelete = device.status === 'disabled' || device.status === 'revoked'

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button size="sm" variant="secondary" aria-label={`${device.name}の操作`}>
          ⋯
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={runAfterClose(onDetail)}>詳細</DropdownMenuItem>
        {canRevoke && (
          <DropdownMenuItem variant="destructive" onSelect={runAfterClose(onRevoke)}>
            失効させる
          </DropdownMenuItem>
        )}
        {canDelete && (
          <DropdownMenuItem variant="destructive" onSelect={runAfterClose(onDelete)}>
            削除する
          </DropdownMenuItem>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

function RevokeDeviceDialog({ device, onClose }: { device: Device | null; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const revokeDevice = useRevokeDevice()

  const { of: revokeOf, rest: revokeRest } = fieldErrors(revokeDevice.error, REVOKE_ERROR_KEYS)

  const close = () => {
    setReason('')
    revokeDevice.reset()
    onClose()
  }

  return (
    <ConfirmActionDialog
      open={device !== null}
      onOpenChange={(open) => {
        if (!open) close()
      }}
      title="端末を失効させますか?"
      description={
        device ? `「${device.name}」を失効させます。この端末のトークンは使用できなくなり、元に戻せません。` : ''
      }
      confirmLabel="失効させる"
      isPending={revokeDevice.isPending}
      error={revokeRest}
      onConfirm={() => {
        if (!device) return
        revokeDevice.mutate(
          { deviceId: device.id, reason: reason || undefined },
          {
            onSuccess: () => {
              notify.success(`${device.name}を失効しました`)
              close()
            },
          },
        )
      }}
    >
      <Input
        aria-label="失効理由"
        placeholder="失効理由(任意。例: 端末紛失)"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      {revokeOf('reason') && <p className="mt-1.5 text-xs text-destructive">{revokeOf('reason')}</p>}
    </ConfirmActionDialog>
  )
}

function DeleteDeviceDialog({ device, onClose }: { device: Device | null; onClose: () => void }) {
  const deleteDevice = useDeleteDevice()

  const close = () => {
    deleteDevice.reset()
    onClose()
  }

  return (
    <ConfirmActionDialog
      open={device !== null}
      onOpenChange={(open) => {
        if (!open) close()
      }}
      title="端末を削除しますか?"
      description={
        device
          ? `「${device.name}」を一覧から削除します。監査記録として操作履歴は残りますが、この端末は一覧から見えなくなります(「削除済みの端末も表示」で再表示できます)。`
          : ''
      }
      confirmLabel="削除する"
      isPending={deleteDevice.isPending}
      error={deleteDevice.error}
      onConfirm={() => {
        if (!device) return
        deleteDevice.mutate(device.id, {
          onSuccess: () => {
            notify.success(`${device.name}を削除しました`)
            close()
          },
        })
      }}
    />
  )
}
