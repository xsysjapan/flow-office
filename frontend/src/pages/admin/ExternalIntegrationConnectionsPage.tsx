import { useEffect, useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { Textarea } from '../../components/ui/textarea'
import {
  useCreateExternalIntegrationConnection,
  useDeleteExternalIntegrationConnection,
  useExternalIntegrationConnectionOAuthRedirectUrl,
  useExternalIntegrationConnections,
  useUpdateExternalIntegrationConnection,
} from '../../hooks/useExternalIntegrationConnections'
import type {
  ExternalIntegrationAuthType,
  ExternalIntegrationConnection,
  ExternalIntegrationConnectionStatus,
  ExternalIntegrationProvider,
} from '../../api/types'

const PROVIDER_LABELS: Record<ExternalIntegrationProvider, string> = {
  freee: 'freee',
  moneyforward: 'マネーフォワード',
}

// docs/25-usecases-integrations-mcp.md: freeeはOAuth2、マネーフォワードはAPIキー認証が実態。
// 初期値として提示するだけで、画面上は変更可能にする。
const DEFAULT_AUTH_TYPE_BY_PROVIDER: Record<ExternalIntegrationProvider, ExternalIntegrationAuthType> = {
  freee: 'oauth2',
  moneyforward: 'api_key',
}

const AUTH_TYPE_LABELS: Record<ExternalIntegrationAuthType, string> = {
  oauth2: 'OAuth2',
  api_key: 'APIキー',
}

const STATUS_LABELS: Record<ExternalIntegrationConnectionStatus, string> = {
  unconfigured: '未設定',
  connected: '接続済み',
  error: 'エラー',
}

const STATUS_TONE: Record<ExternalIntegrationConnectionStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
  unconfigured: 'neutral',
  connected: 'success',
  error: 'danger',
}

interface ConnectionFormValue {
  provider: ExternalIntegrationProvider
  name: string
  authType: ExternalIntegrationAuthType
  clientId: string
  clientSecret: string
  apiKey: string
  externalOfficeId: string
  customSettingsText: string
}

const EMPTY_FORM: ConnectionFormValue = {
  provider: 'freee',
  name: '',
  authType: DEFAULT_AUTH_TYPE_BY_PROVIDER.freee,
  clientId: '',
  clientSecret: '',
  apiKey: '',
  externalOfficeId: '',
  customSettingsText: '',
}

function formFromConnection(connection: ExternalIntegrationConnection): ConnectionFormValue {
  return {
    provider: connection.provider,
    name: connection.name,
    authType: connection.auth_type,
    clientId: '',
    clientSecret: '',
    apiKey: '',
    externalOfficeId: connection.external_office_id ?? '',
    customSettingsText: connection.custom_settings ? JSON.stringify(connection.custom_settings, null, 2) : '',
  }
}

// custom_settingsのJSONテキストを検証し、業務ロジック(認証情報必須項目)のバリデーションを行う。
// 空文字や空欄はOKだが、不正なJSONやauth_typeに必須の項目欠落はエラーメッセージを返す。
function validateForm(value: ConnectionFormValue, isCreate: boolean): string | null {
  if (!value.name.trim()) return '名称を入力してください。'
  if (value.authType === 'oauth2') {
    const needsClientId = isCreate && !value.clientId
    const needsClientSecret = isCreate && !value.clientSecret
    if (needsClientId || needsClientSecret) {
      return 'OAuth2の場合、クライアントIDとクライアントシークレットを入力してください。'
    }
  }
  if (value.authType === 'api_key') {
    if (isCreate && !value.apiKey) {
      return 'APIキー認証の場合、APIキーを入力してください。'
    }
  }
  if (value.customSettingsText.trim()) {
    try {
      const parsed = JSON.parse(value.customSettingsText)
      if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
        return 'カスタム設定はJSONオブジェクトの形式で入力してください。'
      }
    } catch {
      return 'カスタム設定が正しいJSON形式ではありません。'
    }
  }
  return null
}

function ConnectionFormFields({
  idPrefix,
  value,
  onChange,
  isCreate,
}: {
  idPrefix: string
  value: ConnectionFormValue
  onChange: (value: ConnectionFormValue) => void
  isCreate: boolean
}) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {isCreate && (
        <FormField label="プロバイダ" htmlFor={`${idPrefix}-provider`} required>
          <NativeSelect
            id={`${idPrefix}-provider`}
            value={value.provider}
            onChange={(e) => {
              const provider = e.target.value as ExternalIntegrationProvider
              onChange({ ...value, provider, authType: DEFAULT_AUTH_TYPE_BY_PROVIDER[provider] })
            }}
          >
            {Object.entries(PROVIDER_LABELS).map(([v, label]) => (
              <option key={v} value={v}>
                {label}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      )}
      <FormField label="名称" htmlFor={`${idPrefix}-name`} required>
        <Input
          id={`${idPrefix}-name`}
          value={value.name}
          onChange={(e) => onChange({ ...value, name: e.target.value })}
          placeholder="例: freee本社経理"
        />
      </FormField>
      <FormField label="認証方式" htmlFor={`${idPrefix}-auth-type`} required>
        <NativeSelect
          id={`${idPrefix}-auth-type`}
          value={value.authType}
          onChange={(e) => onChange({ ...value, authType: e.target.value as ExternalIntegrationAuthType })}
        >
          {Object.entries(AUTH_TYPE_LABELS).map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </NativeSelect>
      </FormField>
      <FormField label="外部事業所ID" htmlFor={`${idPrefix}-office-id`}>
        <Input
          id={`${idPrefix}-office-id`}
          value={value.externalOfficeId}
          onChange={(e) => onChange({ ...value, externalOfficeId: e.target.value })}
          placeholder="任意"
        />
      </FormField>

      {value.authType === 'oauth2' && (
        <>
          <FormField label="クライアントID" htmlFor={`${idPrefix}-client-id`} required={isCreate}>
            <Input
              id={`${idPrefix}-client-id`}
              value={value.clientId}
              onChange={(e) => onChange({ ...value, clientId: e.target.value })}
              placeholder={isCreate ? undefined : '変更する場合のみ入力(空欄のままなら変更しない)'}
            />
          </FormField>
          <FormField label="クライアントシークレット" htmlFor={`${idPrefix}-client-secret`} required={isCreate}>
            <Input
              id={`${idPrefix}-client-secret`}
              type="password"
              value={value.clientSecret}
              onChange={(e) => onChange({ ...value, clientSecret: e.target.value })}
              placeholder={isCreate ? undefined : '変更する場合のみ入力(空欄のままなら変更しない)'}
            />
          </FormField>
        </>
      )}

      {value.authType === 'api_key' && (
        <FormField label="APIキー" htmlFor={`${idPrefix}-api-key`} required={isCreate}>
          <Input
            id={`${idPrefix}-api-key`}
            type="password"
            value={value.apiKey}
            onChange={(e) => onChange({ ...value, apiKey: e.target.value })}
            placeholder={isCreate ? undefined : '変更する場合のみ入力(空欄のままなら変更しない)'}
          />
        </FormField>
      )}

      <div className="sm:col-span-2">
        <FormField label="カスタム設定(JSON)" htmlFor={`${idPrefix}-custom-settings`}>
          <Textarea
            id={`${idPrefix}-custom-settings`}
            value={value.customSettingsText}
            onChange={(e) => onChange({ ...value, customSettingsText: e.target.value })}
            placeholder='任意。例: {"tax_category": "10"}'
            rows={3}
          />
        </FormField>
      </div>
    </div>
  )
}

/**
 * 外部連携(freee/マネーフォワード等)の接続設定を管理する。同一providerで複数件登録できる
 * (拠点・事業所ごとに接続を分けるケースを想定)。送信実行(external-publish)自体はこの画面の
 * 対象外で、ここでは認証情報の登録・更新・有効化/無効化・削除のみを扱う。
 */
export function ExternalIntegrationConnectionsPage() {
  const { data, isLoading, error, refetch } = useExternalIntegrationConnections()
  const createConnection = useCreateExternalIntegrationConnection()
  const updateConnection = useUpdateExternalIntegrationConnection()
  const deleteConnection = useDeleteExternalIntegrationConnection()
  const oauthRedirectUrl = useExternalIntegrationConnectionOAuthRedirectUrl()

  const [isFormOpen, setIsFormOpen] = useState(false)
  const [createValue, setCreateValue] = useState<ConnectionFormValue>(EMPTY_FORM)
  const [editingConnection, setEditingConnection] = useState<ExternalIntegrationConnection | null>(null)
  const [editValue, setEditValue] = useState<ConnectionFormValue>(EMPTY_FORM)
  const [oauthNotice, setOauthNotice] = useState<{ tone: 'success' | 'error'; message: string } | null>(null)

  // freeeの認可コードフロー完了後、バックエンドのcallbackがこの画面へ
  // ?oauth=success/error のクエリパラメータ付きでリダイレクトしてくる。
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const oauthResult = params.get('oauth')
    if (!oauthResult) return

    if (oauthResult === 'success') {
      setOauthNotice({ tone: 'success', message: 'freeeとの連携が完了しました。' })
      void refetch()
    } else if (oauthResult === 'error') {
      const message = params.get('message')
      setOauthNotice({ tone: 'error', message: `freeeとの連携に失敗しました。${message ? `(${message})` : ''}` })
    }

    params.delete('oauth')
    params.delete('provider')
    params.delete('message')
    const query = params.toString()
    window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''))
    // 初回マウント時のみ処理する(依存配列は空)。
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const startFreeeOAuth = (connection: ExternalIntegrationConnection) => {
    oauthRedirectUrl.mutate(connection.id, {
      onSuccess: ({ url }) => {
        window.location.href = url
      },
    })
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="外部連携設定の取得に失敗しました。" />

  const list = data?.data ?? []
  const createValidationError = isFormOpen ? validateForm(createValue, true) : null
  const editValidationError = editingConnection ? validateForm(editValue, false) : null

  const openCreateForm = () => {
    setCreateValue(EMPTY_FORM)
    createConnection.reset()
    setIsFormOpen((v) => !v)
  }

  const handleCreate = () => {
    if (validateForm(createValue, true)) return
    createConnection.mutate(
      {
        provider: createValue.provider,
        name: createValue.name,
        auth_type: createValue.authType,
        ...(createValue.authType === 'oauth2'
          ? { client_id: createValue.clientId, client_secret: createValue.clientSecret }
          : {}),
        ...(createValue.authType === 'api_key' ? { api_key: createValue.apiKey } : {}),
        external_office_id: createValue.externalOfficeId || undefined,
        ...(createValue.customSettingsText.trim()
          ? { custom_settings: JSON.parse(createValue.customSettingsText) }
          : {}),
      },
      {
        onSuccess: () => {
          setCreateValue(EMPTY_FORM)
          setIsFormOpen(false)
        },
      },
    )
  }

  const openEditDialog = (connection: ExternalIntegrationConnection) => {
    setEditValue(formFromConnection(connection))
    updateConnection.reset()
    setEditingConnection(connection)
  }

  const handleUpdate = () => {
    if (!editingConnection || validateForm(editValue, false)) return
    updateConnection.mutate(
      {
        id: editingConnection.id,
        input: {
          name: editValue.name,
          auth_type: editValue.authType,
          // 機密値・任意項目は入力があった場合のみ送る(空欄のままなら既存値を変更しない)。
          ...(editValue.clientId ? { client_id: editValue.clientId } : {}),
          ...(editValue.clientSecret ? { client_secret: editValue.clientSecret } : {}),
          ...(editValue.apiKey ? { api_key: editValue.apiKey } : {}),
          external_office_id: editValue.externalOfficeId || undefined,
          ...(editValue.customSettingsText.trim()
            ? { custom_settings: JSON.parse(editValue.customSettingsText) }
            : {}),
        },
      },
      {
        onSuccess: () => setEditingConnection(null),
      },
    )
  }

  const handleToggleEnabled = (connection: ExternalIntegrationConnection) => {
    updateConnection.mutate({ id: connection.id, input: { enabled: !connection.enabled } })
  }

  return (
    <Card
      title="外部連携設定"
      actions={
        <Button onClick={openCreateForm} variant={isFormOpen ? 'secondary' : 'primary'}>
          {isFormOpen ? '閉じる' : '新規登録'}
        </Button>
      }
    >
      <p className="mb-4 text-sm text-muted-foreground">
        freee・マネーフォワード等の外部会計サービスとの接続情報を管理する。同一のプロバイダで
        複数件登録できる(拠点・事業所ごとの接続を想定)。ここでの登録は認証情報の保存のみで、
        送信実行は別画面で行う。
      </p>

      <div className="mb-6 rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
        <p className="mb-2 font-medium text-foreground">連携(送信)のタイミングについて</p>
        <ul className="list-disc space-y-1 pl-5">
          <li>
            <span className="font-medium text-foreground">freee</span>:
            勤怠(月次確定後)・経費(承認後)いずれも、管理者が送信操作を行ったタイミングで送信される
            (自動での定期送信は行わない)。
          </li>
          <li>
            <span className="font-medium text-foreground">マネーフォワード</span>:
            経費(承認後)のみAPI送信に対応する。勤怠はマネーフォワード側に外部データを受け付けるAPIが
            無いため、CSV出力のみで連携する(API送信は非対応)。
          </li>
        </ul>
        <p className="mt-2">
          ※現時点では送信操作自体を行う専用画面(送信ボタン)は未実装のため、実際の送信はAPI経由の
          運用になる。
        </p>
      </div>

      {oauthNotice && (
        <div
          className={`mb-4 rounded-md border p-3 text-sm ${
            oauthNotice.tone === 'success'
              ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
              : 'border-destructive/40 bg-destructive/10 text-destructive'
          }`}
        >
          {oauthNotice.message}
        </div>
      )}

      {createConnection.error && <ErrorMessage error={createConnection.error} />}
      {deleteConnection.error && <ErrorMessage error={deleteConnection.error} />}
      {oauthRedirectUrl.error && <ErrorMessage error={oauthRedirectUrl.error} />}

      {isFormOpen && (
        <div className="mb-6 rounded-md border border-border p-4">
          <ConnectionFormFields idPrefix="connection-create" value={createValue} onChange={setCreateValue} isCreate />
          <Button isLoading={createConnection.isPending} disabled={Boolean(createValidationError)} onClick={handleCreate}>
            作成
          </Button>
          {createValidationError && <p className="mt-1.5 text-xs text-muted-foreground">{createValidationError}</p>}
        </div>
      )}

      {list.length === 0 ? (
        <EmptyState
          title="登録済みの外部連携はまだありません。"
          description="右上の「新規登録」からfreee・マネーフォワード等の接続を登録できます。"
        />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>プロバイダ</TableHead>
              <TableHead>名称</TableHead>
              <TableHead>認証方式</TableHead>
              <TableHead>認証情報</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>有効</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((connection) => (
              <TableRow key={connection.id}>
                <TableCell className="text-foreground">{PROVIDER_LABELS[connection.provider]}</TableCell>
                <TableCell className="font-medium text-foreground">{connection.name}</TableCell>
                <TableCell className="text-muted-foreground">{AUTH_TYPE_LABELS[connection.auth_type]}</TableCell>
                <TableCell className="text-muted-foreground">
                  {connection.auth_type === 'oauth2'
                    ? (connection.client_id_masked ?? (connection.has_client_id ? '設定済み' : '未設定'))
                    : (connection.api_key_masked ?? (connection.has_api_key ? '設定済み' : '未設定'))}
                </TableCell>
                <TableCell>
                  <Badge tone={STATUS_TONE[connection.status]}>{STATUS_LABELS[connection.status]}</Badge>
                </TableCell>
                <TableCell>
                  <Badge tone={connection.enabled ? 'success' : 'neutral'}>
                    {connection.enabled ? '有効' : '無効'}
                  </Badge>
                </TableCell>
                <TableCell>
                  <div className="flex flex-wrap items-start gap-2">
                    {connection.provider === 'freee' && connection.auth_type === 'oauth2' && (
                      <Button
                        size="sm"
                        variant="secondary"
                        isLoading={oauthRedirectUrl.isPending}
                        disabled={!connection.has_client_id || !connection.has_client_secret}
                        title={
                          !connection.has_client_id || !connection.has_client_secret
                            ? 'クライアントID・クライアントシークレットを先に登録してください。'
                            : undefined
                        }
                        onClick={() => startFreeeOAuth(connection)}
                      >
                        freeeと連携する
                      </Button>
                    )}
                    <Button size="sm" variant="secondary" onClick={() => openEditDialog(connection)}>
                      編集
                    </Button>
                    <Button
                      size="sm"
                      variant="secondary"
                      isLoading={updateConnection.isPending}
                      onClick={() => handleToggleEnabled(connection)}
                    >
                      {connection.enabled ? '無効にする' : '有効にする'}
                    </Button>
                    <ConfirmActionDialog
                      triggerLabel="削除する"
                      title="外部連携を削除しますか?"
                      description={`「${connection.name}」(${PROVIDER_LABELS[connection.provider]})を削除します。保存された認証情報は失われ、この操作は元に戻せません。`}
                      confirmLabel="削除する"
                      isPending={deleteConnection.isPending}
                      error={deleteConnection.error}
                      onOpenChange={(open) => {
                        if (open) deleteConnection.reset()
                      }}
                      onConfirm={() => deleteConnection.mutate(connection.id)}
                    />
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog
        open={editingConnection !== null}
        onOpenChange={(open) => {
          if (!open) setEditingConnection(null)
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>外部連携を編集</DialogTitle>
          </DialogHeader>
          {editingConnection && (
            <>
              {updateConnection.error && <ErrorMessage error={updateConnection.error} />}
              <p className="mb-2 text-xs text-muted-foreground">
                クライアントシークレット・APIキーは空欄のままにすると現在の値を変更しません。
              </p>
              <ConnectionFormFields
                idPrefix="connection-edit"
                value={editValue}
                onChange={setEditValue}
                isCreate={false}
              />
              {editValidationError && <p className="text-xs text-muted-foreground">{editValidationError}</p>}
            </>
          )}
          <DialogFooter>
            <Button variant="secondary" onClick={() => setEditingConnection(null)}>
              キャンセル
            </Button>
            <Button
              isLoading={updateConnection.isPending}
              disabled={Boolean(editValidationError)}
              onClick={handleUpdate}
            >
              保存
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
