import { useState } from 'react'
import type { AuthenticationKeyStatus, AuthenticationKeyType } from '../../api/types'
import {
  useAuthenticationKeysForUser,
  useDisableAuthenticationKey,
  useIssueAuthenticationKey,
} from '../../hooks/useAuthenticationKeys'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { LoadingState } from '../LoadingState/LoadingState'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table'

const KEY_TYPE_LABELS: Record<AuthenticationKeyType, string> = {
  nfc_uid: 'ICカード(NFC UID)',
  employee_card_id: '社員証ID',
  qr_code: 'QRコード',
  barcode: 'バーコード',
  fingerprint_external_id: '指紋(外部認証端末ID)',
  face_recognition_external_id: '顔認証(外部認証端末ID)',
  fido_credential: 'FIDOクレデンシャル',
  bluetooth_device_id: 'Bluetooth端末ID',
  external_system_user_id: '外部システムユーザーID',
  custom: 'その他',
}

const KEY_STATUS_TONE: Record<AuthenticationKeyStatus, 'success' | 'warning' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  disabled: 'neutral',
}

const KEY_STATUS_LABELS: Record<AuthenticationKeyStatus, string> = {
  active: '有効',
  suspended: '一時停止',
  disabled: '無効化済み',
}

export interface AuthenticationKeysPanelProps {
  userId: number
}

/**
 * UC-K001〜UC-K003: ICカード・指紋等の認証キーを本人または管理者代理で登録・無効化する。
 * カードの生UID・指紋テンプレート自体はサーバーに保存せず、外部認証端末から読み取った
 * 値(raw_key_value)をサーバー側でハッシュ化して保持する(docs/24-usecases-authentication-keys.md)。
 */
export function AuthenticationKeysPanel({ userId }: AuthenticationKeysPanelProps) {
  const { data: keys, isLoading, error } = useAuthenticationKeysForUser(userId)
  const issueKey = useIssueAuthenticationKey()
  const disableKey = useDisableAuthenticationKey()

  const [isFormOpen, setIsFormOpen] = useState(false)
  const [keyType, setKeyType] = useState<AuthenticationKeyType>('nfc_uid')
  const [displayName, setDisplayName] = useState('')
  const [rawKeyValue, setRawKeyValue] = useState('')

  const resetForm = () => {
    setKeyType('nfc_uid')
    setDisplayName('')
    setRawKeyValue('')
  }

  const handleIssue = () => {
    issueKey.mutate(
      { user_id: userId, key_type: keyType, display_name: displayName, raw_key_value: rawKeyValue },
      {
        onSuccess: () => {
          resetForm()
          setIsFormOpen(false)
        },
      },
    )
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="認証キーの取得に失敗しました。" />

  const list = keys ?? []

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-foreground">認証キー(ICカード・指紋等)</h3>
        <Button size="sm" variant={isFormOpen ? 'secondary' : 'primary'} onClick={() => setIsFormOpen((v) => !v)}>
          {isFormOpen ? '閉じる' : '新規発行'}
        </Button>
      </div>

      {issueKey.error && <ErrorMessage error={issueKey.error} />}
      {disableKey.error && <ErrorMessage error={disableKey.error} />}

      {isFormOpen && (
        <div className="mb-4 rounded-md border border-border p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="種別" htmlFor="auth-key-form-type" required>
              <NativeSelect
                id="auth-key-form-type"
                value={keyType}
                onChange={(e) => setKeyType(e.target.value as AuthenticationKeyType)}
              >
                {Object.entries(KEY_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
            <FormField label="表示名" htmlFor="auth-key-form-name" required>
              <Input
                id="auth-key-form-name"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                placeholder="例: 本社ICカード"
              />
            </FormField>
          </div>
          <FormField label="読み取った値(カードUID・外部認証端末IDなど)" htmlFor="auth-key-form-raw-value" required>
            <Input
              id="auth-key-form-raw-value"
              value={rawKeyValue}
              onChange={(e) => setRawKeyValue(e.target.value)}
              placeholder="外部端末・カードリーダーで読み取った値を入力"
            />
          </FormField>
          <p className="mb-3 text-xs text-muted-foreground">
            この値はサーバーに生のまま保存されず、ハッシュ化されます。登録後は無効化のみ可能で、値の変更はできません。
          </p>
          <Button
            isLoading={issueKey.isPending}
            disabled={!displayName || !rawKeyValue}
            onClick={handleIssue}
          >
            発行する
          </Button>
        </div>
      )}

      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">登録済みの認証キーはまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>表示名</TableHead>
              <TableHead>種別</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>登録日</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((key) => (
              <TableRow key={key.id}>
                <TableCell className="font-medium text-foreground">{key.display_name}</TableCell>
                <TableCell className="text-muted-foreground">{KEY_TYPE_LABELS[key.key_type]}</TableCell>
                <TableCell>
                  <Badge tone={KEY_STATUS_TONE[key.status]}>{KEY_STATUS_LABELS[key.status]}</Badge>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {key.registered_at ? new Date(key.registered_at).toLocaleDateString('ja-JP') : '-'}
                </TableCell>
                <TableCell>
                  {key.status !== 'disabled' && (
                    <Button
                      size="sm"
                      variant="danger"
                      isLoading={disableKey.isPending}
                      onClick={() => disableKey.mutate({ id: key.id, userId })}
                    >
                      無効化する
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
