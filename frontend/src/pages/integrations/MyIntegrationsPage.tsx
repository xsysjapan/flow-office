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
import type { ApplicationIntegration, IntegrationClientType, IntegrationScopeType } from '../../api/types'
import { useMyIntegrations, useReissueIntegrationToken, useRegisterIntegration, useRevokeIntegration } from '../../hooks/useIntegrations'

const CLIENT_TYPE_LABELS: Record<IntegrationClientType, string> = {
  api_client: 'APIクライアント',
  mcp_client: 'MCPクライアント(Claude等)',
  ai_application: 'AIアプリケーション',
  external_application: '外部アプリケーション',
}

const SELECTABLE_SCOPES: Array<{ value: IntegrationScopeType; label: string }> = [
  { value: 'attendance:self:read', label: '自分の勤怠を閲覧する' },
  { value: 'attendance:self:clock', label: '自分の打刻を行う' },
  { value: 'attendance:self:draft', label: '自分の月次勤怠下書きを作成・編集する' },
  { value: 'attendance:self:update', label: '自分の日次勤怠を編集する' },
  { value: 'attendance:self:validate', label: '自分の月次勤怠下書きを検証する' },
  { value: 'attendance:self:submit', label: '自分の月次勤怠を提出する' },
  { value: 'leave:self:read', label: '自分の有給・特別休暇を閲覧する' },
  { value: 'leave:self:create', label: '自分の有給・特別休暇を申請する' },
  { value: 'schedule:self:read', label: '自分の勤務予定を閲覧する' },
  { value: 'report:self:import', label: '作業報告書を取り込む' },
]

const SCOPE_LABELS: Record<IntegrationScopeType, string> = {
  'profile:self:read': '自分のプロフィールを閲覧する',
  ...Object.fromEntries(SELECTABLE_SCOPES.map((s) => [s.value, s.label])),
} as Record<IntegrationScopeType, string>

/**
 * UC-I001〜UC-I003: 本人が自分専用のAPI・MCP連携(Claude等のAIアプリからの操作用)を
 * 登録・再発行・停止する。連携の登録・再発行・停止自体は連携トークンではなく本人の通常
 * ログインセッションで行う(docs/25-usecases-integrations-mcp.md)。
 */
export function MyIntegrationsPage() {
  const { data: integrations, isLoading, error } = useMyIntegrations()
  const registerIntegration = useRegisterIntegration()
  const reissueToken = useReissueIntegrationToken()

  const [isFormOpen, setIsFormOpen] = useState(false)
  const [clientType, setClientType] = useState<IntegrationClientType>('mcp_client')
  const [clientName, setClientName] = useState('')
  const [purpose, setPurpose] = useState('')
  const [scopes, setScopes] = useState<IntegrationScopeType[]>([])
  const [issuedToken, setIssuedToken] = useState<{ integrationId: number; token: string } | null>(null)

  const toggleScope = (scope: IntegrationScopeType) => {
    setScopes((prev) => (prev.includes(scope) ? prev.filter((s) => s !== scope) : [...prev, scope]))
  }

  const resetForm = () => {
    setClientType('mcp_client')
    setClientName('')
    setPurpose('')
    setScopes([])
  }

  const handleRegister = () => {
    registerIntegration.mutate(
      { client_type: clientType, client_name: clientName, purpose: purpose || undefined, scopes },
      {
        onSuccess: (result) => {
          setIssuedToken({ integrationId: result.integration.id, token: result.token })
          resetForm()
          setIsFormOpen(false)
        },
      },
    )
  }

  const handleReissue = (integration: ApplicationIntegration) => {
    reissueToken.mutate(integration.id, {
      onSuccess: (result) => {
        setIssuedToken({ integrationId: result.integration.id, token: result.token })
      },
    })
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="連携一覧の取得に失敗しました。" />

  const list = integrations ?? []

  return (
    <Card
      title="API・MCP連携"
      actions={
        <Button onClick={() => setIsFormOpen((v) => !v)} variant={isFormOpen ? 'secondary' : 'primary'}>
          {isFormOpen ? '閉じる' : '新規登録'}
        </Button>
      }
    >
      {registerIntegration.error && <ErrorMessage error={registerIntegration.error} />}
      {reissueToken.error && <ErrorMessage error={reissueToken.error} />}

      {issuedToken && (
        <div className="mb-4 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
          アクセストークン(一度のみ表示・必ずこの場でコピーしてください):
          <div className="mt-1 break-all font-mono font-semibold">{issuedToken.token}</div>
        </div>
      )}

      {isFormOpen && (
        <div className="mb-6 rounded-md border border-border p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="種別" htmlFor="integration-form-type" required>
              <NativeSelect
                id="integration-form-type"
                value={clientType}
                onChange={(e) => setClientType(e.target.value as IntegrationClientType)}
              >
                {Object.entries(CLIENT_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
            <FormField label="名称" htmlFor="integration-form-name" required>
              <Input
                id="integration-form-name"
                value={clientName}
                onChange={(e) => setClientName(e.target.value)}
                placeholder="例: Claude Desktop"
              />
            </FormField>
          </div>
          <FormField label="用途(任意)" htmlFor="integration-form-purpose">
            <Input
              id="integration-form-purpose"
              value={purpose}
              onChange={(e) => setPurpose(e.target.value)}
              placeholder="例: 月次勤怠の下書き作成をClaudeに依頼する"
            />
          </FormField>

          <fieldset className="mb-4">
            <legend className="mb-1.5 text-sm font-medium text-foreground">許可する操作</legend>
            <p className="mb-2 text-xs text-muted-foreground">
              自分のプロフィール参照は常に許可されます。他人の勤怠閲覧・承認・組織設定の変更はこの連携では行えません。
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
              {SELECTABLE_SCOPES.map((scope) => (
                <label key={scope.value} className="flex items-center gap-2 text-sm text-foreground">
                  <Checkbox checked={scopes.includes(scope.value)} onCheckedChange={() => toggleScope(scope.value)} />
                  {scope.label}
                </label>
              ))}
            </div>
          </fieldset>

          <Button
            isLoading={registerIntegration.isPending}
            disabled={!clientName || scopes.length === 0}
            onClick={handleRegister}
          >
            登録する
          </Button>
        </div>
      )}

      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">登録済みの連携はまだありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>名称</TableHead>
              <TableHead>種別</TableHead>
              <TableHead>許可された操作</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>最終利用</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((integration) => (
              <TableRow key={integration.id}>
                <TableCell className="font-medium text-foreground">{integration.client_name}</TableCell>
                <TableCell className="text-muted-foreground">{CLIENT_TYPE_LABELS[integration.client_type]}</TableCell>
                <TableCell className="text-muted-foreground">
                  {(integration.scopes ?? []).map((scope) => SCOPE_LABELS[scope] ?? scope).join(' / ') || '-'}
                </TableCell>
                <TableCell>
                  <Badge tone={integration.status === 'active' ? 'success' : 'neutral'}>
                    {integration.status === 'active' ? '有効' : '停止済み'}
                  </Badge>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {integration.last_used_at ? new Date(integration.last_used_at).toLocaleString('ja-JP') : '未使用'}
                </TableCell>
                <TableCell>
                  {integration.status === 'active' && (
                    <div className="flex flex-col items-start gap-2">
                      <Button
                        size="sm"
                        variant="secondary"
                        isLoading={reissueToken.isPending}
                        onClick={() => handleReissue(integration)}
                      >
                        トークン再発行
                      </Button>
                      <RevokeIntegrationDialog integration={integration} />
                    </div>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  )
}

function RevokeIntegrationDialog({ integration }: { integration: ApplicationIntegration }) {
  const revokeIntegration = useRevokeIntegration()

  return (
    <ConfirmActionDialog
      triggerLabel="停止する"
      title="連携を停止しますか?"
      description={`「${integration.client_name}」を停止します。発行済みのトークンは使用できなくなり、元に戻せません。`}
      confirmLabel="停止する"
      isPending={revokeIntegration.isPending}
      error={revokeIntegration.error}
      onOpenChange={(open) => {
        if (open) revokeIntegration.reset()
      }}
      onConfirm={() => revokeIntegration.mutate(integration.id)}
    />
  )
}
